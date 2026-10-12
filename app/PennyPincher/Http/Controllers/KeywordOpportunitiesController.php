<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Support\ValidationFailed;
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
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$period = $this->periods->resolve($request, $project);
		$tab = in_array($request->query('tab'), ['opportunities', 'negatives', 'dataforseo'], true) ? (string) $request->query('tab') : 'opportunities';
		$run = $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE, $period->range->fromString(), $period->range->toString());

		$filters = [
			'min_score' => ctype_digit((string) $request->query('min_score', '')) ? min(100, (int) $request->query('min_score')) : null,
			'source' => in_array($request->query('source'), ['search_terms', 'dataforseo'], true) ? (string) $request->query('source') : null,
			'sort' => in_array($request->query('sort'), ['score', 'volume', 'cpc'], true) ? (string) $request->query('sort') : 'score',
		];

		$configured = $this->dataforseo->isConfigured();
		$canManage = Policy::allows($project->role, Ability::UpdateProject);

		return $this->render('app.pages.keywords', [
			'project' => $project,
			'period' => $period,
			'tab' => $tab,
			'run' => $run,
			'filters' => $filters,
			'opportunities' => $run ? $this->analyses->opportunities((int) $run['id'], $filters) : [],
			'negatives' => $run ? $this->analyses->negativeSuggestions((int) $run['id']) : [],
			'adGroups' => $this->metrics->adGroups($project->id),
			'campaignOptions' => $this->metrics->campaignOptions($project->id),
			'dataforseo' => [
				'configured' => $configured,
				'usage' => $this->dataforseo->usage($project),
				// Plan (frazy źródłowe, szacunek) liczony z bazy tylko na zakładce DataForSEO – bez zapytań do API.
				'plan' => $configured && $tab === 'dataforseo' ? $this->dataforseo->plan($project, $period) : null,
				'tasks' => $this->tasks->forProject($project->id, 10),
				'ideas' => $this->ideas->count($project->id),
				'last_fetched' => $this->ideas->lastFetchedAt($project->id),
			],
			'canRun' => Policy::allows($project->role, Ability::ImportData),
			'canManage' => $canManage,
			'pageTitle' => 'Keyword Opportunities',
			'section' => 'keywords',
		]);
	}

	public function testDataForSeo(int $project)
	{
		$project = $this->access->project($project, Ability::UpdateProject);
		$result = $this->dataforseo->testConnection();
		$message = $result['ok']
			? $result['message'] . ' Saldo: ' . ($result['balance'] === null ? 'brak danych' : DataForSeoService::usd($result['balance']) . ' USD') . ($result['sandbox'] ? ' (sandbox)' : '') . '.'
			: $result['message'];

		return $this->redirect(AppUrl::project($project->id, 'keywords', ['tab' => 'dataforseo']), $message, $result['ok'] ? 'success' : 'error');
	}

	public function requestDataForSeo(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::UpdateProject);
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
		$project = $this->access->project($project, Ability::UpdateProject);
		$raw = str_replace([' ', ','], ['', '.'], trim((string) $request->input('limit', '')));
		$back = AppUrl::project($project->id, 'keywords', ['tab' => 'dataforseo']);

		if ($raw !== '' && (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 10000)) {
			return $this->redirect($back, 'Limit musi być liczbą od 0 do 10 000 USD (puste = płatne zapytania zablokowane).', 'error');
		}

		$this->projects->update($project->id, ['dataforseo_monthly_limit' => $raw === '' ? null : round((float) $raw, 2)]);

		return $this->redirect($back, 'Zapisano miesięczny limit wydatków na DataForSEO.');
	}
}
