<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\ActionPlan\ActionPlanBuilder;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\ActionItemRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\ActionPlan\ActionPlanService;
use App\PennyPincher\Services\Analysis\AnalysisService;
use App\PennyPincher\Support\AnalysisPeriodRequest;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Action Plan: uporządkowane działania z rekomendacji (zależności, statusy, notatki, historia).
 */
class ActionPlanController extends Controller
{
	public function __construct(
		AccessService $access,
		private ActionItemRepository $items,
		private ActionPlanService $service,
		private AnalysisService $analysis,
		private MetricsRepository $metrics,
		private AnalysisPeriodRequest $periods,
		private \App\PennyPincher\Services\Changes\ChangeLinkService $changeLinks,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$period = $this->periods->resolve($request, $project);
		$filters = [
			'status' => array_key_exists((string) $request->query('status', ''), ActionItemRepository::STATUSES) ? (string) $request->query('status') : null,
			'category' => array_key_exists((string) $request->query('category', ''), ActionPlanBuilder::CATEGORIES) ? (string) $request->query('category') : null,
			'priority' => in_array($request->query('priority'), ['high', 'medium', 'low'], true) ? (string) $request->query('priority') : null,
			'obsolete' => in_array($request->query('obsolete'), ['active', 'obsolete', 'all'], true) ? (string) $request->query('obsolete') : 'active',
		];
		$items = $this->items->list($project->id, $filters);
		$all = $this->items->byFingerprint($project->id);
		$open = ctype_digit((string) $request->query('open', '')) ? (int) $request->query('open') : null;

		return $this->render('app.pages.action-plan', [
			'project' => $project,
			'changes' => $this->changeLinks->forSources($project, 'action_item', array_column($items, 'id')),
			'deployable' => $this->changeLinks->deployableActionItems($project, $items),
			'period' => $period,
			'items' => $items,
			'all' => $all,
			'filters' => $filters,
			'counts' => $this->items->counts($project->id),
			'events' => $open ? $this->items->events($project->id, $open) : [],
			'open' => $open,
			'campaignOptions' => $this->metrics->campaignOptions($project->id),
			'canEdit' => Policy::allows($project->role, Ability::ManageKeywords),
			'canRun' => Policy::allows($project->role, Ability::ImportData),
			'pageTitle' => 'Action Plan',
			'section' => 'action-plan',
		]);
	}

	/**
	 * Wygenerowanie planu dla okresu: analiza (audyt, Budget Leaks, słowa, rekomendacje) i odświeżenie działań.
	 */
	public function generate(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::ImportData);
		$period = $this->periods->resolve($request, $project);
		$result = $this->analysis->run($project, $period, 'manual', $this->access->userId());
		$url = AppUrl::project($project->id, 'action-plan', AnalysisPeriodRequest::query($period));

		if ($result['errors'] !== []) {
			return $this->redirect($url, 'Plan wygenerowany częściowo: ' . implode(' ', array_unique($result['errors'])), 'error');
		}

		$a = $result['actions'] ?? ['total' => 0, 'created' => 0, 'obsolete' => 0];

		return $this->redirect($url, sprintf('Plan działań dla okresu %s: %d działań (nowe: %d, nieaktualne: %d).', $period->range->label(), $a['total'], $a['created'], $a['obsolete']));
	}

	public function updateStatus(int $project, int $item, Request $request)
	{
		return $this->act($project, $item, fn ($p) => $this->service->updateStatus($p, $item, (string) $request->input('status', ''), $this->access->userId(), $request->input('note'), $request->input('also_recommendations') === '1'), $request);
	}

	public function updateNote(int $project, int $item, Request $request)
	{
		return $this->act($project, $item, fn ($p) => $this->service->updateNote($p, $item, $request->input('note'), $this->access->userId()), $request);
	}

	private function act(int $project, int $item, \Closure $action, Request $request)
	{
		$project = $this->access->project($project, Ability::ManageKeywords);
		parse_str((string) $request->input('back', ''), $back);
		$query = array_intersect_key(array_filter($back, 'is_scalar'), array_flip(['status', 'category', 'priority', 'obsolete', 'period', 'from', 'to']));

		try {
			$action($project);
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($project->id, 'action-plan', $query), (string) (array_values($e->errors)[0] ?? $e->getMessage()), 'error');
		}

		return redirect()->to(AppUrl::project($project->id, 'action-plan', $query + ['open' => $item]) . '#action-' . $item);
	}
}
