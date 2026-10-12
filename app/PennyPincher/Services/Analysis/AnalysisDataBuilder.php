<?php

namespace App\PennyPincher\Services\Analysis;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Leaks\LeakContext;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\Audit\AuditContextBuilder;

/**
 * Wspólne pobieranie i normalizacja danych dla analiz (Budget Leaks, słowa kluczowe).
 * Metryki zawsze przez MetricsRepository + PeriodResolver – bez nakładania się importów i API.
 */
class AnalysisDataBuilder
{
	public function __construct(
		private Db $db,
		private MetricsRepository $metrics,
		private ConversionBreakdown $breakdown,
		private AuditContextBuilder $audit,
	) {
	}

	public function leakContext(Project $project, AnalysisPeriod $period): LeakContext
	{
		$range = $period->range;
		$coverage = $this->metrics->resolve('campaign_metrics', $project->id, $range);
		$current = $this->metrics->campaignMetricsByCampaign($project->id, $coverage);
		$previous = $this->metrics->campaignMetricsByCampaign($project->id, $this->metrics->resolve('campaign_metrics', $project->id, $period->previous()));
		$campaigns = [];

		foreach ($this->db->select('SELECT id, name, status, channel_type, budget_micros, bidding_strategy, settings FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ?', [$project->id]) as $row) {
			$id = (int) $row['id'];
			$campaigns[$id] = [
				'id' => $id,
				'name' => (string) $row['name'],
				'status' => $row['status'],
				'channel_type' => $row['channel_type'],
				'bidding_strategy' => $row['bidding_strategy'],
				'settings' => $row['settings'] ? (json_decode($row['settings'], true) ?: []) : [],
				'current' => $current[$id] ?? [],
				'previous' => $previous[$id] ?? [],
			];
		}

		$termsResolved = $this->metrics->resolve('search_term_metrics', $project->id, $range);
		$keywordsResolved = $this->metrics->resolve('keyword_metrics', $project->id, $range);

		return new LeakContext(
			currency: $project->currency,
			period: $period,
			campaigns: $campaigns,
			adGroups: $this->metrics->adGroups($project->id),
			terms: $this->metrics->searchTermStats($project->id, $termsResolved),
			keywords: $this->metrics->keywordStats($project->id, $keywordsResolved),
			breakdown: $this->breakdown->forPeriod($project->id, $range),
			negatives: $this->metrics->negativeKeywords($project->id),
			context: $project->context(),
			monthlyBudgetMicros: $project->monthlyBudgetMicros,
			dataState: $this->audit->activity($project->id, $range, $coverage)['state'],
		);
	}
}
