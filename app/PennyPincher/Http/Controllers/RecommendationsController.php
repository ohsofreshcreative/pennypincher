<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Analysis\Statistics;
use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\RecommendationService;
use App\PennyPincher\Support\AnalysisPeriodRequest;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Rekomendacje: lista z filtrami i sortowaniem, statusy (Nowa → Wykonana), notatki, historia zmian.
 * „Wykonana” oznacza ręczne potwierdzenie użytkownika – Penny Pincher niczego nie zmienia w Google Ads.
 */
class RecommendationsController extends Controller
{
	public function __construct(
		AccessService $access,
		private RecommendationRepository $recommendations,
		private RecommendationService $service,
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
		$only = $request->query('scope') === 'period';

		$filters = [
			'status' => array_key_exists((string) $request->query('status', ''), RecommendationGenerator::STATUSES) ? (string) $request->query('status') : null,
			'priority' => in_array($request->query('priority'), ['high', 'medium', 'low'], true) ? (string) $request->query('priority') : null,
			'source' => array_key_exists((string) $request->query('source', ''), RecommendationGenerator::SOURCES) ? (string) $request->query('source') : null,
			'confidence' => array_key_exists((string) $request->query('confidence', ''), Statistics::CONFIDENCE_LABELS) ? (string) $request->query('confidence') : null,
			'campaign_id' => ctype_digit((string) $request->query('campaign', '')) ? (int) $request->query('campaign') : null,
			'obsolete' => in_array($request->query('obsolete'), ['active', 'obsolete', 'all'], true) ? (string) $request->query('obsolete') : 'active',
			'sort' => array_key_exists((string) $request->query('sort', ''), RecommendationRepository::SORTS) ? (string) $request->query('sort') : 'priority',
			'from' => $only ? $period->range->fromString() : null,
			'to' => $only ? $period->range->toString() : null,
		];

		$open = ctype_digit((string) $request->query('open', '')) ? (int) $request->query('open') : null;

		return $this->render('app.pages.recommendations', [
			'project' => $project,
			'period' => $period,
			'filters' => $filters,
			'scope' => $only ? 'period' : 'all',
			'recommendations' => $this->recommendations->paginate($project->id, $filters, max(1, (int) $request->query('page', 1)), 25),
			'counts' => $this->recommendations->counts($project->id),
			'events' => $open ? $this->recommendations->events($project->id, $open) : [],
			'open' => $open,
			'campaignOptions' => $this->metrics->campaignOptions($project->id),
			'canEdit' => Policy::allows($project->role, Ability::ImportData),
			'pageTitle' => 'Rekomendacje',
			'section' => 'recommendations',
		]);
	}

	public function updateStatus(int $project, int $recommendation, Request $request)
	{
		return $this->act($project, $recommendation, fn ($p, $userId) => $this->service->updateStatus($p, $recommendation, (string) $request->input('status', ''), $userId, $request->input('note')), $request);
	}

	public function updateNote(int $project, int $recommendation, Request $request)
	{
		return $this->act($project, $recommendation, fn ($p, $userId) => $this->service->updateNote($p, $recommendation, $request->input('note'), $userId), $request);
	}

	public function obsolete(int $project, int $recommendation, Request $request)
	{
		return $this->act($project, $recommendation, fn ($p, $userId) => $this->service->markObsolete($p, $recommendation, (bool) $request->input('obsolete', true), $userId), $request);
	}

	private function act(int $project, int $recommendation, \Closure $action, Request $request)
	{
		$project = $this->access->project($project, Ability::ImportData);
		// Powrót do tej samej listy (filtry, strona) – tylko znane parametry, bez przekierowań poza aplikację.
		parse_str((string) $request->input('back', ''), $back);
		$query = array_intersect_key(array_filter($back, 'is_scalar'), array_flip(['status', 'priority', 'source', 'confidence', 'campaign', 'obsolete', 'sort', 'scope', 'page', 'period', 'from', 'to']));

		try {
			$action($project, $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($project->id, 'recommendations'), array_values($e->errors)[0] ?? $e->getMessage(), 'error');
		}

		return redirect()->to(AppUrl::project($project->id, 'recommendations', $query + ['open' => $recommendation]) . '#rec-' . $recommendation);
	}
}
