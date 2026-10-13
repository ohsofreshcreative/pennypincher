<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\GoogleAds\Changes\ResourceName;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\GoogleAds\Changes\StateQueries;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Changes\ChangeProposalService;
use App\PennyPincher\Services\GoogleAds\StateReader;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use App\PennyPincher\Support\AppUrl;
use Closure;
use Illuminate\Http\Request;

/**
 * Google Ads Management: przegląd kampanii, grup, słów, wykluczeń i reklam (dane z synchronizacji + odczyt na żądanie)
 * i formularze przygotowania zmian. Każda akcja tworzy lub uzupełnia szkic w Change Center – nic nie trafia do
 * Google Ads bez przeglądu, zatwierdzenia i świadomego wdrożenia.
 */
class GoogleAdsManagementController extends Controller
{
	public function __construct(
		AccessService $access,
		private Db $db,
		private ChangeProposalService $proposals,
		private ChangeSetRepository $sets,
		private GoogleAdsConnectionRepository $connections,
		private OrganizationRepository $organizations,
		private MetricsRepository $metrics,
		private StateReader $reader,
		private WriteGuard $guard,
	) {
		parent::__construct($access);
	}

	public function index(int $project)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$connection = $this->connections->currentForProject($project->id);
		$period = AnalysisPeriod::fromPreset('last_30', $project->timezone());
		$resolved = $this->metrics->resolve('campaign_metrics', $project->id, $period->range);

