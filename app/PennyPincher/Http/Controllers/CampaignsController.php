<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\CampaignPlanRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\BudgetLeakService;
use App\PennyPincher\Support\DateRangeRequest;
use Illuminate\Http\Request;

class CampaignsController extends Controller
{
	private const STATUSES = ['enabled', 'paused', 'removed', 'ended', 'other', 'unknown'];

	public function __construct(
		AccessService $access,
		private MetricsRepository $metrics,
		private DateRangeRequest $ranges,
		private AnalysisRepository $analyses,
		private RecommendationRepository $recommendations,
		private CampaignPlanRepository $plans,
		private GoogleAdsConnectionRepository $connections,
		private \App\PennyPincher\Infrastructure\Database\Db $db,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$range = $this->ranges->resolve($request, $project);
		$resolved = $this->metrics->resolve('campaign_metrics', $project->id, $range);

		$filters = [
			'q' => trim((string) $request->query('q', '')),
			'status' => in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '',
		];
		$sort = array_key_exists((string) $request->query('sort'), MetricsRepository::CAMPAIGN_SORTS) ? (string) $request->query('sort') : 'spend';
		$direction = $request->query('dir') === 'asc' ? 'asc' : 'desc';

		$campaigns = $this->metrics->campaigns($project->id, $resolved, $filters, $sort, $direction, max(1, (int) $request->query('page', 1)), 25);

		// Zestawy Change Center w toku dla kampanii z listy (stan na liście pochodzi z synchronizacji, więc zmiana
		// w toku jest pokazywana osobno – do czasu wdrożenia i następnej synchronizacji).
		$pending = [];
		$ids = array_map(fn ($row) => (int) $row['id'], $campaigns->items);
		if ($ids !== []) {
			$active = [ChangeStatus::REVIEW, ChangeStatus::APPROVED, ChangeStatus::VALIDATING, ChangeStatus::READY, ChangeStatus::DEPLOYING, ChangeStatus::RECONCILE, ChangeStatus::FAILED];
			$rows = $this->db->select(
				'SELECT c.id AS campaign_id, s.id AS set_id, s.status FROM ' . $this->db->table('change_operations') . ' o'
				. ' JOIN ' . $this->db->table('change_sets') . ' s ON s.id = o.change_set_id AND s.project_id = o.project_id'
				. ' JOIN ' . $this->db->table('campaigns') . ' c ON c.project_id = o.project_id AND c.external_id IS NOT NULL AND o.resource_name = CONCAT(?, s.customer_id, ?, c.external_id)'
				. ' WHERE o.project_id = ? AND o.resource_type = ? AND s.status IN (' . implode(',', array_fill(0, count($active), '?')) . ') AND c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.id DESC',
				['customers/', '/campaigns/', $project->id, 'campaign', ...$active, ...$ids]
			);
			foreach ($rows as $r) {
				$pending[(int) $r['campaign_id']] ??= ['set' => (int) $r['set_id'], 'status' => (string) $r['status']];
			}
		}

		return $this->render('app.pages.campaigns.index', [
			'pendingChanges' => $pending,
			'canSync' => ($c = $this->connections->currentForProject($project->id)) !== null && $c['status'] === GoogleAdsConnectionRepository::STATUS_ACTIVE && \App\PennyPincher\Domain\Access\Policy::allows($project->role, \App\PennyPincher\Domain\Access\Ability::UpdateProject),
			'lastSync' => $this->db->scalar('SELECT MAX(finished_at) FROM ' . $this->db->table('sync_runs') . ' WHERE project_id = ? AND status = ?', [$project->id, 'completed']),
			'project' => $project,
			'range' => $range,
			'resolved' => $resolved,
			'campaigns' => $campaigns,
			'filters' => $filters,
			'sort' => $sort,
			'direction' => $direction,
			'pageTitle' => 'Kampanie',
			'section' => 'campaigns',
		]);
	}

	public function show(int $project, int $campaign, Request $request)
	{
		$project = $this->access->project($project);
		$campaignRow = $this->metrics->findCampaign($project->id, $campaign);

		if ($campaignRow === null) {
			throw new NotFound('Kampania nie istnieje w tym projekcie.');
		}

		$range = $this->ranges->resolve($request, $project);
		$resolved = $this->metrics->resolve('campaign_metrics', $project->id, $range);
		$searchResolved = $this->metrics->resolve('search_term_metrics', $project->id, $range);

		return $this->render('app.pages.campaigns.show', [
			'project' => $project,
			'range' => $range,
			'resolved' => $resolved,
			'campaign' => $campaignRow,
			'totals' => $this->metrics->campaignTotals($project->id, $resolved, $campaign),
			'series' => $resolved->hasDaily() ? $this->metrics->dailySeries($project->id, $resolved, $range, $campaign) : [],
			'sources' => $this->metrics->campaignMetricSources($project->id, $campaign),
			'searchTerms' => $this->metrics->searchTerms($project->id, $searchResolved, ['campaign_id' => $campaign], 'spend', 'desc', 1, 10),
			'searchResolved' => $searchResolved,
			'pageTitle' => $campaignRow['name'],
			'section' => 'campaigns',
		]);
	}

	/**
	 * Zakładka „Optymalizacja” kampanii (pp2_campaigns): wycieki z ostatniej analizy i rekomendacje dla tej kampanii.
	 * Tylko odczyt – statusy zmienia się w modułach Budget Leaks i Rekomendacje.
	 */
	public function optimization(int $project, int $campaign)
	{
		$project = $this->access->project($project);
		$campaignRow = $this->campaign($project->id, $campaign);
		$leakRun = $this->analyses->latestRun($project->id, BudgetLeakService::TYPE);

		return $this->render('app.pages.campaigns.optimization', [
			'project' => $project,
			'campaign' => $campaignRow,
			'leakRun' => $leakRun,
			'leaks' => $leakRun ? array_values(array_filter($this->analyses->leaks((int) $leakRun['id'], ['campaign_id' => $campaign]), fn ($l) => $l['status'] !== 'dismissed')) : [],
			'recommendations' => $this->recommendations->paginate($project->id, ['campaign_id' => $campaign], 1, 10),
			'pageTitle' => $campaignRow['name'],
			'section' => 'campaigns',
		]);
	}

	/**
	 * Zakładka „Planowanie” kampanii: zarządzanie kampanią w Google Ads (przez Change Center) i plany w aplikacji.
	 */
	public function planning(int $project, int $campaign)
	{
		$project = $this->access->project($project);
		$campaignRow = $this->campaign($project->id, $campaign);

		return $this->render('app.pages.campaigns.planning', [
			'project' => $project,
			'campaign' => $campaignRow,
			'connection' => $this->connections->currentForProject($project->id),
			'plans' => $this->plans->paginate($project->id, null, 1),
			'pageTitle' => $campaignRow['name'],
			'section' => 'campaigns',
		]);
	}

	private function campaign(int $projectId, int $campaign): array
	{
		return $this->metrics->findCampaign($projectId, $campaign) ?? throw new NotFound('Kampania nie istnieje w tym projekcie.');
	}
}
