<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Leaks\Leak;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\BudgetLeakService;
use App\PennyPincher\Support\AnalysisPeriodRequest;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Budget Leaks: miejsca, w których budżet prawdopodobnie jest marnowany – z poziomem pewności i szacunkiem oszczędności.
 * Wyświetlanie czyta zapisane wyniki analizy; nic nie jest liczone ani pobierane przy wejściu na stronę.
 */
class BudgetLeaksController extends Controller
{
	public function __construct(
		AccessService $access,
		private AnalysisRepository $analyses,
		private BudgetLeakService $service,
		private MetricsRepository $metrics,
		private AnalysisPeriodRequest $periods,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$period = $this->periods->resolve($request, $project);
		$run = $this->analyses->latestRun($project->id, BudgetLeakService::TYPE, $period->range->fromString(), $period->range->toString());

		$filters = [
			'campaign_id' => ctype_digit((string) $request->query('campaign', '')) ? (int) $request->query('campaign') : null,
			'category' => array_key_exists((string) $request->query('category', ''), Leak::CATEGORIES) ? (string) $request->query('category') : null,
			'priority' => array_key_exists((string) $request->query('priority', ''), Leak::PRIORITIES) ? (string) $request->query('priority') : null,
			'status' => array_key_exists((string) $request->query('status', ''), AnalysisRepository::LEAK_STATUSES) ? (string) $request->query('status') : null,
		];

		$all = $run ? $this->analyses->leaks((int) $run['id']) : [];
		$leaks = $run ? (array_filter($filters) === [] ? $all : $this->analyses->leaks((int) $run['id'], $filters)) : [];

		return $this->render('app.pages.budget-leaks', [
			'project' => $project,
			'period' => $period,
			'run' => $run,
			'leaks' => $leaks,
			'all' => $all,
			'filters' => $filters,
			'savings' => $run ? BudgetLeakService::savings($all, $run['summary']) : null,
			'campaignOptions' => $this->metrics->campaignOptions($project->id),
			'adGroups' => $this->metrics->adGroups($project->id),
			'history' => $this->analyses->runs($project->id, BudgetLeakService::TYPE, 8),
			'canRun' => Policy::allows($project->role, Ability::ImportData),
			'pageTitle' => 'Budget Leaks',
			'section' => 'budget-leaks',
		]);
	}

	public function updateStatus(int $project, int $leak, Request $request)
	{
		$project = $this->access->project($project, Ability::ImportData);

		try {
			$row = $this->service->updateStatus($project, $leak, (string) $request->input('status', ''), $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($project->id, 'budget-leaks'), array_values($e->errors)[0] ?? $e->getMessage(), 'error');
		}

		$run = $this->analyses->findRun($project->id, (int) $row['analysis_run_id']);
		$query = $run ? ($run['range_preset'] === 'custom' ? ['period' => 'custom', 'from' => $run['date_from'], 'to' => $run['date_to']] : ['period' => $run['range_preset']]) : [];

		return redirect()->to(AppUrl::project($project->id, 'budget-leaks', $query) . '#leak-' . $row['id']);
	}
}
