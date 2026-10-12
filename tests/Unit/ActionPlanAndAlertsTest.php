<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\ActionPlan\ActionPlanBuilder;
use App\PennyPincher\Domain\Monitoring\AlertDetector;
use Tests\TestCase;

/**
 * Action Plan (grupowanie, zależności, priorytety) i wykrywanie anomalii monitoringu (progi przeciw fałszywym alarmom).
 */
class ActionPlanAndAlertsTest extends TestCase
{
	private const M = 1_000_000;

	// --- Action Plan --------------------------------------------------------------------------

	public function testActionsGroupRecommendationsAndMeasurementComesFirst(): void
	{
		$actions = ActionPlanBuilder::build([
			$this->rec(1, 'audit_ct001', 80, 'high'),
			$this->rec(2, 'leak_bl401', 70, 'medium'),
			$this->rec(3, 'audit_pf004', 75, 'high', 7),
			$this->rec(4, 'leak_bl301', 65, 'medium', 7),
			$this->rec(5, 'keyword_opportunities', 60, 'low'),
			$this->rec(6, 'negatives', 55, 'high', null, 'Wyklucz zapytania: darmowe (12 haseł)'),
			$this->rec(7, 'audit_bp001', 50, 'medium'),
		], [7 => 'Szukaj | Strony'], 5000 * self::M);

		$byCategory = [];

		foreach ($actions as $fp => $action) {
			$byCategory[$action['category']][] = $fp;
		}

		$this->assertCount(1, $byCategory['measurement'], 'CT001 i BL401 to jedno działanie „Popraw pomiar”.');
		$measurement = $actions[$byCategory['measurement'][0]];
		$this->assertSame([1, 2], array_values(array_intersect([1, 2], $measurement['recommendation_ids'])), 'Działanie zachowuje powiązania z rekomendacjami.');

		$review = $actions[$byCategory['campaign_review'][0]];
		$this->assertSame([3, 4], $review['recommendation_ids']);
		$this->assertSame(7, $review['campaign_id']);
		$this->assertContains('Szukaj | Strony', $review['title']);

		$this->assertTrue(isset($byCategory['landing_page']), 'Kampania bez konwersji → osobne działanie dla strony docelowej.');
		$this->assertTrue(in_array($byCategory['measurement'][0], $actions[$byCategory['budget'][0]]['depends_on'], true), 'Budżet zależy od pomiaru.');
		$this->assertTrue(in_array($byCategory['measurement'][0], $actions[$byCategory['new_keywords'][0]]['depends_on'], true));
		$this->assertSame([], $actions[$byCategory['negatives'][0]]['depends_on'], 'Wykluczenia nie czekają na pomiar.');
		$this->assertContains('darmowe', $actions[$byCategory['negatives'][0]]['title']);

		$position = array_column($actions, 'position', 'category');
		$this->assertTrue($position['measurement'] < $position['budget'], 'Kolejność: najpierw pomiar.');

		foreach ($actions as $action) {
			foreach (['title', 'description', 'rationale', 'sources', 'priority', 'priority_score', 'confidence', 'effort', 'expected_effect', 'depends_on'] as $field) {
				$this->assertTrue(array_key_exists($field, $action), "Brak pola {$field}.");
			}

			$this->assertFalse(isset($action['status']), 'Generator nie ustawia statusów.');
		}
	}

	public function testNotEverythingIsHighPriorityAndDataIssuesBlockOthers(): void
	{
		$recs = [$this->rec(1, 'audit_dq003', 95, 'high'), $this->rec(2, 'audit_dq005', 30, 'low')];

		for ($i = 10; $i < 20; $i++) {
			$recs[] = $this->rec($i, 'audit_cs001', 90, 'high', $i);
		}

		$actions = ActionPlanBuilder::build($recs, [], null);
		$high = count(array_filter($actions, fn ($a) => $a['priority'] === 'high'));
		$this->assertTrue($high <= (int) ceil(count($actions) * 0.3), "Najwyżej 30% działań ma wysoki priorytet (jest {$high} z " . count($actions) . ').');

		$data = array_key_first(array_filter($actions, fn ($a) => $a['category'] === 'data'));
		$dataQuality = array_key_first(array_filter($actions, fn ($a) => $a['category'] === 'data_quality'));

		foreach ($actions as $fp => $action) {
			if (! in_array($action['category'], ['data', 'data_quality'], true)) {
				$this->assertTrue(in_array($data, $action['depends_on'], true), 'Brak danych (DQ003) blokuje pozostałe działania.');
				$this->assertFalse(in_array($dataQuality, $action['depends_on'], true), 'Informacja o kompletności (DQ005) nie blokuje.');
			}
		}
	}

