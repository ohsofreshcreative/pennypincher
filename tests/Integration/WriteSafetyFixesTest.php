<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\WriteBlockedException;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Bezpieczniki zapisu (poprawki z audytu, przed etapem „Plan działania”) – wyłącznie na atrapie konta Google Ads:
 * blokada emisji dla zmian zwiększających wydatki aktywnych kampanii, wyjście ze stanów bez wyjścia, konflikt
 * rozstrzygany ponownym odczytem (cała grupa atomowa trafia do wdrożenia).
 */
class WriteSafetyFixesTest extends ChangeCenterTestCase
{
	/** Wymuszenie kolejki z pominięciem kontroli w interfejsie – sprawdza kontrolę workera przed wysłaniem. */
	private function forceWork(int $setId): array
	{
		$this->sets()->transition($setId, [ChangeStatus::READY], ChangeStatus::DEPLOYING, ['deploy_key' => wp_generate_uuid4(), 'deploy_requested_by' => $this->owner, 'deploy_requested_at' => $this->db->now()]);
		$this->execution()->work(30);

		return $this->set($setId);
	}

	public function testSpendIncreasingChangesAreBlockedOnActiveCampaign(): void
	{
		$this->account('sf-spend', true);
		$campaign = $this->fake->name('campaign', '111');
		$group = $this->fake->name('ad_group', '222');
		$this->fake->set('campaign', $campaign, 'network_settings.target_content_network', false);
		$this->fake->set('ad_group', $group, 'cpc_bid_micros', 2_000_000);

		$sets = [
			'display' => $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['target_content_network' => true]),
			'bidding' => $this->proposals()->campaignBidding($this->project, $this->owner, $this->campaignId(), 'TARGET_SPEND', null, null),
			'cpc' => $this->proposals()->adGroupUpdate($this->project, $this->owner, $this->adGroupId(), ['cpc_bid_micros' => 9_000_000]),
			'negative' => $this->proposals()->campaignCriterionRemove($this->project, $this->owner, $this->campaignId(), $this->fake->name('campaign_criterion', '111~501')),
		];

		foreach ($sets as $key => $setId) {
			$this->approve($setId);
			$this->assertSame(ChangeStatus::READY, $this->set($setId)['status'], $key);
			$set = $this->forceWork($setId);
			$this->assertSame(ChangeStatus::READY, $set['status'], "{$key}: worker zatrzymuje przed wysłaniem.");
			$this->assertContains('Blokada emisji', (string) $set['last_error'], $key);
		}

		// Kampania znana z odczytu jako aktywna: interfejs blokuje zlecenie od razu.
		$this->assertThrows(WriteBlockedException::class, fn () => $this->center()->requestDeploy($this->project, $sets['display'], $this->owner, wp_generate_uuid4(), $this->confirmation($sets['display'])), 'trybie bezpieczeństwa');
		$this->assertSame(0, $this->fake->executedMutations(), 'Żadna zmiana zwiększająca wydatki nie trafiła do konta.');
		$this->assertFalse($this->fake->value('campaign', $campaign, 'network_settings.target_content_network'));

		// Obniżenie stawki aktywnej grupy nie jest blokowane.
		$lower = $this->proposals()->adGroupUpdate($this->project, $this->owner, $this->adGroupId(), ['cpc_bid_micros' => 1_000_000]);
		$this->assertSame(ChangeStatus::APPLIED, $this->deploy($lower)['status'], (string) $this->set($lower)['last_error']);

