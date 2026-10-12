<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Metrics\BudgetCalculator;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\MetricCalculator;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\MetricsRepository;

/**
 * Dane dashboardu. KPI i wykresy pochodzą wyłącznie z raportu kampanii; sekcja wyszukiwanych haseł
 * ma własne źródło i własne pokrycie dat – tych kosztów nie dodajemy do siebie.
 */
class DashboardService
{
	public function __construct(private MetricsRepository $metrics, private ImportRepository $imports)
	{
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(Project $project, DateRange $range): array
	{
		$resolved = $this->metrics->resolve('campaign_metrics', $project->id, $range);
		$totals = $this->metrics->campaignTotals($project->id, $resolved);

		$budgetForRange = BudgetCalculator::budgetForRange($project->monthlyBudgetMicros, $range);

		// Przy niepełnym pokryciu porównujemy wydatki z budżetem tylko dla dni, za które mamy dane.
		$budgetCompared = $resolved->isComplete()
			? $budgetForRange
			: BudgetCalculator::budgetForCoverage($project->monthlyBudgetMicros, $resolved);

		$kpis = [
			'spend' => $totals['cost_micros'],
			'clicks' => $totals['clicks'],
			'impressions' => $totals['impressions'],
			'ctr' => MetricCalculator::ctr($totals['clicks'], $totals['impressions']),
			'cpc' => MetricCalculator::averageCpc($totals['cost_micros'], $totals['clicks']),
			'conversions' => $totals['conversions'],
			'cpa' => MetricCalculator::costPerConversion($totals['cost_micros'], $totals['conversions']),
			'monthly_budget' => $project->monthlyBudgetMicros,
			'budget_for_range' => $budgetForRange,
			'budget_compared' => $budgetCompared,
			'budget_partial' => ! $resolved->isComplete(),
			'budget_used' => $resolved->isEmpty() ? null : MetricCalculator::share($totals['cost_micros'], $budgetCompared),
		];

		$searchResolved = $this->metrics->resolve('search_term_metrics', $project->id, $range);
		$conversionResolved = $this->metrics->resolve('conversion_metrics', $project->id, $range);

		return [
			'kpis' => $kpis,
			'resolved' => $resolved,
			'series' => $resolved->hasDaily() ? $this->metrics->dailySeries($project->id, $resolved, $range) : [],
			'campaigns' => $this->metrics->campaigns($project->id, $resolved, [], 'spend', 'desc', 1, 8),
			'searchTerms' => $this->metrics->searchTerms($project->id, $searchResolved, [], 'spend', 'desc', 1, 8),
			'searchResolved' => $searchResolved,
			'conversionActions' => $this->metrics->conversionActions($project->id, $conversionResolved),
			'conversionResolved' => $conversionResolved,
			'imports' => $this->imports->latest($project->id, 5),
			'hasAnyData' => $this->metrics->hasAnyData($project->id),
			'campaignPeriods' => $this->metrics->availablePeriods($project->id, 'campaign_metrics'),
		];
	}
}