	// --- Monitoring ---------------------------------------------------------------------------

	public function testCpcSpikeAndSpendWithoutConversionsAreDetected(): void
	{
		$alerts = AlertDetector::detect($this->input([
			5 => $this->campaign(['clicks' => 80, 'cost_micros' => 480 * self::M, 'impressions' => 2000, 'conversions' => 0.0], ['clicks' => 400, 'cost_micros' => 1200 * self::M, 'impressions' => 9000, 'conversions' => 20.0]),
		]));
		$types = array_column($alerts, 'type');

		$this->assertTrue(in_array('cpc_spike', $types, true), json_encode($types));
		$this->assertTrue(in_array('spend_no_conversions', $types, true));
		$spike = array_values(array_filter($alerts, fn ($a) => $a['type'] === 'cpc_spike'))[0];
		$this->assertSame('critical', $spike['severity'], 'CPC ×2 – krytyczny.');
		$this->assertSame(5, $spike['campaign_id']);
	}

	public function testSmallSamplesPausedCampaignsAndIncompleteDataDoNotRaiseAlarms(): void
	{
		$spike = fn () => $this->campaign(['clicks' => 80, 'cost_micros' => 480 * self::M, 'impressions' => 2000, 'conversions' => 0.0], ['clicks' => 400, 'cost_micros' => 1200 * self::M, 'impressions' => 9000, 'conversions' => 20.0]);

		$small = AlertDetector::detect($this->input([1 => $this->campaign(['clicks' => 10, 'cost_micros' => 100 * self::M, 'impressions' => 300, 'conversions' => 0.0], ['clicks' => 50, 'cost_micros' => 150 * self::M, 'impressions' => 900, 'conversions' => 1.0])]));
		$this->assertSame([], $small, 'Za mało kliknięć – brak alarmu.');

		$paused = AlertDetector::detect($this->input([1 => ['status' => 'paused'] + $spike()]));
		$this->assertSame([], $paused, 'Kampania świadomie wstrzymana – brak alarmu.');

		$incomplete = AlertDetector::detect(['recent_complete' => false] + $this->input([1 => $spike()]));
		$this->assertSame([], $incomplete, 'Niepełne dane w oknie – brak oceny.');

		$cheap = AlertDetector::detect(['min_cost_micros' => 1000 * self::M] + $this->input([1 => $spike()]));
		$this->assertFalse(in_array('cpc_spike', array_column($cheap, 'type'), true), 'Koszt poniżej progu – brak alarmu CPC.');
	}

	public function testLeadsDropIgnoresDeliberateBudgetCutsAndMeasurementDegradation(): void
	{
		$goals = ['recent' => 2.0, 'previous' => 10.0, 'recent_cost' => 900 * self::M, 'previous_cost' => 1000 * self::M, 'google_recent' => 20.0, 'google_previous' => 20.0, 'micro_recent' => 2.0, 'micro_previous' => 2.0];
		$this->assertSame(['leads_drop'], array_column(AlertDetector::detect(['goals' => $goals] + $this->input([])), 'type'));

		$cut = ['recent_cost' => 300 * self::M] + $goals;
		$this->assertSame([], AlertDetector::detect(['goals' => $cut] + $this->input([])), 'Wydatki spadły – spadek leadów jest oczekiwany.');

		$micro = ['recent' => 5.0, 'previous' => 5.0, 'micro_recent' => 15.0, 'micro_previous' => 2.0] + $goals;
		$this->assertSame(['measurement'], array_column(AlertDetector::detect(['goals' => $micro] + $this->input([])), 'type'));
	}