		// Po wstrzymaniu kampanii podwyżka stawki przechodzi (brak emisji).
		$this->pauseTestCampaign();
		$raise = $this->proposals()->adGroupUpdate($this->project, $this->owner, $this->adGroupId(), ['cpc_bid_micros' => 3_000_000]);
		$this->assertSame(ChangeStatus::APPLIED, $this->deploy($raise)['status'], (string) $this->set($raise)['last_error']);
		$this->assertSame(2, $this->fake->executedMutations());
	}

	public function testConflictIsResolvedByReReadAndWholeAtomicGroupDeploys(): void
	{
		$this->account('sf-conflict', true);
		$this->pauseTestCampaign();
		$setId = $this->proposals()->keywordReplaceMatch($this->project, $this->owner, $this->keywordId(), 'EXACT');
		$this->approve($setId);
		$this->fake->set('ad_group_criterion', $this->fake->name('ad_group_criterion', '222~333'), 'status', 'PAUSED');

		$set = $this->queueAndWork($setId);
		$this->assertSame(ChangeStatus::REVIEW, $set['status']);
		$this->assertTrue(in_array(ChangeStatus::OP_CONFLICT, array_column($this->ops($setId), 'status'), true));
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->approve($this->project, $setId, $this->owner, true, true), 'konflikt');

		// Ponowny odczyt: konflikt → oczekuje; po zatwierdzeniu wdrażane są OBIE operacje grupy (nowe słowo i usunięcie starego).
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$this->assertSame([ChangeStatus::OP_PENDING], array_values(array_unique(array_column($this->ops($setId), 'status'))));
		$this->center()->approve($this->project, $setId, $this->owner, true, true);
		$set = $this->queueAndWork($setId);

		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertSame('REMOVED', $this->fake->value('ad_group_criterion', $this->fake->name('ad_group_criterion', '222~333'), 'status'), 'Stare słowo usunięte.');
		$exact = array_filter($this->fake->all('ad_group_criterion'), fn ($k) => ($k['ad_group_criterion.keyword.text'] ?? '') === 'sklep meble' && ($k['ad_group_criterion.keyword.match_type'] ?? '') === 'EXACT');
		$this->assertCount(1, $exact, 'Nowe słowo dodane raz.');
	}

	public function testStatesWithoutExitCanBeLeft(): void
	{
		$this->account('sf-exit', true);

		// 1. Worker przerwany przed wysłaniem: „Wymaga uzgodnienia” bez operacji o nieznanym wyniku → uzgodnienie → Gotowy.
		$a = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | A']);
		$this->approve($a);
		$this->center()->requestDeploy($this->project, $a, $this->owner, wp_generate_uuid4(), $this->confirmation($a));
		$this->assertTrue($this->sets()->claim($a, 'worker-dead'));
		$this->sets()->update($a, ['deploy_started_at' => gmdate('Y-m-d H:i:s', time() - 40 * MINUTE_IN_SECONDS)]);
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::RECONCILE, $this->set($a)['status']);
		$this->assertSame([ChangeStatus::OP_PENDING], array_column($this->ops($a), 'status'));
		$this->execution()->reconcile($this->project, $a, $this->owner);
		$this->assertSame(ChangeStatus::READY, $this->set($a)['status'], 'Nic nie wysłano – zestaw czeka na świadome wdrożenie.');
		$this->assertSame(0, $this->fake->executedMutations());

		// 2. Zawieszona „Walidacja” – można anulować (walidacja jest bez zapisu).
		$b = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | B']);
		$this->sets()->transition($b, [ChangeStatus::DRAFT], ChangeStatus::VALIDATING);
		$this->center()->cancel($this->project, $b, $this->owner);
		$this->assertSame(ChangeStatus::CANCELLED, $this->set($b)['status']);

		// 3. Kolejka nieprzejęta przez workera – można anulować; przejęta – nie.
		$c = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | C']);
		$this->approve($c);
		$this->center()->requestDeploy($this->project, $c, $this->owner, wp_generate_uuid4(), $this->confirmation($c));
		$this->center()->cancel($this->project, $c, $this->owner);
		$this->assertSame(ChangeStatus::CANCELLED, $this->set($c)['status']);
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::CANCELLED, $this->set($c)['status']);

		$d = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | D']);
		$this->approve($d);
		$this->center()->requestDeploy($this->project, $d, $this->owner, wp_generate_uuid4(), $this->confirmation($d));
		$this->assertTrue($this->sets()->claim($d, 'worker-busy'));
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->cancel($this->project, $d, $this->owner), 'nie można już anulować');
		$this->assertSame(0, $this->fake->executedMutations());
	}
}
