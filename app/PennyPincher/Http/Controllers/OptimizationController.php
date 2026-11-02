<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\BudgetLeakService;
use App\PennyPincher\Services\Analysis\KeywordOpportunityService;
use App\PennyPincher\Support\AnalysisPeriodRequest;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use Illuminate\Http\Request;

/**
 * Optymalizacja – wspólny punkt wejścia (pp2_optimization): okres, uruchomienie pełnej analizy i przegląd wyników
 * Audytu, Budget Leaks, Keyword Opportunities i Rekomendacji dla tego samego okresu. Tylko odczyt zapisanych
 * przebiegów – reguły, statusy i szczegóły zostają w modułach źródłowych (linki z okresem i filtrem kampanii).
 */
class OptimizationController extends Controller
{
	public function __construct(
		AccessService $access,
		private AnalysisPeriodRequest $periods,
		private AuditRepository $audits,
		private AnalysisRepository $analyses,
		private RecommendationRepository $recommendations,
		private MetricsRepository $metrics,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);

		// Bez flagi adres zachowuje się jak dotychczasowy alias sekcji (przekierowanie do Audytu z parametrami).
		if (! Feature::enabled(Feature::OPTIMIZATION)) {
			return redirect()->to(AppUrl::project($project->id, 'audits', $request->query()));
		}

		$this->access->rememberProject($project);
		$period = $this->periods->resolve($request, $project);
		$from = $period->range->fromString();
		$to = $period->range->toString();
		$campaignOptions = $this->metrics->campaignOptions($project->id);
		$campaign = (int) $request->query('campaign', 0);
		$campaign = isset($campaignOptions[$campaign]) ? $campaign : null;

		$audit = $this->audits->latestRunForPeriod($project->id, $from, $to);
		$leakRun = $this->analyses->latestRun($project->id, BudgetLeakService::TYPE, $from, $to);
		$keywordRun = $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE, $from, $to);
		$allLeaks = $leakRun ? $this->analyses->leaks((int) $leakRun['id']) : [];
		$findings = $audit ? $this->audits->findings((int) $audit['id']) : [];

		return $this->render('app.pages.optimization', [
			'project' => $project,
			'period' => $period,
			'campaign' => $campaign,
			'campaignOptions' => $campaignOptions,
			'audit' => $audit,
			'findings' => array_values(array_filter($findings, fn ($f) => $f['status'] !== 'dismissed')),
			'leakRun' => $leakRun,
			'leaks' => array_values(array_filter($allLeaks, fn ($l) => $l['status'] !== 'dismissed' && ($campaign === null || (int) $l['campaign_id'] === $campaign))),
			'savings' => $leakRun ? BudgetLeakService::savings($allLeaks, $leakRun['summary']) : null,
			'keywordRun' => $keywordRun,
			'recommendations' => $this->recommendations->paginate($project->id, ['from' => $from, 'to' => $to] + ($campaign ? ['campaign_id' => $campaign] : []), 1, 10),
			'canRun' => Policy::allows($project->role, Ability::ImportData),
			'pageTitle' => 'Optymalizacja',
			'section' => 'optimization',
		]);
	}
}
