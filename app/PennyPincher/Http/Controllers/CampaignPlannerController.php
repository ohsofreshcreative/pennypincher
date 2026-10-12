<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Planner\BudgetAllocator;
use App\PennyPincher\Domain\Planner\PlanStatus;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\CampaignPlanRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\KeywordOpportunityService;
use App\PennyPincher\Services\Planner\BudgetPlannerService;
use App\PennyPincher\Services\Planner\CampaignPlanService;
use App\PennyPincher\Services\Planner\PlanExporter;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Format;
use Closure;
use Illuminate\Http\Request;

/**
 * Campaign Planner + Budget Planner. Plany są dokumentami roboczymi – nic nie trafia na konto Google Ads.
 * Uprawnienia: podgląd – każdy członek; edycja – EditPlans; zatwierdzanie i archiwizacja – ApprovePlans;
 * budżety – ManageBudget.
 */
class CampaignPlannerController extends Controller
{
	public function __construct(
		AccessService $access,
		private CampaignPlanRepository $plans,
		private CampaignPlanService $planner,
		private BudgetPlannerService $budgets,
		private AnalysisRepository $analyses,
		private \App\PennyPincher\Services\Changes\ChangeLinkService $changeLinks,
		private \App\PennyPincher\Services\Changes\PlanDeploymentService $deployments,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$status = array_key_exists((string) $request->query('status', ''), PlanStatus::LABELS) ? (string) $request->query('status') : null;

		return $this->render('app.pages.planner.index', [
			'project' => $project,
			'plans' => $this->plans->paginate($project->id, $status, max(1, (int) $request->query('page', 1))),
			'status' => $status,
			'canEdit' => Policy::allows($project->role, Ability::EditPlans),
			'keywordRun' => $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE),
			'pageTitle' => 'Campaign Planner',
			'section' => 'planner',
		]);
	}

	public function store(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::EditPlans);

		try {
			$id = $this->planner->create($project, $request->input(), $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($project->id, 'planner'), self::first($e), 'error');
		}

		return $this->redirect(AppUrl::project($project->id, "planner/{$id}"), 'Utworzono szkic planu.');
	}

	/**
	 * Dodanie fraz lub klastrów z Keyword Opportunities do istniejącego albo nowego planu.
	 */
	public function fromKeywords(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::EditPlans);
		$planId = ctype_digit((string) $request->input('plan_id', '')) ? (int) $request->input('plan_id') : null;

		try {
			$result = $this->planner->addFromOpportunities(
				$project,
				$planId,
				(int) $request->input('run'),
				array_map('strval', array_filter((array) $request->input('phrases', []), 'is_string')),
				array_map('strval', array_filter((array) $request->input('clusters', []), fn ($c) => is_string($c) && preg_match('/^[a-f0-9]{40}$/', $c))),
				$this->access->userId()
			);
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($project->id, 'keywords'), self::first($e), 'error');
		}

		return $this->redirect(AppUrl::project($project->id, 'planner/' . $result['plan_id']), sprintf('Dodano %d %s do planu%s.', $result['added'], Format::plural($result['added'], 'frazę', 'frazy', 'fraz'), $result['skipped'] ? " ({$result['skipped']} już było w planie)" : ''));
	}

	public function show(int $project, int $plan, Request $request)
	{
		$project = $this->access->project($project);
		$row = $this->plans->find($project->id, $plan);

		if ($row === null) {
			throw new NotFound('Plan nie istnieje.');
		}

		$tree = $this->plans->tree($project->id, $plan);
		$measurement = $this->planner->measurement($project);
		$keywordRun = $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE);
		$tab = in_array($request->query('tab'), ['structure', 'budget', 'negatives', 'ads', 'history'], true) ? (string) $request->query('tab') : 'structure';
		$editable = in_array($row['status'], PlanStatus::EDITABLE, true);

		return $this->render('app.pages.planner.show', [
			'project' => $project,
			'plan' => $row,
			'tree' => $tree,
			'tab' => $tab,
			'measurement' => $measurement,
			'conflicts' => $this->planner->conflicts($tree),
			'budget' => $this->budgets->summary($project, $row, $tree, $measurement),
			'scenarios' => BudgetAllocator::SCENARIOS,
			'negativeSuggestions' => $keywordRun && $tab === 'negatives' ? array_filter($this->analyses->negativeSuggestions((int) $keywordRun['id']), fn ($n) => ! (int) $n['already_excluded']) : [],
			'events' => $tab === 'history' ? $this->plans->events($project->id, $plan) : [],
			'editable' => $editable,
			'canEdit' => $editable && Policy::allows($project->role, Ability::EditPlans),
			'canApprove' => Policy::allows($project->role, Ability::ApprovePlans),
			'canBudget' => $editable && Policy::allows($project->role, Ability::ManageBudget),
			'canEditStatus' => Policy::allows($project->role, Ability::EditPlans),
			'changes' => $this->changeLinks->forSources($project, 'plan', [$plan]),
			'deployedLinks' => $this->deployments->links($project, $plan),
			'pageTitle' => $row['name'],
			'section' => 'planner',
		]);
	}

	public function update(int $project, int $plan, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->update($p, $plan, $request->input(), $this->access->userId()), 'Zapisano plan.', $request);
	}

	public function status(int $project, int $plan, Request $request)
	{
		$projectModel = $this->access->project($project, Ability::EditPlans);
		$row = $this->plans->find($projectModel->id, $plan);
		$status = (string) $request->input('status', '');

		// Zatwierdzanie, archiwizacja i zmiany zatwierdzonego planu – tylko z uprawnieniem ApprovePlans.
		if ($row !== null && (in_array($status, [PlanStatus::APPROVED, PlanStatus::ARCHIVED], true) || ! in_array($row['status'], PlanStatus::EDITABLE, true))) {
			$this->access->project($project, Ability::ApprovePlans);
		}

		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->setStatus($p, $plan, $status, $this->access->userId()), 'Zmieniono status planu.', $request);
	}

	public function copy(int $project, int $plan)
	{
		$projectModel = $this->access->project($project, Ability::EditPlans);

		try {
			$id = $this->planner->copy($projectModel, $plan, $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($projectModel->id, 'planner'), self::first($e), 'error');
		}

		return $this->redirect(AppUrl::project($projectModel->id, "planner/{$id}"), 'Utworzono kopię planu (szkic).');
	}

	public function export(int $project, int $plan, string $format)
	{
		$projectModel = $this->access->project($project);
		$row = $this->plans->find($projectModel->id, $plan);

		if ($row === null || ! in_array($format, ['csv', 'html'], true)) {
			throw new NotFound('Plan nie istnieje.');
		}

		$tree = $this->plans->tree($projectModel->id, $plan);

		if ($format === 'csv') {
			return response(PlanExporter::csv($row, $tree), 200, [
				'Content-Type' => 'text/csv; charset=UTF-8',
				'Content-Disposition' => 'attachment; filename="' . PlanExporter::filename($row, 'csv') . '"',
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control' => 'no-store',
			]);
		}

		$measurement = $this->planner->measurement($projectModel);

		return response(view('app.pages.planner.export', [
			'project' => $projectModel,
			'plan' => $row,
			'tree' => $tree,
			'budget' => $this->budgets->summary($projectModel, $row, $tree, $measurement),
			'conflicts' => $this->planner->conflicts($tree),
			'measurement' => $measurement,
		]), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN']);
	}

	// --- Struktura ------------------------------------------------------------------------------

	public function addCampaign(int $project, int $plan, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->addCampaign($p, $plan, (string) $request->input('name', ''), (string) $request->input('kind', 'search')), 'Dodano kampanię.', $request);
	}

	public function updateCampaign(int $project, int $plan, int $campaign, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->updateCampaign($p, $plan, $campaign, $request->only(['name', 'final_url', 'kind', 'notes'])), 'Zapisano kampanię.', $request, "campaign-{$campaign}");
	}

	public function deleteCampaign(int $project, int $plan, int $campaign, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->deleteCampaign($p, $plan, $campaign), 'Usunięto kampanię z planu.', $request);
	}

	public function addGroup(int $project, int $plan, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->addGroup($p, $plan, (int) $request->input('plan_campaign_id'), (string) $request->input('name', '')), 'Dodano grupę reklam.', $request, 'campaign-' . (int) $request->input('plan_campaign_id'));
	}

	public function updateGroup(int $project, int $plan, int $group, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->updateGroup($p, $plan, $group, $request->only(['name', 'final_url', 'plan_campaign_id'])), 'Zapisano grupę reklam.', $request, "group-{$group}");
	}

	public function deleteGroup(int $project, int $plan, int $group, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->deleteGroup($p, $plan, $group), 'Usunięto grupę reklam.', $request);
	}

	public function addKeywords(int $project, int $plan, int $group, Request $request)
	{
		$phrases = preg_split('/[\r\n]+/u', (string) $request->input('phrases', '')) ?: [];
		$match = (string) $request->input('match_type', '');

		return $this->act($project, $plan, Ability::EditPlans, function ($p) use ($plan, $group, $phrases, $match) {
			$result = $this->planner->addKeywords($p, $plan, $group, $phrases, $match === '' ? null : $match);

			return sprintf('Dodano %d %s%s.', $result['added'], Format::plural($result['added'], 'słowo', 'słowa', 'słów'), $result['skipped'] ? " (pominięto {$result['skipped']} – już w grupie)" : '');
		}, null, $request, "group-{$group}");
	}

	public function updateKeyword(int $project, int $plan, int $keyword, Request $request)
	{
		$action = (string) $request->input('action', '');

		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => match ($action) {
			'move' => $this->planner->moveKeyword($p, $plan, $keyword, (int) $request->input('plan_ad_group_id')),
			'match' => $this->planner->setMatchType($p, $plan, $keyword, (string) $request->input('match_type', '')),
			'delete' => $this->planner->removeKeyword($p, $plan, $keyword),
			default => throw new ValidationFailed(['action' => 'Nieznana operacja.']),
		}, ['move' => 'Przeniesiono słowo.', 'match' => 'Zmieniono dopasowanie.', 'delete' => 'Usunięto słowo z planu.'][$action] ?? 'Zapisano.', $request, 'group-' . (int) $request->input('anchor_group'));
	}

	public function addNegatives(int $project, int $plan, Request $request)
	{
		$phrases = [...(preg_split('/[\r\n]+/u', (string) $request->input('phrases', '')) ?: []), ...array_map('strval', array_filter((array) $request->input('suggested', []), 'is_string'))];
		$level = (string) $request->input('level', 'plan');
		$scope = $level === 'campaign' ? (int) $request->input('plan_campaign_id') : ($level === 'ad_group' ? (int) $request->input('plan_ad_group_id') : null);

		return $this->act($project, $plan, Ability::EditPlans, function ($p) use ($plan, $level, $scope, $phrases, $request) {
			$result = $this->planner->addNegatives($p, $plan, $level, $scope, $phrases, (string) $request->input('match_type', 'phrase'), $request->input('suggested') ? 'negative_suggestions' : 'manual');
			$message = sprintf('Dodano %d %s.', $result['added'], Format::plural($result['added'], 'wykluczenie', 'wykluczenia', 'wykluczeń'));

			if ($result['conflicts'] !== []) {
				return [$message . ' Uwaga: ' . $result['conflicts'][0]['message'], 'error'];
			}

			return $message;
		}, null, $request, 'negatives');
	}

	public function deleteNegative(int $project, int $plan, int $negative, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, fn ($p) => $this->planner->removeNegative($p, $plan, $negative), 'Usunięto wykluczenie.', $request, 'negatives');
	}

	public function saveAd(int $project, int $plan, int $group, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, function ($p) use ($plan, $group, $request) {
			$result = $this->planner->saveAd($p, $plan, $group, $request->only(['headlines', 'descriptions', 'path1', 'path2', 'final_url', 'cta', 'notes']));

			return $result['warnings'] === [] ? 'Zapisano reklamę.' : ['Zapisano reklamę. Do poprawy: ' . implode(' ', $result['warnings']), 'error'];
		}, null, $request, "ad-{$group}");
	}

	public function suggestAd(int $project, int $plan, int $group, Request $request)
	{
		return $this->act($project, $plan, Ability::EditPlans, function ($p) use ($plan, $group) {
			$result = $this->planner->suggestAd($p, $plan, $group);

			return 'Utworzono propozycję reklamy z kontekstu biznesowego – sprawdź i popraw teksty.' . ($result['warnings'] !== [] ? ' ' . implode(' ', $result['warnings']) : '');
		}, null, $request, "ad-{$group}");
	}

	// --- Budżet ---------------------------------------------------------------------------------

	public function updateBudget(int $project, int $plan, Request $request)
	{
		return $this->act($project, $plan, Ability::ManageBudget, fn ($p) => $this->budgets->updateBudget($p, $plan, $request->input(), $this->access->userId()), 'Zapisano budżet i scenariusz.', $request);
	}

	public function campaignBudget(int $project, int $plan, int $campaign, Request $request)
	{
		return $this->act($project, $plan, Ability::ManageBudget, fn ($p) => $this->budgets->setCampaignBudget($p, $plan, $campaign, $request->input('budget')), 'Zapisano budżet kampanii.', $request);
	}

	public function applyBudget(int $project, int $plan, Request $request)
	{
		return $this->act($project, $plan, Ability::ManageBudget, fn ($p) => sprintf('Zastosowano proponowany podział dla %d kampanii.', $this->budgets->applyProposal($p, $plan, $this->access->userId(), $this->planner->measurement($p))), null, $request);
	}

	/**
	 * Wspólna obsługa akcji formularzy: uprawnienie, wykonanie, komunikat, powrót na właściwą zakładkę.
	 */
	private function act(int $project, int $plan, Ability $ability, Closure $action, ?string $message, Request $request, ?string $anchor = null)
	{
		$projectModel = $this->access->project($project, $ability);
		$tab = in_array($request->input('tab'), ['structure', 'budget', 'negatives', 'ads', 'history'], true) ? (string) $request->input('tab') : null;
		$url = AppUrl::project($projectModel->id, "planner/{$plan}", array_filter(['tab' => $tab])) . ($anchor ? '#' . $anchor : '');

		try {
			$result = $action($projectModel);
		} catch (ValidationFailed $e) {
			return $this->redirect($url, self::first($e), 'error');
		}

		if (is_array($result)) {
			return $this->redirect($url, $result[0], $result[1]);
		}

		return $this->redirect($url, is_string($result) ? $result : ($message ?? 'Zapisano.'));
	}

	private static function first(ValidationFailed $e): string
	{
		return (string) (array_values($e->errors)[0] ?? $e->getMessage());
	}
}
