<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Planner\PlanStatus;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\CampaignPlanRepository;
use App\PennyPincher\Services\Changes\ChangeEffectsService;
use App\PennyPincher\Services\Changes\ConversionManagementService;
use App\PennyPincher\Services\Changes\PlanDeploymentService;
use App\PennyPincher\Services\Planner\CampaignPlanService;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Google Ads Management, Conversion Management, przygotowanie zmian z modułów i wdrożenie planu kampanii –
 * wyłącznie na atrapie konta (bez sieci, bez prawdziwego Google Ads).
 */
class GoogleAdsManagementTest extends ChangeCenterTestCase
{
	// --- Słowa kluczowe, wykluczenia, budżet ---------------------------------------------------------

	public function testKeywordChecksDuplicatesAndNegativeConflicts(): void
	{
		$this->account('kw');
		$setId = $this->proposals()->keywordsAdd($this->project, $this->owner, $this->adGroupId(), ['sklep meble', 'meble darmowe dostawa', 'sofa narożna'], 'PHRASE');
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$local = $this->center()->validateLocally($this->project, $this->set($setId), $ops = $this->ops($setId));
		$byText = [];

		foreach ($ops as $op) {
			$byText[$op['payload']['text']] = ['errors' => implode(' ', $local['errors'][$op['id']] ?? []), 'warnings' => implode(' ', array_merge($op['warnings'], $local['warnings'][$op['id']] ?? []))];
		}

		$this->assertFalse($local['ok']);
		$this->assertContains('istnieje', $byText['sklep meble']['errors'], 'Duplikat istniejącego słowa.');
		$this->assertContains('darmowe', $byText['meble darmowe dostawa']['errors'] . $byText['meble darmowe dostawa']['warnings'], 'Konflikt z wykluczeniem kampanii.');
		$this->assertSame('', $byText['sofa narożna']['errors']);

		// Za długie słowo – walidacja lokalna (maks. 80 znaków / 10 słów).
		$long = $this->proposals()->keywordsAdd($this->project, $this->owner, $this->adGroupId(), ['a b c d e f g h i j k'], 'BROAD');
		$check = $this->center()->validateLocally($this->project, $this->set($long), $this->ops($long));
		$this->assertFalse($check['ok']);
	}

	public function testNegativeThatBlocksActiveKeywordIsFlagged(): void
	{
		$this->account('neg');
		$setId = $this->proposals()->adGroupNegativesAdd($this->project, $this->owner, $this->adGroupId(), ['meble'], 'PHRASE');
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$op = $this->ops($setId)[0];
		$local = $this->center()->validateLocally($this->project, $this->set($setId), [$op]);
		$messages = implode(' ', array_merge($local['errors'][$op['id']] ?? [], $local['warnings'][$op['id']] ?? [], $op['warnings']));

		$this->assertContains('sklep meble', $messages, 'Wykluczenie zablokuje aktywne słowo – musi to być widoczne przed zatwierdzeniem.');
	}

