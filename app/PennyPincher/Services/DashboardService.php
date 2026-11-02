<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Audit\Rules\MonthlyPacingForecast;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Metrics\BudgetCalculator;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\MetricCalculator;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Repositories\AlertRepository;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\ChangeSetRepository;
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
		private AlertRepository $alerts,
		private ChangeSetRepository $changeSets,
	) {
	}

	/**
	 * Nowe (niepotwierdzone) alerty monitoringu – najważniejsze najpierw.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function newAlerts(Project $project, int $limit = 3): array
	{
		return $this->alerts->paginate($project->id, ['status' => 'new'], 1, $limit)->items;
	}

	/**
	 * Historia ostatnich zdarzeń projektu (importy, synchronizacje, audyty, analizy, zestawy zmian, alerty) – tylko odczyt
	 * istniejących rekordów, najnowsze najpierw.
	 *
	 * @return array<int, array{at: string, kind: string, title: string, status: ?string, status_label: ?string, path: string, query: array, anchor: ?string}>
	 */
	public function activity(Project $project, int $limit = 8): array
	{
		$events = [];
		$event = fn (?string $at, string $kind, string $title, ?string $status, string $path, array $query = [], ?string $anchor = null, ?string $statusLabel = null) => $at ? compact('at', 'kind', 'title', 'status', 'path', 'query', 'anchor') + ['status_label' => $statusLabel] : null;

		foreach ($this->imports->latest($project->id, $limit) as $import) {
			$events[] = $event($import['completed_at'] ?? $import['created_at'], 'Import', (string) $import['original_filename'], $import['status'], 'imports/' . $import['id']);
		}

		foreach ($this->runs->recentForProject($project->id, $limit) as $run) {
			$events[] = $event($run['finished_at'] ?? $run['created_at'], 'Synchronizacja Google Ads', $run['type'] === 'backfill' ? 'Pobranie historii konta' : 'Aktualizacja danych z konta', $run['status'], 'google-ads');
		}

		foreach ($this->audits->runs($project->id, $limit) as $run) {
			$events[] = $event($run['created_at'], 'Audyt', 'Audyt konta' . ($run['score'] !== null ? ' – wynik ' . (int) $run['score'] . '/100' : ''), $run['status'], 'audits/' . $run['id']);
		}

		foreach ([BudgetLeakService::TYPE => ['Analiza wycieków budżetu', 'budget-leaks'], KeywordOpportunityService::TYPE => ['Analiza słów kluczowych', 'keywords']] as $type => [$title, $path]) {
			foreach ($this->analyses->runs($project->id, $type, $limit) as $run) {
				$query = ['period' => $run['range_preset'] === 'custom' || ! $run['range_preset'] ? 'custom' : $run['range_preset']] + ($run['range_preset'] === 'custom' || ! $run['range_preset'] ? ['from' => $run['date_from'], 'to' => $run['date_to']] : []);
				$events[] = $event($run['created_at'], 'Analiza', $title, $run['status'], $path, $query);
			}
		}

		foreach ($this->changeSets->paginate($project->id, [], 1, $limit)->items as $set) {
			$events[] = $event($set['updated_at'], 'Zmiany na koncie', (string) $set['title'], $set['status'], 'changes/' . $set['id'], [], null, ChangeStatus::LABELS[$set['status']] ?? null);
		}

		foreach ($this->alerts->paginate($project->id, [], 1, $limit)->items as $alert) {
			$events[] = $event($alert['last_detected_at'], 'Alert', (string) $alert['title'], $alert['severity'], 'monitoring', ['open' => $alert['id']], 'alert-' . $alert['id']);
		}

		return self::latestEvents($events, $limit);
	}

	/**
	 * Łączy zdarzenia z różnych źródeł: najnowsze najpierw (przy remisie zachowuje kolejność źródeł), bez pustych.
	 *
	 * @param array<int, ?array{at: string}> $events
	 * @return array<int, array{at: string}>
	 */
	public static function latestEvents(array $events, int $limit): array
	{
		$events = array_values(array_filter($events));
		$order = array_keys($events);
		array_multisort(array_column($events, 'at'), SORT_DESC, SORT_STRING, $order, SORT_ASC, $events);

		return array_slice($events, 0, $limit);
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
