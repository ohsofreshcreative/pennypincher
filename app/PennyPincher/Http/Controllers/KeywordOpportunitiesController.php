<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Keywords\KeywordStatus;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\CampaignPlanRepository;
use App\PennyPincher\Repositories\KeywordDecisionRepository;
use App\PennyPincher\Services\Analysis\KeywordDecisionService;
use App\PennyPincher\Support\Format;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\DataForSeoRepository;
use App\PennyPincher\Repositories\KeywordIdeaRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\KeywordOpportunityService;
use App\PennyPincher\Services\DataForSeo\DataForSeoService;
use App\PennyPincher\Support\AnalysisPeriodRequest;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Keyword Opportunities: nowe frazy (Opportunity Score), propozycje wykluczeń i panel DataForSEO.
 * Penny Pincher nie dodaje słów ani wykluczeń w Google Ads – wyłącznie je proponuje.
 * Wejście na stronę nie wykonuje żadnych zapytań do DataForSEO.
 */
class KeywordOpportunitiesController extends Controller
{
	public function __construct(
		AccessService $access,
		private AnalysisRepository $analyses,
		private MetricsRepository $metrics,
		private KeywordIdeaRepository $ideas,
		private DataForSeoRepository $tasks,
		private DataForSeoService $dataforseo,
		private ProjectRepository $projects,
		private AnalysisPeriodRequest $periods,
		private KeywordDecisionRepository $decisions,
		private KeywordDecisionService $keywordDecisions,
		private CampaignPlanRepository $plans,
		private \App\PennyPincher\Services\Changes\ChangeLinkService $changeLinks,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$period = $this->periods->resolve($request, $project);
		$tab = in_array($request->query('tab'), ['opportunities', 'clusters', 'negatives', 'dataforseo'], true) ? (string) $request->query('tab') : 'opportunities';
		$run = $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE, $period->range->fromString(), $period->range->toString());

		$filters = [
			'min_score' => ctype_digit((string) $request->query('min_score', '')) ? min(100, (int) $request->query('min_score')) : null,
			'source' => in_array($request->query('source'), ['search_terms', 'dataforseo'], true) ? (string) $request->query('source') : null,
			'sort' => in_array($request->query('sort'), ['score', 'volume', 'cpc', 'confidence'], true) ? (string) $request->query('sort') : 'score',
			'status' => array_key_exists((string) $request->query('status', ''), KeywordStatus::LABELS) ? (string) $request->query('status') : null,
			'cluster' => preg_match('/^[a-f0-9]{40}$/', (string) $request->query('cluster', '')) ? (string) $request->query('cluster') : null,
			'intent' => array_key_exists((string) $request->query('intent', ''), Text::INTENT_LABELS) ? (string) $request->query('intent') : null,
			'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
			'hide_rejected' => $request->query('status') === null,
		];

		$configured = $this->dataforseo->isConfigured();
		$canManage = Policy::allows($project->role, Ability::RunPaidAnalysis);
		$opportunities = $run && $tab === 'opportunities' ? $this->analyses->opportunitiesPage((int) $run['id'], $project->id, $filters, max(1, (int) $request->query('page', 1)), 50) : null;
		$clusters = $run && in_array($tab, ['clusters', 'opportunities'], true) ? $this->analyses->clusters((int) $run['id'], $project->id) : [];
		$memberStatuses = [];

		if ($tab === 'clusters' && $clusters !== []) {
			$decisions = $this->decisions->forPhrases($project->id, array_merge(...array_map(fn ($c) => $c['members'], $clusters)));

			foreach ($clusters as $cluster) {
				$counts = [];

				foreach ($cluster['members'] as $member) {
					$status = $decisions[$member]['status'] ?? KeywordStatus::NEW;
					$counts[$status] = ($counts[$status] ?? 0) + 1;
				}

				$memberStatuses[$cluster['cluster_key']] = $counts;
			}
		}

