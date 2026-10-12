<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Audit\Rules\MonthlyPacingForecast;
use App\PennyPincher\Domain\Metrics\BudgetCalculator;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\MetricCalculator;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use App\PennyPincher\Services\Analysis\BudgetLeakService;
use App\PennyPincher\Services\Analysis\KeywordOpportunityService;
use App\PennyPincher\Services\Audit\AuditContextBuilder;

/**
 * Dane dashboardu. KPI i wykresy pochodzą wyłącznie z raportu kampanii; sekcja wyszukiwanych haseł
 * ma własne źródło i własne pokrycie dat – tych kosztów nie dodajemy do siebie.
 */
class DashboardService
{
	public function __construct(
		private MetricsRepository $metrics,
		private ImportRepository $imports,
		private GoogleAdsConnectionRepository $connections,
		private SyncRunRepository $runs,
		private AuditRepository $audits,
		private AuditContextBuilder $auditContext,
		private AnalysisRepository $analyses,
		private RecommendationRepository $recommendations,
	) {
	}

	/**
	 * Optymalizacja: najważniejsze wycieki budżetu, nowe rekomendacje i możliwości z ostatnich analiz.
	 * Bez analizy – null (widok pokazuje stan pusty, bez sztucznych liczb).
	 *
	 * @return array<string, mixed>
	 */
	public function optimization(Project $project): array
	{
		$leakRun = $this->analyses->latestRun($project->id, BudgetLeakService::TYPE);
		$keywordRun = $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE);
		$leaks = $leakRun ? array_values(array_filter($this->analyses->leaks((int) $leakRun['id']), fn ($l) => $l['status'] !== 'dismissed')) : [];
		$counts = $this->recommendations->counts($project->id);

		return [
			'leak_run' => $leakRun,
			'top_leaks' => array_slice($leaks, 0, 3),
			'leak_count' => count($leaks),
			'savings' => $leakRun ? BudgetLeakService::savings($leaks, $leakRun['summary']) : null,
			'keyword_run' => $keywordRun,
			'opportunities' => $keywordRun ? count(array_filter($this->analyses->opportunities((int) $keywordRun['id']), fn ($o) => (int) $o['score'] >= 50)) : null,
			'negatives' => $keywordRun ? count(array_filter($this->analyses->negativeSuggestions((int) $keywordRun['id']), fn ($n) => ! (int) $n['already_excluded'])) : null,
			'new_recommendations' => $counts['new'] ?? 0,
			'open_recommendations' => ($counts['new'] ?? 0) + ($counts['to_review'] ?? 0) + ($counts['accepted'] ?? 0),
			'top_recommendations' => $this->recommendations->top($project->id, 3),
		];
	}

	/**
	 * Stan konta: połączenie z Google Ads, ostatni audyt i tempo wydatków w bieżącym miesiącu.
	 *
	 * @return array<string, mixed>
	 */
	public function health(Project $project): array
	{
		$connection = $this->connections->currentForProject($project->id);
		$run = $this->audits->latestRun($project->id);
		$findings = $run ? array_values(array_filter($this->audits->findings((int) $run['id']), fn ($f) => $f['status'] !== 'dismissed')) : [];

		return [
			'connection' => $connection,
			'lastSync' => $connection ? $this->runs->lastCompleted((int) $connection['id']) : null,
			'lastRun' => $connection ? ($this->runs->recentForProject($project->id, 1)[0] ?? null) : null,
			'audit' => $run,
			'auditCritical' => count(array_filter($findings, fn ($f) => $f['severity'] === 'critical')),
			'auditTop' => array_slice(array_values(array_filter($findings, fn ($f) => $f['severity'] !== 'info' && $f['category'] !== 'data_quality')), 0, 5),
			'dataQuality' => array_values(array_filter($findings, fn ($f) => $f['category'] === 'data_quality' && $f['severity'] !== 'info')),
			'pacing' => self::pacing($project, $this->auditContext->monthToDate($project)),
		];
	}

	/**
	 * Tempo wydatków w bieżącym miesiącu. Prognoza to szacunek liniowy i liczymy ją tylko przy pełnych danych dziennych.
	 */
	public static function pacing(Project $project, array $mtd): array
	{
		$budget = $project->monthlyBudgetMicros;
		$forecast = null;

		if ($mtd['spend'] !== null && $mtd['complete'] && $mtd['covered_days'] >= MonthlyPacingForecast::MIN_DAYS) {
			$forecast = (int) round($mtd['spend'] / $mtd['covered_days'] * $mtd['days_in_month']);
		}

		return $mtd + [
			'budget' => $budget,
			'used' => $budget && $mtd['spend'] !== null ? $mtd['spend'] / $budget * 100 : null,
			'forecast' => $forecast,
			'forecast_share' => $budget && $forecast !== null ? $forecast / $budget * 100 : null,
			'expected_share' => $mtd['days_in_month'] > 0 ? $mtd['elapsed_days'] / $mtd['days_in_month'] * 100 : null,
		];
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