	public function testSyncStaleDataPacingAndAuditAlertsHaveStableFingerprints(): void
	{
		$input = [
			'sync' => ['status' => 'active', 'last_run_status' => 'failed', 'last_completed_at' => '2026-10-01 06:00:00', 'now' => '2026-10-12 06:00:00'],
			'pacing' => ['forecast_share' => 135.0, 'elapsed_days' => 12, 'spend' => 2000 * self::M, 'budget' => 4000 * self::M, 'forecast' => 5400 * self::M, 'month' => '2026-10'],
			'audit_new_critical' => [['fingerprint' => 'abc', 'title' => 'Brak konwersji', 'id' => 1]],
		] + $this->input([]);

		$first = AlertDetector::detect($input);
		$types = array_column($first, 'type');
		sort($types);
		$this->assertSame(['audit', 'pacing', 'stale_data', 'sync_failed'], $types);
		$this->assertSame(array_keys($first), array_keys(AlertDetector::detect($input)), 'Ten sam problem = ten sam odcisk (bez duplikatów).');
		$stale = array_values(array_filter($first, fn ($a) => $a['type'] === 'stale_data'))[0];
		$this->assertSame('critical', $stale['severity'], 'Ponad 7 dni bez synchronizacji.');

		$reauth = AlertDetector::detect(['sync' => ['status' => 'reauth_required', 'last_run_status' => 'failed', 'last_completed_at' => null, 'now' => '2026-10-12 06:00:00']] + $this->input([]));
		$this->assertSame(['sync_failed'], array_column($reauth, 'type'));

		$early = AlertDetector::detect(['pacing' => ['forecast_share' => 200.0, 'elapsed_days' => 3] + $input['pacing']] + $this->input([]));
		$this->assertSame([], $early, 'Pacing ocenia się od 5. dnia miesiąca.');
	}

	// --- Pomocnicze ---------------------------------------------------------------------------

	private function rec(int $id, string $type, int $priority, string $confidence, ?int $campaignId = null, ?string $title = null): array
	{
		return [
			'id' => $id, 'type' => $type, 'fingerprint' => sha1($type . $id), 'title' => $title ?? 'Rekomendacja ' . $id, 'description' => 'Opis', 'action' => 'Działanie',
			'priority_score' => $priority, 'confidence' => $confidence, 'campaign_id' => $campaignId, 'impact_micros' => 100 * self::M, 'impact_label' => null,
			'source' => str_starts_with($type, 'leak_') ? 'budget_leaks' : 'audit', 'sources' => null, 'status' => 'new', 'evidence' => [],
		];
	}

	private function campaign(array $recent, array $baseline): array
	{
		return ['name' => 'Szukaj | Strony', 'status' => 'enabled', 'recent' => $recent, 'baseline' => $baseline, 'last3_impressions' => 100, 'baseline_active_days' => 28];
	}

	private function input(array $campaigns): array
	{
		return [
			'currency' => 'PLN',
			'min_cost_micros' => 50 * self::M,
			'recent_complete' => true,
			'baseline_complete' => true,
			'campaigns' => $campaigns,
			'goals' => ['recent' => null, 'previous' => null, 'recent_cost' => 0, 'previous_cost' => 0, 'google_recent' => 0.0, 'google_previous' => 0.0, 'micro_recent' => 0.0, 'micro_previous' => 0.0],
			'pacing' => null,
			'sync' => ['status' => 'active', 'last_run_status' => 'completed', 'last_completed_at' => '2026-10-12 05:00:00', 'now' => '2026-10-12 06:00:00'],
			'audit_new_critical' => [],
			'period' => ['recent_from' => '2026-10-05', 'recent_to' => '2026-10-11', 'baseline_from' => '2026-09-07', 'baseline_to' => '2026-10-04'],
		];
	}
}