		$openId = ctype_digit((string) $request->query('open', '')) ? (int) $request->query('open') : null;

		return $this->render('app.pages.keywords', [
			'project' => $project,
			'period' => $period,
			'tab' => $tab,
			'run' => $run,
			'filters' => $filters,
			'opportunities' => $opportunities,
			'clusters' => $clusters,
			'clusterNames' => array_column($clusters, 'name', 'cluster_key'),
			'memberStatuses' => $memberStatuses,
			'statusCounts' => $this->decisions->counts($project->id),
			'decisionEvents' => $openId ? $this->decisions->events($project->id, $openId) : [],
			'openDecision' => $openId,
			'negatives' => $run && $tab === 'negatives' ? $this->analyses->negativeSuggestions((int) $run['id']) : [],
			'adGroups' => $this->metrics->adGroups($project->id),
			'campaignOptions' => $this->metrics->campaignOptions($project->id),
			'plans' => $this->plans->openOptions($project->id),
			'dataforseo' => [
				'configured' => $configured,
				'usage' => $this->dataforseo->usage($project),
				// Plan (frazy źródłowe, szacunek) liczony z bazy tylko na zakładce DataForSEO – bez zapytań do API.
				'plan' => $tab === 'dataforseo' ? $this->dataforseo->plan($project, $period) : null,
				'tasks' => $this->tasks->forProject($project->id, 10),
				'ideas' => $this->ideas->count($project->id),
				'last_fetched' => $this->ideas->lastFetchedAt($project->id),
			],
			'canRun' => Policy::allows($project->role, Ability::ImportData),
			'canDecide' => Policy::allows($project->role, Ability::ManageKeywords),
			'canPlan' => Policy::allows($project->role, Ability::EditPlans),
			'canManage' => $canManage,
			'canPrepareChanges' => $tab === 'negatives' && $this->changeLinks->canPrepare($project),
			'pageTitle' => 'Keyword Opportunities',
			'section' => 'keywords',
		]);
	}

	/**
	 * Zmiana statusu zaznaczonych fraz (zaakceptuj, odrzuć, przywróć, do sprawdzenia).
	 */
	public function updateStatus(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::ManageKeywords);
		$back = $this->back($project, $request);
		$phrases = array_filter(array_map('strval', (array) $request->input('phrases', [])), fn ($p) => is_string($p) && $p !== '');

		if ($phrases === []) {
			return $this->redirect($back, 'Zaznacz co najmniej jedną frazę.', 'error');
		}

		try {
			$changed = $this->keywordDecisions->setStatus($project, $phrases, (string) $request->input('status', ''), $this->access->userId(), $request->input('note'));
		} catch (ValidationFailed $e) {
			return $this->redirect($back, array_values($e->errors)[0] ?? $e->getMessage(), 'error');
		}

		return $this->redirect($back, sprintf('Zmieniono status %d %s na „%s”.', $changed, Format::plural($changed, 'frazy', 'fraz', 'fraz'), KeywordStatus::LABELS[(string) $request->input('status')]));
	}

	public function updateClusterStatus(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::ManageKeywords);
		$back = $this->back($project, $request);
		$run = $this->analyses->findRun($project->id, (int) $request->input('run'));

		if ($run === null || $run['type'] !== KeywordOpportunityService::TYPE) {
			return $this->redirect($back, 'Nie znaleziono analizy.', 'error');
		}

		try {
			$changed = $this->keywordDecisions->setClusterStatus($project, (int) $run['id'], (string) $request->input('cluster', ''), (string) $request->input('status', ''), $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect($back, array_values($e->errors)[0] ?? $e->getMessage(), 'error');
		}

		return $this->redirect($back, sprintf('Zmieniono status %d %s w klastrze.', $changed, Format::plural($changed, 'frazy', 'fraz', 'fraz')));
	}

	/**
	 * Edycja fraz źródłowych DataForSEO: zapis własnego zestawu, przywrócenie automatycznych, wczytanie ostatnio użytego.
	 */
	public function updateSeeds(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::RunPaidAnalysis);
		$period = $this->periods->resolve($request, $project);
		$back = AppUrl::project($project->id, 'keywords', ['tab' => 'dataforseo'] + AnalysisPeriodRequest::query($period));

		try {
			switch ((string) $request->input('action', 'save')) {
				case 'reset':
					$this->dataforseo->resetSeeds($project);

					return $this->redirect($back, 'Przywrócono automatyczne propozycje fraz źródłowych.');
				case 'last_used':
					$this->dataforseo->restoreLastUsed($project, $this->access->userId());

					return $this->redirect($back, 'Wczytano ostatnio użyty zestaw fraz.');
				default:
					$keep = array_map('strval', array_filter((array) $request->input('seeds', []), 'is_string'));
					$added = preg_split('/[\r\n]+/u', (string) $request->input('new_seeds', '')) ?: [];
					$auto = array_map('strval', array_filter((array) $request->input('auto', []), 'is_string'));
					$saved = $this->dataforseo->saveSeeds($project, [...$keep, ...$added], $auto, $this->access->userId());

					return $this->redirect($back, sprintf('Zapisano zestaw %d %s źródłowych.', count($saved), Format::plural(count($saved), 'frazy', 'fraz', 'fraz')));
			}
		} catch (ValidationFailed $e) {
			return $this->redirect($back, array_values($e->errors)[0] ?? $e->getMessage(), 'error');
		}
	}

	private function back($project, Request $request): string
	{
		parse_str((string) $request->input('back', ''), $query);
		$query = array_intersect_key(array_filter($query, 'is_scalar'), array_flip(['tab', 'status', 'cluster', 'intent', 'q', 'min_score', 'source', 'sort', 'page', 'period', 'from', 'to']));

		return AppUrl::project($project->id, 'keywords', $query);
	}

	public function testDataForSeo(int $project)
	{
		$project = $this->access->project($project, Ability::RunPaidAnalysis);
		$result = $this->dataforseo->testConnection();
		$message = $result['ok']
			? $result['message'] . ' Saldo: ' . ($result['balance'] === null ? 'brak danych' : DataForSeoService::usd($result['balance']) . ' USD') . ($result['sandbox'] ? ' (sandbox)' : '') . '.'
			: $result['message'];

		return $this->redirect(AppUrl::project($project->id, 'keywords', ['tab' => 'dataforseo']), $message, $result['ok'] ? 'success' : 'error');
	}

	public function requestDataForSeo(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::RunPaidAnalysis);
		$period = $this->periods->resolve($request, $project);
		$back = AppUrl::project($project->id, 'keywords', ['tab' => 'dataforseo'] + AnalysisPeriodRequest::query($period));

		try {
			$task = $this->dataforseo->request($project, $period, $this->access->userId(), $request->input('consent') === '1', $request->input('estimate'));
		} catch (ValidationFailed $e) {
			return $this->redirect($back, array_values($e->errors)[0] ?? $e->getMessage(), 'error');
		}

		return $this->redirect($back, sprintf('Zadanie DataForSEO #%d jest w kolejce. Wykona je proces w tle (pp:sync:work) – wyniki pojawią się po jego zakończeniu.', $task['id']));
	}

	public function updateLimit(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::RunPaidAnalysis);
		$raw = str_replace([' ', ','], ['', '.'], trim((string) $request->input('limit', '')));
		$back = AppUrl::project($project->id, 'keywords', ['tab' => 'dataforseo']);

		if ($raw !== '' && (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 10000)) {
			return $this->redirect($back, 'Limit musi być liczbą od 0 do 10 000 USD (puste = płatne zapytania zablokowane).', 'error');
		}

		$this->projects->update($project->id, ['dataforseo_monthly_limit' => $raw === '' ? null : round((float) $raw, 2)]);

		return $this->redirect($back, 'Zapisano miesięczny limit wydatków na DataForSEO.');
	}
}
