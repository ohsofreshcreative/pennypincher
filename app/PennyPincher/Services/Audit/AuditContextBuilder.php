<?php

namespace App\PennyPincher\Services\Audit;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use DateTimeImmutable;

/**
 * Zbiera dane wejściowe audytu: ostatnie 30 zakończonych dni (strefa czasowa projektu) i poprzednie 30 dni do porównań.
 */
class AuditContextBuilder
{
	public const PERIOD_DAYS = 30;

	public function __construct(
		private Db $db,
		private MetricsRepository $metrics,
		private GoogleAdsConnectionRepository $connections,
		private SyncRunRepository $runs,
	) {
	}

	public function build(Project $project, ?DateTimeImmutable $today = null): AuditContext
	{
		$today = ($today ?? new DateTimeImmutable('now', $project->timezone()))->setTime(0, 0);
		$range = new DateRange($today->modify('-' . self::PERIOD_DAYS . ' days'), $today->modify('-1 day'));
		$previous = new DateRange($range->from->modify('-' . self::PERIOD_DAYS . ' days'), $range->from->modify('-1 day'));

		$coverage = $this->metrics->resolve('campaign_metrics', $project->id, $range);
		$current = $this->metrics->campaignMetricsByCampaign($project->id, $coverage);
		$before = $this->metrics->campaignMetricsByCampaign($project->id, $this->metrics->resolve('campaign_metrics', $project->id, $previous));

		$campaigns = [];

		foreach ($this->db->select('SELECT id, name, external_id, status, channel_type, budget_micros, budget_period, bidding_strategy, settings FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? ORDER BY name', [$project->id]) as $row) {
			$id = (int) $row['id'];
			$row['id'] = $id;
			$row['budget_micros'] = $row['budget_micros'] === null ? null : (int) $row['budget_micros'];
			$row['settings'] = $row['settings'] ? (json_decode($row['settings'], true) ?: []) : [];
			$row['current'] = $current[$id] ?? [];
			$row['previous'] = $before[$id] ?? [];
			$campaigns[] = $row;
		}

		$connection = $this->connections->currentForProject($project->id);
		$lastSync = $connection ? $this->runs->lastCompleted((int) $connection['id']) : null;
		$lastRun = $connection ? ($this->runs->recentForProject($project->id, 1)[0] ?? null) : null;
		$terms = $this->metrics->searchTermsWithoutConversions($project->id, $this->metrics->resolve('search_term_metrics', $project->id, $range));

		return new AuditContext(
			currency: $project->currency,
			monthlyBudgetMicros: $project->monthlyBudgetMicros,
			range: $range,
			previousRange: $previous,
			campaigns: $campaigns,
			conversionActions: $this->conversionActions($project->id, $range),
			coverage: $coverage,
			connection: $connection,
			lastSyncAt: $lastSync['finished_at'] ?? null,
			lastRunStatus: $lastRun['status'] ?? null,
			lastRunError: $lastRun['last_error'] ?? null,
			monthToDate: $this->monthToDate($project, $today),
			searchTermsWithoutConversions: $terms['terms'],
			searchTermsTotalCostMicros: $terms['total_cost_micros'],
			now: time(),
			projectCurrencyMismatch: ($connection['currency'] ?? null) && $connection['currency'] !== $project->currency ? $connection['currency'] : null,
		);
	}

	/**
	 * Wszystkie działania konwersji projektu (także bez konwersji w okresie) z ustawieniami i liczbą konwersji.
	 */
	private function conversionActions(int $projectId, DateRange $range): array
	{
		$resolved = $this->metrics->resolve('conversion_metrics', $projectId, $range);
		$metrics = [];

		if (! $resolved->isEmpty()) {
			foreach ($this->metrics->conversionActions($projectId, $resolved) as $row) {
				$metrics[(int) $row['id']] = $row;
			}
		}

		$actions = [];

		foreach ($this->db->select('SELECT id, name, external_id, category, category_code, type, status, include_in_conversions, primary_for_goal, counting_type, settings FROM ' . $this->db->table('conversion_actions') . ' WHERE project_id = ? ORDER BY name', [$projectId]) as $row) {
			$id = (int) $row['id'];
			$row['id'] = $id;
			$row['settings'] = $row['settings'] ? (json_decode($row['settings'], true) ?: []) : [];
			$row['primary_for_goal'] = $row['primary_for_goal'] === null ? null : (bool) $row['primary_for_goal'];
			$row['include_in_conversions'] = $row['include_in_conversions'] === null ? null : (bool) $row['include_in_conversions'];
			$row['conversions'] = isset($metrics[$id]['conversions']) ? (float) $metrics[$id]['conversions'] : null;
			$row['all_conversions'] = isset($metrics[$id]['all_conversions']) ? (float) $metrics[$id]['all_conversions'] : null;
			$row['conversions_value_micros'] = isset($metrics[$id]['conversions_value_micros']) ? (int) $metrics[$id]['conversions_value_micros'] : null;
			$actions[] = $row;
		}

		return $actions;
	}

	/**
	 * Wydatki od początku miesiąca do wczoraj (dzisiejszy dzień nie jest zakończony).
	 */
	public function monthToDate(Project $project, ?DateTimeImmutable $today = null): array
	{
		$today = ($today ?? new DateTimeImmutable('now', $project->timezone()))->setTime(0, 0);
		$first = $today->modify('first day of this month');
		$elapsed = (int) $first->diff($today)->days;
		$daysInMonth = (int) $today->format('t');

		if ($elapsed < 1) {
			return ['spend' => null, 'covered_days' => 0, 'elapsed_days' => 0, 'days_in_month' => $daysInMonth, 'complete' => false, 'month' => $today->format('m.Y')];
		}

		$range = new DateRange($first, $today->modify('-1 day'));
		$resolved = $this->metrics->resolve('campaign_metrics', $project->id, $range);
		$spend = $resolved->isEmpty() ? null : $this->metrics->campaignTotals($project->id, $resolved)['cost_micros'];

		// Raporty okresowe nie mówią, ile wydano w poszczególnych dniach – do tempa liczymy tylko dane dzienne.
		$daily = $resolved->periods === [];

		return [
			'spend' => $daily ? $spend : null,
			'covered_days' => min($resolved->coveredDays, $range->days()),
			'elapsed_days' => $range->days(),
			'days_in_month' => $daysInMonth,
			'complete' => $daily && $resolved->isComplete(),
			'month' => $today->format('m.Y'),
		];
	}
}
