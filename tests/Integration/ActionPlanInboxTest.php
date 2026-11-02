<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\ActionPlan\Inbox;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Services\ActionPlan\InboxService;
use App\PennyPincher\Services\Analysis\RecommendationService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use App\PennyPincher\Support\Navigation;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Plan działania 2.0 (pp2_action_plan): wspólna kolejka i prześledzony cykl rekomendacja → zgoda → wykonanie →
 * wynik → monitoring – na atrapie konta Google Ads (bez sieci).
 */
class ActionPlanInboxTest extends ChangeCenterTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::ACTION_PLAN);
		parent::tearDown();
	}

	private function flag(bool $on): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::ACTION_PLAN);
		if ($on) {
			add_filter('pennypincher/feature/' . Feature::ACTION_PLAN, '__return_true');
		}
	}

	private function get(string $url): \Symfony\Component\HttpFoundation\Response
	{
		wp_set_current_user($this->owner);
		$_SERVER['REQUEST_METHOD'] = 'GET';

		return Application::getInstance()->make(Kernel::class)->handle(Request::create($url, 'GET'));
	}

	private function body(string $url): string
	{
		$response = $this->get($url);
		$this->assertSame(200, $response->getStatusCode(), $url);

		return (string) $response->getContent();
	}

	private function recommendation(string $type, string $title, ?int $campaignId): int
	{
		$now = $this->db->now();

		return $this->db->insert('recommendations', [
			'project_id' => $this->project->id, 'fingerprint' => md5($type . $title . microtime()), 'source' => 'audit', 'type' => $type, 'title' => $title,
			'description' => $title, 'rationale' => '', 'evidence' => '[]', 'action' => $title, 'campaign_id' => $campaignId, 'ad_group_id' => null,
			'priority' => 'medium', 'priority_score' => 50, 'confidence' => 'high', 'status' => 'new', 'is_obsolete' => 0,
			'first_seen_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now,
		]);
	}

	private function tabOfRecommendation(int $id): ?string
	{
		$inbox = $this->make(InboxService::class);

		foreach (array_keys(Inbox::TABS) as $tab) {
			foreach ($inbox->items($this->project, $tab) as $item) {
				if ($item['kind'] === 'recommendation' && $item['id'] === $id) {
					return $tab;
				}
			}
		}

		return null;
	}

	public function testFullCycleRecommendationApprovalExecutionResultMonitoring(): void
	{
		$this->account('inbox-cycle', true);
		$this->flag(true);
		$rec = $this->recommendation('audit_cs001', 'Partnerzy wyszukiwania bez konwersji', $this->campaignId());
		$inbox = $this->make(InboxService::class);

		// 1. Nowa rekomendacja → „Do oceny”; decyzja użytkownika → „Zaakceptowane”.
		$this->assertSame('review', $this->tabOfRecommendation($rec));
		$this->make(RecommendationService::class)->updateStatus($this->project, $rec, 'accepted', $this->owner, 'Wdrażamy');
		$this->assertSame('accepted', $this->tabOfRecommendation($rec));

		// 2. Szkic zmiany na koncie → „Do oceny”; zatwierdzenie → „Zaakceptowane”; nic nie wysłano.
		$setId = $this->proposals()->fromRecommendation($this->project, $this->owner, $rec);
		$this->assertSame('review', Inbox::tabOf('change', $this->set($setId)['status']));
		$this->approve($setId);
		$this->assertSame('accepted', Inbox::tabOf('change', $this->set($setId)['status']));
		$this->assertSame(0, $this->fake->executedMutations(), 'Zatwierdzenie nie zapisuje w Google Ads.');

		// 3. Świadome polecenie wdrożenia z potwierdzeniem → worker → wynik API i weryfikacja → „Wykonane”.
		$set = $this->queueAndWork($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertSame(1, $this->fake->executedMutations());
		$this->assertSame('done', Inbox::tabOf('change', $set['status']));

		$recRow = array_values(array_filter($inbox->items($this->project, 'accepted'), fn ($i) => $i['kind'] === 'recommendation' && $i['id'] === $rec))[0];
		$this->assertSame($setId, $recRow['change']['id'], 'Rekomendacja pokazuje powiązaną zmianę na koncie.');
		$this->assertSame('accepted', $this->tabOfRecommendation($rec), '„Wykonana” ustawia wyłącznie użytkownik – wdrożenie nie zmienia statusu.');

		// 4. Ścieżka: decyzja (osoba, czas), zgoda, polecenie wdrożenia, wynik API, weryfikacja, monitoring efektów.
		$trace = $inbox->trace($this->project, $rec);
		$this->assertSame(['deployed', 'status'], array_slice(array_column($trace['events'], 'event'), 0, 2), 'Najnowsze najpierw: wdrożenie po decyzji.');
		$this->assertCount(1, $trace['changes']);
		$change = $trace['changes'][0];
		$this->assertSame($this->owner, (int) $change['set']['approved_by']);
		$this->assertSame($this->owner, (int) $change['set']['deploy_requested_by']);
		$this->assertNotNull($change['set']['verified_at']);
		$this->assertTrue(in_array('execute', array_column($change['attempts'], 'kind'), true), 'Zapisany wynik API.');
		$this->assertFalse($change['effects']['available'], 'Efekty dopiero po kilku pełnych dniach.');
		$this->assertContains('Za wcześnie', $change['effects']['message']);

		$page = $this->body(AppUrl::project($this->project->id, 'action-plan/trace/' . $rec));
		foreach (['recommendation', 'actions', 'changes', 'monitoring'] as $step) {
			$this->assertContains('data-step="' . $step . '"', $page);
		}
		$this->assertContains('data-change="' . $setId . '"', $page);
		$this->assertContains('Za wcześnie', $page);

		// 5. Użytkownik potwierdza wykonanie → „Wykonane”.
		$this->make(RecommendationService::class)->updateStatus($this->project, $rec, 'done', $this->owner, null);
		$this->assertSame('done', $this->tabOfRecommendation($rec));
		$this->assertContains('data-inbox="change"', $this->body(AppUrl::project($this->project->id, 'action-plan/inbox', ['tab' => 'done'])));
	}

	public function testTabsCountsFlagAndIsolation(): void
	{
		$this->account('inbox-tabs', true);
		$a = $this->recommendation('x', 'A', null);
		$b = $this->recommendation('y', 'B', null);
		$this->make(RecommendationService::class)->updateStatus($this->project, $b, 'rejected', $this->owner, null);
		$c = $this->recommendation('z', 'C (nieaktualna)', null);
		$this->db->update('recommendations', ['is_obsolete' => 1], ['id' => $c]);

		// Bez flagi: adres kolejki wraca do Action Plan; nawigacja bez zmian.
		$this->flag(false);
		$this->assertTrue($this->get(AppUrl::project($this->project->id, 'action-plan/inbox'))->isRedirect(AppUrl::project($this->project->id, 'action-plan')));
		$this->assertSame(Navigation::GROUPS['action-plan'], Navigation::groups()['action-plan']);

		$this->flag(true);
		$this->assertSame('action-inbox', array_key_first(Navigation::groups()['action-plan']['items']));
		$counts = $this->make(InboxService::class)->counts($this->project);

		foreach (array_keys(Inbox::TABS) as $tab) {
			$items = $this->make(InboxService::class)->items($this->project, $tab);
			$this->assertSame($counts[$tab], count($items), "Licznik zakładki {$tab} = liczba pozycji.");
			$body = $this->body(AppUrl::project($this->project->id, 'action-plan/inbox', ['tab' => $tab]));
			$this->assertSame(count($items), substr_count($body, 'data-inbox='), $tab);
		}

		$ids = fn (string $tab) => array_column(array_filter($this->make(InboxService::class)->items($this->project, $tab), fn ($i) => $i['kind'] === 'recommendation'), 'id');
		$this->assertTrue(in_array($a, $ids('review'), true));
		$this->assertFalse(in_array($c, $ids('review'), true), 'Nieaktualna nie trafia do „Do oceny”.');
		$this->assertTrue(in_array($b, $ids('rejected'), true));
		$this->assertFalse(in_array($b, $ids('review'), true));
		$this->assertSame('review', Inbox::tab('nieznana'));
		$this->assertSame('in_progress', Inbox::tabOf('action', 'blocked'), '„Zablokowane” zostaje widoczne w „W toku”.');

		// Izolacja: cudza rekomendacja i cudzy projekt = 404.
		$other = $this->user('inbox-other');
		$foreign = $this->organizationWithProject($other, 'Obcy');
		$this->assertSame(404, $this->get(AppUrl::project($foreign['project']->id, 'action-plan/inbox'))->getStatusCode());
		$this->assertSame(404, $this->get(AppUrl::project($this->project->id, 'action-plan/trace/999999'))->getStatusCode());
	}
}