	public function testSharedBudgetRequiresAcknowledgementAndShowsAffectedCampaigns(): void
	{
		$this->account('shared', true);
		$this->fake->set('campaign_budget', $this->budgetName, 'explicitly_shared', true);
		$this->fake->campaign('112', 'Szukaj | Sofy', $this->budgetName);
		$setId = $this->proposals()->campaignBudget($this->project, $this->owner, $this->campaignId(), 60_000_000, false);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$op = $this->ops($setId)[0];

		$this->assertCount(2, $op['before_state']['campaigns']);
		$local = $this->center()->validateLocally($this->project, $this->set($setId), [$op]);
		$this->assertFalse($local['ok']);
		$this->assertContains('współdziel', implode(' ', $local['errors'][$op['id']]));

		$this->center()->updateOperation($this->project, $setId, (int) $op['id'], ['amount_micros' => 60_000_000, 'shared_ack' => true], $this->owner);
		$this->assertTrue($this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId))['ok']);

		$set = $this->deploy($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertSame(60_000_000, (int) $this->fake->value('campaign_budget', $this->budgetName, 'amount_micros'));
	}

	public function testBudgetMustBeWholeCentsAndPositive(): void
	{
		$this->account('cents');
		$setId = $this->proposals()->campaignBudget($this->project, $this->owner, $this->campaignId(), 70_005_000, false);
		$this->center()->refreshState($this->project, $setId, $this->owner);

		$this->assertFalse($this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId))['ok'], 'Kwota musi być wielokrotnością 0,01 (10 000 mikro).');
	}

	public function testImportedCampaignWithoutGoogleIdCannotBeChanged(): void
	{
		$this->account('import');
		$this->db->update('campaigns', ['external_id' => null], ['id' => $this->campaignId()]);
		$id = (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$this->project->id]);

		$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->campaignUpdate($this->project, $this->owner, $id, ['status' => 'PAUSED']), 'importu');
		$this->assertSame([], $this->sets()->paginate($this->project->id, [], 1)->items, 'Nie zostaje pusty szkic.');
	}

	public function testRsaNewVersionCreatesNewAdAndPausesOldOneAtomically(): void
	{
		$this->account('rsa', true);
		$old = $this->fake->name('ad_group_ad', '222~9001');
		$this->fake->set('ad_group_ad', $old, 'status', 'ENABLED');
		$this->fake->set('ad_group_ad', $old, 'ad_group', $this->fake->name('ad_group', '222'));
		$this->fake->set('ad_group_ad', $old, 'ad.id', '9001');

		$values = [
			'headlines' => ['Meble na wymiar', 'Sofy i narożniki', 'Darmowy projekt'], 'descriptions' => ['Zaprojektujemy meble do Twojego wnętrza.', 'Umów bezpłatną konsultację.'],
			'final_url' => 'https://example.test/meble', 'path1' => 'meble', 'status' => 'ENABLED',
		];
		$bad = $this->proposals()->adCreate($this->project, $this->owner, $this->adGroupId(), ['headlines' => ['Za mało'], 'descriptions' => ['x'], 'final_url' => 'ftp://zly'] + $values);
		$this->assertFalse($this->center()->validateLocally($this->project, $this->set($bad), $this->ops($bad))['ok'], 'RSA: za mało nagłówków i opisów, zły adres.');

		$setId = $this->proposals()->adCreate($this->project, $this->owner, $this->adGroupId(), $values, null, $old);
		$ops = $this->ops($setId);
		$this->assertSame(['ad_group_ad.create', 'ad_group_ad.update'], array_column($ops, 'type'));
		$this->assertSame($ops[0]['atomic_group'], $ops[1]['atomic_group']);

		$set = $this->deploy($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertSame('PAUSED', $this->fake->value('ad_group_ad', $old, 'status'));
		$new = array_values(array_filter($this->fake->all('ad_group_ad'), fn ($ad) => $ad !== $this->fake->get('ad_group_ad', $old)))[0];
		$this->assertSame('ENABLED', $new['ad_group_ad.status']);
		$this->assertSame(['https://example.test/meble'], $new['ad_group_ad.ad.final_urls']);
	}

	// --- Conversion Management -------------------------------------------------------------------------

	public function testOhSoFreshMicroConversionAsPrimaryIsDetectedAndFixedOnlyAfterConfirmation(): void
	{
		$this->account('ohsofresh', true);
		$this->db->update('conversion_actions', ['name' => '30 sekund – Bezpłatna konsultacja'], ['id' => $this->conversionId('901')]);
		$micro = $this->fake->name('conversion_action', '901');
		$this->fake->set('conversion_action', $micro, 'name', '30 sekund – Bezpłatna konsultacja');
		$this->fake->set('conversion_action', $micro, 'category', 'ENGAGEMENT');
		$this->fake->customerGoal('ENGAGEMENT', 'WEBSITE', true);
		$this->fake->customerGoal('SUBMIT_LEAD_FORM', 'WEBSITE', true);

		$service = $this->make(ConversionManagementService::class);
		$service->readFromGoogle($this->project);
		$overview = $service->overview($this->project);
		$issue = array_values(array_filter($overview['issues'], fn ($i) => $i['kind'] === 'micro_primary' && $i['action_id'] === $this->conversionId('901')))[0] ?? null;

		$this->assertNotNull($issue, 'Health Check wykrywa mikrokonwersję „30 sekund” jako główną.');
		$this->assertContains('30 sekund', $issue['title']);
		$this->assertTrue($issue['operations'] !== []);
		$this->assertSame('ENABLED', $this->fake->value('conversion_action', $micro, 'status'));

		// Penny Pincher nie wdraża sam – przygotowuje szkic, który wymaga potwierdzenia skutków dla Smart Bidding.
		$setId = $this->proposals()->fromHealthIssue($this->project, $this->owner, $issue);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$op = $this->ops($setId)[0];
		$this->assertSame(false, $op['payload']['primary_for_goal']);
		$this->assertSame('high', $op['risk']);
		$this->assertFalse($this->center()->validateLocally($this->project, $this->set($setId), [$op])['ok'], 'Bez potwierdzenia skutków zmiany celu.');
		$this->assertTrue($this->fake->value('conversion_action', $micro, 'primary_for_goal'));

		$this->center()->updateOperation($this->project, $setId, (int) $op['id'], ['primary_for_goal' => false, 'confirm_goal_change' => true], $this->owner);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$this->center()->submit($this->project, $setId, $this->owner);
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->approve($this->project, $setId, $this->owner, true, false), 'Smart Bidding');
		$this->center()->approve($this->project, $setId, $this->owner, true, true);
		$this->queueAndWork($setId);

		$this->assertSame(ChangeStatus::APPLIED, $this->set($setId)['status'], (string) $this->set($setId)['last_error']);
		$this->assertFalse($this->fake->value('conversion_action', $micro, 'primary_for_goal'));
		$this->assertSame('ENABLED', $this->fake->value('conversion_action', $micro, 'status'), 'Działanie nadal mierzy (dodatkowe), nie zostało usunięte.');
	}

	public function testSystemConversionIsReadOnlyAndClassificationNeverTouchesGoogleAds(): void
	{
		$this->account('sysconv');
		$this->fake->set('conversion_action', $this->fake->name('conversion_action', '902'), 'owner_customer', null);
		$this->fake->set('conversion_action', $this->fake->name('conversion_action', '902'), 'type', 'GOOGLE_HOSTED');
		$service = $this->make(ConversionManagementService::class);
		$service->readFromGoogle($this->project);
		$rows = array_column($service->overview($this->project)['actions'], null, 'external_id');

		$this->assertSame([], $rows['902']['editable'], 'Działanie systemowe – tylko odczyt.');
		$this->assertTrue($rows['901']['editable'] !== []);

		$setId = $this->proposals()->conversionUpdate($this->project, $this->owner, $this->conversionId('902'), ['counting_type' => 'ONE_PER_CLICK']);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$local = $this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId));
		$this->assertFalse($local['ok']);
		$this->assertContains('tylko do odczytu', implode(' ', array_merge(...array_values($local['errors']))));

		// Klasyfikacja Penny Pincher (moduł Konwersje) nie tworzy żadnych zmian Google Ads.
		$before = count($this->fake->mutations);
		$this->make(\App\PennyPincher\Repositories\ConversionActionRepository::class);
		$this->db->update('conversion_actions', ['business_class' => 'micro', 'classified_by' => $this->owner], ['id' => $this->conversionId('901')]);
		$this->assertSame($before, count($this->fake->mutations));
	}

	public function testNewConversionActionIsWebpageOnlyAndWarnsAboutTag(): void
	{
		$this->account('newconv', true);
		$setId = $this->proposals()->conversionCreate($this->project, $this->owner, ['name' => 'Wysłanie formularza', 'category' => 'SUBMIT_LEAD_FORM', 'counting_type' => 'ONE_PER_CLICK', 'click_window' => '30']);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$local = $this->center()->validateLocally($this->project, $this->set($setId), $ops = $this->ops($setId));

		$this->assertTrue($local['ok']);
		$this->assertContains('tag', implode(' ', array_merge($ops[0]['warnings'], $local['warnings'][$ops[0]['id']] ?? [])));
		$set = $this->deploy($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$created = array_values(array_filter($this->fake->all('conversion_action'), fn ($c) => $c['conversion_action.name'] === 'Wysłanie formularza'))[0];
		$this->assertSame('WEBPAGE', $created['conversion_action.type']);
		$this->assertFalse($created['conversion_action.primary_for_goal'], 'Nowa konwersja domyślnie dodatkowa.');

		$duplicate = $this->proposals()->conversionCreate($this->project, $this->owner, ['name' => 'Formularz', 'category' => 'SUBMIT_LEAD_FORM']);
		$this->center()->refreshState($this->project, $duplicate, $this->owner);
		$this->assertFalse($this->center()->validateLocally($this->project, $this->set($duplicate), $this->ops($duplicate))['ok'], 'Nazwa już istnieje na koncie.');
	}

	// --- Z modułów analizy --------------------------------------------------------------------------------

	public function testRecommendationDeploymentIsLinkedButNeverMarksItEffective(): void
	{
		$this->account('rec', true);
		$recId = $this->recommendation('audit_cs001', 'Wyłącz partnerów wyszukiwania', $this->campaignId());
		$setId = $this->proposals()->fromRecommendation($this->project, $this->owner, $recId);

		$this->assertSame($setId, $this->proposals()->fromRecommendation($this->project, $this->owner, $recId), 'Ponowne kliknięcie nie tworzy drugiego szkicu.');
		$set = $this->deploy($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertFalse($this->fake->value('campaign', $this->fake->name('campaign', '111'), 'network_settings.target_search_network'));

		$rec = $this->make(\App\PennyPincher\Repositories\RecommendationRepository::class)->find($this->project->id, $recId);
		$this->assertSame('new', $rec['status'], 'Wdrożenie nie oznacza rekomendacji jako wykonanej ani skutecznej.');
		$events = array_column($this->make(\App\PennyPincher\Repositories\RecommendationRepository::class)->events($this->project->id, $recId), 'event');
		$this->assertTrue(in_array('deployed', $events, true), 'Historia rekomendacji zawiera powiązane wdrożenie.');
		$this->assertSame($setId, (int) $this->sets()->latestForSources($this->project->id, 'recommendation', [$recId])[$recId]['id']);
	}

	public function testUnsupportedRecommendationIsNeverFakedAsOperation(): void
	{
		$this->account('recno');
		$recId = $this->recommendation('audit_pf005', 'Popraw stronę docelową', $this->campaignId());

		$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->fromRecommendation($this->project, $this->owner, $recId), 'poza Google Ads API');
		$this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('change_sets') . ' WHERE project_id = ?', [$this->project->id]));
	}

	public function testBudgetLeakSearchTermBecomesExactNegativeDraft(): void
	{
		$this->account('leak');
		$leakId = $this->leak('BL101', 'meble za darmo', $this->campaignId());
		$setId = $this->proposals()->fromLeak($this->project, $this->owner, $leakId);
		$op = $this->ops($setId)[0];

		$this->assertSame('campaign_criterion.create', $op['type']);
		$this->assertSame('meble za darmo', $op['payload']['text']);
		$this->assertSame('EXACT', $op['payload']['match_type']);
		$this->assertSame($setId, $this->proposals()->fromLeak($this->project, $this->owner, $leakId));

		$other = $this->leak('BL401', 'pomiar', null);
		$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->fromLeak($this->project, $this->owner, $other), 'poza Google Ads API');
	}

	// --- Campaign Planner → Google Ads -------------------------------------------------------------------

	public function testApprovedPlanDeploysInDependencyOrderPausedAndOnlyOnce(): void
	{
		$this->account('plan', true);
		$planId = $this->plan();

		$this->assertThrows(ValidationFailed::class, fn () => $this->make(PlanDeploymentService::class)->prepare($this->project, $planId, $this->owner, ['eu_political' => 'no']), 'zatwierdzonego');
		$this->db->update('campaign_plans', ['status' => PlanStatus::APPROVED], ['id' => $planId]);

		$setId = $this->make(PlanDeploymentService::class)->prepare($this->project, $planId, $this->owner, ['eu_political' => 'no', 'geo_ids' => [2616]]);
		$this->assertSame($setId, $this->make(PlanDeploymentService::class)->prepare($this->project, $planId, $this->owner, ['eu_political' => 'no']), 'Odświeżenie / ponowne kliknięcie nie tworzy drugiego wdrożenia.');

		$types = array_column($this->ops($setId), 'type');
		$order = ['campaign_budget.create', 'campaign.create', 'ad_group.create', 'ad_group_criterion.create', 'ad_group_ad.create'];
		$positions = array_map(fn ($type) => array_search($type, $types, true), $order);
		$this->assertTrue(! in_array(false, $positions, true), 'Brakuje typów operacji: ' . implode(', ', $types));
		$sorted = $positions;
		sort($sorted);
		$this->assertSame($sorted, $positions, 'Kolejność: budżet → kampania → grupa → słowa → reklama.');

		$set = $this->deploy($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$campaign = array_values(array_filter($this->fake->all('campaign'), fn ($c) => $c['campaign.name'] === 'Plan | Sofy'))[0];
		$this->assertSame('PAUSED', $campaign['campaign.status']);
		$this->assertSame(1, count($this->make(PlanDeploymentService::class)->links($this->project, $planId)), 'Powiązanie kampanii planu z zasobem Google Ads.');

		// Po wdrożeniu – ponowne przygotowanie nie tworzy kampanii drugi raz.
		$campaignsBefore = count($this->fake->all('campaign'));

		try {
			$again = $this->make(PlanDeploymentService::class)->prepare($this->project, $planId, $this->owner, ['eu_political' => 'no']);
			$this->assertSame([], array_values(array_filter($this->ops($again), fn ($op) => $op['type'] === 'campaign.create')));
		} catch (ValidationFailed $e) {
			$this->assertContains('utworzon', implode(' ', $e->errors) . $e->getMessage());
		}

		$this->execution()->work(30);
		$this->assertSame($campaignsBefore, count($this->fake->all('campaign')));
	}

	// --- Efekty -------------------------------------------------------------------------------------------

	public function testEffectsNeedEnoughTimeAndData(): void
	{
		$this->account('effects', true);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['target_search_network' => false]);
		$this->deploy($setId);
		$effects = $this->make(ChangeEffectsService::class);

		$now = $effects->forSet($this->project, $this->set($setId));
		$this->assertFalse($now['available']);
		$this->assertContains('Za wcześnie', $now['message']);

		// Wdrożenie 20 dni temu – dane z synchronizacji atrapy: 10 kliknięć dziennie.
		$this->sets()->update($setId, ['deploy_finished_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS)]);
		$later = $effects->forSet($this->project, $this->set($setId));
		$this->assertTrue($later['available'], $later['message']);
		$this->assertTrue(in_array('Szukaj | Marka', $later['campaigns'], true));
		$this->assertTrue($later['notes'] !== [], 'Zastrzeżenia interpretacji są zawsze pokazywane.');
	}

	// --- Pomocnicze ----------------------------------------------------------------------------------------

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

	private function leak(string $rule, string $subject, ?int $campaignId): int
	{
		$runId = $this->db->insert('analysis_runs', ['project_id' => $this->project->id, 'type' => 'budget_leaks', 'status' => 'completed', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'created_at' => $this->db->now()]);

		return $this->db->insert('budget_leaks', [
			'analysis_run_id' => $runId, 'project_id' => $this->project->id, 'rule_id' => $rule, 'category' => 'search_terms', 'fingerprint' => md5($rule . $subject),
			'title' => 'Wyciek ' . $rule, 'entity_type' => 'search_term', 'entity_id' => null, 'campaign_id' => $campaignId, 'ad_group_id' => null, 'subject' => $subject,
			'cost_micros' => 50_000_000, 'clicks' => 20, 'conversions' => 0, 'valuable_conversions' => 0, 'confidence' => 'high', 'priority' => 'high', 'priority_score' => 80,
			'reason' => 'Koszt bez konwersji', 'action' => 'Dodaj wykluczenie', 'evidence' => '{}', 'status' => 'new', 'created_at' => $this->db->now(),
		]);
	}

	private function plan(): int
	{
		$planner = $this->make(CampaignPlanService::class);
		$planId = $planner->create($this->project, ['name' => 'Plan wdrożenia', 'language' => 'pl', 'monthly_budget' => '1500'], $this->owner);
		$campaignId = $planner->addCampaign($this->project, $planId, 'Plan | Sofy');
		$this->make(\App\PennyPincher\Services\Planner\BudgetPlannerService::class)->setCampaignBudget($this->project, $planId, $campaignId, '1216');
		$groupId = $planner->addGroup($this->project, $planId, $campaignId, 'Sofy narożne');
		$planner->addKeywords($this->project, $planId, $groupId, ['sofa narożna', 'narożnik do salonu'], 'phrase');
		$planner->saveAd($this->project, $planId, $groupId, [
			'headlines' => ['Sofy narożne', 'Narożniki na wymiar', 'Darmowa dostawa'], 'descriptions' => ['Wybierz sofę dopasowaną do salonu.', 'Umów bezpłatną konsultację.'],
			'final_url' => 'https://example.test/sofy',
		]);

		return $planId;
	}
}