		return $this->render('app.pages.management.index', $this->shared($project, $connection) + [
			'campaigns' => $this->db->select('SELECT c.*, (SELECT COUNT(*) FROM ' . $this->db->table('ad_groups') . ' g WHERE g.campaign_id = c.id) AS ad_group_count FROM ' . $this->db->table('campaigns') . " c WHERE c.project_id = ? ORDER BY c.status = 'removed', c.name", [$project->id]),
			'metrics' => $this->metrics->campaignMetricsByCampaign($project->id, $resolved),
			'period' => $period,
			'members' => Policy::allows($project->role, Ability::ManageWriteAccess) ? $this->organizations->members($project->organizationId) : [],
			'pageTitle' => 'Google Ads Management',
			'section' => 'management',
		]);
	}

	public function campaign(int $project, int $campaign)
	{
		$project = $this->access->project($project);
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? AND id = ?', [$project->id, $campaign]) ?? throw new NotFound('Kampania nie istnieje.');
		$connection = $this->connections->currentForProject($project->id);
		$customer = $connection['customer_id'] ?? null;
		$resource = $customer && $row['external_id'] ? ResourceName::campaign((string) $customer, (string) $row['external_id']) : null;
		$period = AnalysisPeriod::fromPreset('last_30', $project->timezone());

		return $this->render('app.pages.management.campaign', $this->shared($project, $connection) + [
			'campaign' => $row,
			'settings' => json_decode((string) ($row['settings'] ?? ''), true) ?: [],
			'resource' => $resource,
			'adGroups' => $this->db->select('SELECT g.*, (SELECT COUNT(*) FROM ' . $this->db->table('keywords') . ' k WHERE k.ad_group_id = g.id) AS keyword_count FROM ' . $this->db->table('ad_groups') . ' g WHERE g.project_id = ? AND g.campaign_id = ? ORDER BY g.name', [$project->id, $campaign]),
			'negatives' => $this->db->select('SELECT * FROM ' . $this->db->table('negative_keywords') . " WHERE project_id = ? AND campaign_id = ? AND level = 'campaign' ORDER BY text", [$project->id, $campaign]),
			'criteria' => $resource ? $this->sets->snapshot($project->id, (string) $customer, 'campaign_criteria', $resource) : null,
			'metrics' => $this->metrics->campaignMetricsByCampaign($project->id, $this->metrics->resolve('campaign_metrics', $project->id, $period->range))[$campaign] ?? null,
			'period' => $period,
			'pageTitle' => $row['name'],
			'section' => 'management',
		]);
	}

	public function adGroup(int $project, int $group)
	{
		$project = $this->access->project($project);
		$row = $this->db->first('SELECT g.*, c.name AS campaign_name, c.id AS campaign_local_id, c.channel_type FROM ' . $this->db->table('ad_groups') . ' g INNER JOIN ' . $this->db->table('campaigns') . ' c ON c.id = g.campaign_id WHERE g.project_id = ? AND g.id = ?', [$project->id, $group]) ?? throw new NotFound('Grupa reklam nie istnieje.');
		$connection = $this->connections->currentForProject($project->id);
		$customer = $connection['customer_id'] ?? null;
		$resource = $customer && $row['external_id'] ? ResourceName::adGroup((string) $customer, (string) $row['external_id']) : null;
		$period = AnalysisPeriod::fromPreset('last_90', $project->timezone());
		$stats = [];

		foreach ($this->metrics->keywordStats($project->id, $this->metrics->resolve('keyword_metrics', $project->id, $period->range)) as $stat) {
			$stats[$stat['id']] = $stat;
		}

		return $this->render('app.pages.management.ad-group', $this->shared($project, $connection) + [
			'group' => $row,
			'resource' => $resource,
			'keywords' => $this->db->select('SELECT * FROM ' . $this->db->table('keywords') . " WHERE project_id = ? AND ad_group_id = ? ORDER BY status = 'removed', text", [$project->id, $group]),
			'stats' => $stats,
			'negatives' => $this->db->select('SELECT * FROM ' . $this->db->table('negative_keywords') . " WHERE project_id = ? AND ad_group_id = ? AND level = 'ad_group' ORDER BY text", [$project->id, $group]),
			'ads' => $resource ? $this->sets->snapshot($project->id, (string) $customer, 'ads', $resource) : null,
			'criteria' => $resource ? $this->sets->snapshot($project->id, (string) $customer, 'ad_group_criteria', $resource) : null,
			'period' => $period,
			'pageTitle' => $row['name'],
			'section' => 'management',
		]);
	}

	/**
	 * Odczyt na żądanie (tylko GAQL): kryteria kampanii albo słowa/wykluczenia i reklamy grupy.
	 */
	public function read(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::PrepareChanges);
		$connection = $this->connections->currentForProject($project->id);
		$kind = (string) $request->input('kind', '');
		$id = (int) $request->input('id');
		$back = AppUrl::project($project->id, $kind === 'campaign' ? 'management/campaigns/' . $id : 'management/ad-groups/' . $id);

		try {
			if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
				throw new ValidationFailed(['connection' => 'Połącz projekt z kontem Google Ads, aby odczytać aktualny stan.']);
			}

			$customer = (string) $connection['customer_id'];

			if ($kind === 'campaign') {
				$campaign = $this->proposals->campaign($project, $id);
				$this->sets->saveSnapshot($project->id, $customer, 'campaign_criteria', $campaign['resource_name'], $this->reader->list($connection, 'campaign_criterion', StateQueries::campaignCriteria($campaign['resource_name'])));
			} else {
				$group = $this->proposals->adGroup($project, $id);
				$this->sets->saveSnapshot($project->id, $customer, 'ads', $group['resource_name'], $this->reader->list($connection, 'ad_group_ad', StateQueries::adGroupAds($group['resource_name'])));
				$this->sets->saveSnapshot($project->id, $customer, 'ad_group_criteria', $group['resource_name'], $this->reader->list($connection, 'ad_group_criterion', StateQueries::adGroupCriteria($group['resource_name'])));
			}
		} catch (ValidationFailed $e) {
			return $this->redirect($back, (string) array_values($e->errors)[0], 'error');
		} catch (GoogleAdsApiException $e) {
			return $this->redirect($back, 'Nie udało się odczytać danych z Google Ads: ' . $e->userMessage(), 'error');
		}

		return $this->redirect($back, 'Odczytano aktualny stan z Google Ads (tylko odczyt).');
	}

	public function campaignChange(int $project, int $campaign, Request $request)
	{
		return $this->prepare($project, $request, function (Project $p, int $userId, ?int $setId) use ($campaign, $request) {
			$action = (string) $request->input('action', '');

			return match ($action) {
				'pause' => $this->proposals->campaignUpdate($p, $userId, $campaign, ['status' => 'PAUSED'], $setId),
				'enable' => $this->proposals->campaignUpdate($p, $userId, $campaign, ['status' => 'ENABLED'], $setId),
				'rename' => $this->proposals->campaignUpdate($p, $userId, $campaign, ['name' => trim(wp_strip_all_tags((string) $request->input('name')))], $setId),
				'network' => $this->proposals->campaignUpdate($p, $userId, $campaign, ['target_search_network' => $request->input('search_partners') === '1', 'target_content_network' => $request->input('display') === '1'], $setId),
				'budget' => $this->proposals->campaignBudget($p, $userId, $campaign, (int) ChangeCenterController::micros($request->input('amount')), $request->input('shared_ack') === '1', $setId),
				'bidding' => $this->proposals->campaignBidding($p, $userId, $campaign, (string) $request->input('strategy'), ChangeCenterController::micros($request->input('target_cpa')), ChangeCenterController::micros($request->input('ceiling')), $setId),
				'location' => $this->proposals->campaignCriterionAdd($p, $userId, $campaign, 'location', ['geo_id' => $request->input('geo_id')], $setId),
				'language' => $this->proposals->campaignCriterionAdd($p, $userId, $campaign, 'language', ['language' => $request->input('language')], $setId),
				'negatives' => $this->proposals->campaignCriterionAdd($p, $userId, $campaign, 'negative_keyword', ['phrases' => ChangeProposalService::phrases($request->input('phrases')), 'match_type' => $request->input('match_type', 'PHRASE')], $setId),
				'remove_criterion' => $this->proposals->campaignCriterionRemove($p, $userId, $campaign, (string) $request->input('resource_name'), $setId),
				'ad_group' => $this->proposals->adGroupCreate($p, $userId, $campaign, ['name' => $request->input('name'), 'status' => $request->input('status'), 'cpc_bid_micros' => ChangeCenterController::micros($request->input('cpc'))], $setId),
				default => throw new ValidationFailed(['action' => 'Nieznana akcja.']),
			};
		});
	}

	public function adGroupChange(int $project, int $group, Request $request)
	{
		return $this->prepare($project, $request, function (Project $p, int $userId, ?int $setId) use ($group, $request) {
			$action = (string) $request->input('action', '');
			$keyword = (int) $request->input('keyword');

			return match ($action) {
				'pause' => $this->proposals->adGroupUpdate($p, $userId, $group, ['status' => 'PAUSED'], $setId),
				'enable' => $this->proposals->adGroupUpdate($p, $userId, $group, ['status' => 'ENABLED'], $setId),
				'rename' => $this->proposals->adGroupUpdate($p, $userId, $group, ['name' => trim(wp_strip_all_tags((string) $request->input('name')))], $setId),
				'cpc' => $this->proposals->adGroupUpdate($p, $userId, $group, ['cpc_bid_micros' => ChangeCenterController::micros($request->input('cpc'))], $setId),
				'keywords' => $this->proposals->keywordsAdd($p, $userId, $group, ChangeProposalService::phrases($request->input('phrases')), (string) $request->input('match_type', 'PHRASE'), (string) $request->input('status', 'ENABLED'), $setId),
				'keyword_pause' => $this->proposals->keywordStatus($p, $userId, $this->ownKeyword($p, $group, $keyword), 'PAUSED', $setId),
				'keyword_enable' => $this->proposals->keywordStatus($p, $userId, $this->ownKeyword($p, $group, $keyword), 'ENABLED', $setId),
				'keyword_remove' => $request->input('confirm_remove') === '1' ? $this->proposals->keywordRemove($p, $userId, $this->ownKeyword($p, $group, $keyword), $setId) : throw new ValidationFailed(['confirm' => 'Potwierdź usunięcie słowa kluczowego.']),
				'keyword_match' => $this->proposals->keywordReplaceMatch($p, $userId, $this->ownKeyword($p, $group, $keyword), (string) $request->input('match_type'), $setId),
				'negatives' => $this->proposals->adGroupNegativesAdd($p, $userId, $group, ChangeProposalService::phrases($request->input('phrases')), (string) $request->input('match_type', 'PHRASE'), $setId),
				'remove_criterion' => $this->proposals->criterionRemove($p, $userId, $group, (string) $request->input('resource_name'), $setId),
				'ad' => $this->proposals->adCreate($p, $userId, $group, ['headlines' => preg_split('/\r?\n/', (string) $request->input('headlines')) ?: [], 'descriptions' => preg_split('/\r?\n/', (string) $request->input('descriptions')) ?: [], 'final_url' => $request->input('final_url'), 'path1' => $request->input('path1'), 'path2' => $request->input('path2'), 'status' => $request->input('status')], $setId, $request->input('replace') ? (string) $request->input('replace') : null),
				'ad_pause' => $this->proposals->adStatus($p, $userId, $group, (string) $request->input('resource_name'), 'PAUSED', $setId),
				'ad_enable' => $this->proposals->adStatus($p, $userId, $group, (string) $request->input('resource_name'), 'ENABLED', $setId),
				default => throw new ValidationFailed(['action' => 'Nieznana akcja.']),
			};
		});
	}

	public function newCampaign(int $project, Request $request)
	{
		return $this->prepare($project, $request, fn (Project $p, int $userId, ?int $setId) => $this->proposals->newCampaign($p, $userId, [
			'name' => $request->input('name'),
			'budget_micros' => ChangeCenterController::micros($request->input('budget')),
			'bidding' => array_key_exists((string) $request->input('bidding'), OperationCatalog::BIDDING) ? (string) $request->input('bidding') : 'MANUAL_CPC',
			'target_cpa_micros' => ChangeCenterController::micros($request->input('target_cpa')),
			'geo_ids' => array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) $request->input('geo_ids', '')) ?: []))),
			'languages' => array_values(array_filter(array_map('strval', (array) $request->input('languages', [$p->context()->language()])))),
			'search_partners' => $request->input('search_partners') === '1',
			'presence_only' => $request->input('presence') !== 'interest',
			'eu_political' => in_array($request->input('eu_political'), ['no', 'yes'], true) ? (string) $request->input('eu_political') : null,
		], $setId));
	}

	/**
	 * Zgoda na zapis dla połączonego konta (właściciel). Działa tylko, gdy serwer ma tryb WRITE_ENABLED.
	 */
	public function writeAccess(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::ManageWriteAccess);
		$back = AppUrl::project($project->id, 'management') . '#write-access';
		$connection = $this->connections->currentForProject($project->id);
		$enable = $request->input('enabled') === '1';

		if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
			return $this->redirect($back, 'Brak aktywnego połączenia z Google Ads.', 'error');
		}

		if ($enable && ! $this->guard->serverMode()->canWrite()) {
			return $this->redirect($back, 'Serwer ma wyłączony zapis (PP_GOOGLE_ADS_WRITE_MODE ≠ write_enabled) – zgody nie można włączyć z poziomu aplikacji.', 'error');
		}

		if ($enable && trim((string) $request->input('confirm_customer')) !== (string) $connection['customer_id']) {
			return $this->redirect($back, 'Wpisz identyfikator konta Google Ads, aby potwierdzić włączenie zapisu.', 'error');
		}

		$this->connections->update((int) $connection['id'], ['write_enabled' => $enable ? 1 : 0, 'write_enabled_by' => $enable ? $this->access->userId() : null, 'write_enabled_at' => $enable ? gmdate('Y-m-d H:i:s') : null]);

		return $this->redirect($back, $enable ? 'Włączono zapis dla konta ' . $connection['customer_id'] . '. Wdrożenia nadal wymagają zatwierdzenia i świadomego uruchomienia przez wykonawcę.' : 'Wyłączono zapis dla tego konta.');
	}

	public function executor(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::ManageWriteAccess);
		$this->organizations->setExecutor($project->organizationId, (int) $request->input('user'), $request->input('enabled') === '1');

		return $this->redirect(AppUrl::project($project->id, 'management') . '#write-access', 'Zapisano uprawnienie „wykonawca zmian”.');
	}

	// --- Pomocnicze -----------------------------------------------------------------------------

	private function shared(Project $project, ?array $connection): array
	{
		return [
			'project' => $project,
			'connection' => $connection,
			'mode' => $this->guard->mode($connection),
			'serverMode' => $this->guard->serverMode(),
			'isExecutor' => $this->guard->isExecutor($project, $this->access->userId()),
			'canPrepare' => Policy::allows($project->role, Ability::PrepareChanges) && $this->guard->mode($connection)->canPrepare(),
			'canManageWrite' => Policy::allows($project->role, Ability::ManageWriteAccess),
			'drafts' => array_values(array_filter($this->sets->paginate($project->id, ['status' => ChangeStatus::DRAFT], 1, 20)->items, fn ($s) => (int) $s['created_by'] === $this->access->userId() || Policy::allows($project->role, Ability::ApproveChanges))),
		];
	}

	private function prepare(int $project, Request $request, Closure $action)
	{
		$projectModel = $this->access->project($project, Ability::PrepareChanges);
		$setId = ctype_digit((string) $request->input('change_set_id', '')) ? (int) $request->input('change_set_id') : null;

		try {
			$id = $this->proposals->atomically($projectModel, $this->access->userId(), fn () => $action($projectModel, $this->access->userId(), $setId));
		} catch (ValidationFailed $e) {
			return $this->redirect(wp_get_referer() ? AppUrl::safeRedirect((string) wp_get_referer()) : AppUrl::project($projectModel->id, 'management'), (string) (array_values($e->errors)[0] ?? $e->getMessage()), 'error');
		} catch (GoogleAdsApiException $e) {
			return $this->redirect(wp_get_referer() ? AppUrl::safeRedirect((string) wp_get_referer()) : AppUrl::project($projectModel->id, 'management'), 'Nie udało się odczytać danych z Google Ads: ' . $e->userMessage(), 'error');
		}

		return $this->redirect(AppUrl::project($projectModel->id, 'changes/' . $id), 'Dodano do szkicu zmian. Nic nie zostało wysłane do Google Ads – sprawdź podgląd, odczytaj aktualny stan i przekaż do zatwierdzenia.');
	}

	private function ownKeyword(Project $project, int $group, int $keyword): int
	{
		if ($this->db->scalar('SELECT 1 FROM ' . $this->db->table('keywords') . ' WHERE project_id = ? AND ad_group_id = ? AND id = ?', [$project->id, $group, $keyword]) === null) {
			throw new ValidationFailed(['keyword' => 'Słowo nie należy do tej grupy reklam.']);
		}

		return $keyword;
	}
}
