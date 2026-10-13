<?php

namespace App\PennyPincher\Services\Changes;

use App\PennyPincher\Domain\Conversions\ConversionFieldPolicy;
use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Conversions\ConversionHealthCheck;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\GoogleAds\Changes\StateQueries;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\ConversionActionRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\GoogleAds\StateReader;
use App\PennyPincher\Services\Planner\CampaignPlanService;

/**
 * Conversion Management: konfiguracja konwersji w Google Ads (z synchronizacji + odczyt na żądanie: pola
 * niesynchronizowane, cele konta i kampanii) obok wewnętrznej klasyfikacji Penny Pincher, oraz Conversion Health Check.
 * Klasyfikacja Penny Pincher nigdy nie zmienia Google Ads; zmiany konfiguracji Google Ads idą przez Change Center.
 */
class ConversionManagementService
{
	public const SNAPSHOT_ACTIONS = 'conversion_actions';

	public const SNAPSHOT_GOALS = 'conversion_goals';

	public function __construct(
		private Db $db,
		private ConversionActionRepository $actions,
		private MetricsRepository $metrics,
		private GoogleAdsConnectionRepository $connections,
		private ChangeSetRepository $sets,
		private StateReader $reader,
		private CampaignPlanService $planner,
	) {
	}

	/**
	 * Odczyt konfiguracji konwersji i celów z Google Ads (tylko GAQL) do pamięci podręcznej widoku.
	 */
	public function readFromGoogle(Project $project): void
	{
		$connection = $this->connections->currentForProject($project->id);

		if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
			throw new ValidationFailed(['connection' => 'Połącz projekt z kontem Google Ads, aby odczytać konfigurację konwersji.']);
		}

		try {
			$actions = $this->reader->list($connection, 'conversion_action', StateQueries::conversionAction());
			$customer = $this->reader->list($connection, 'customer_conversion_goal', StateQueries::customerGoals());
			$campaigns = $this->reader->list($connection, 'campaign_conversion_goal', StateQueries::campaignGoals());
		} catch (GoogleAdsApiException $e) {
			throw new ValidationFailed(['google_ads' => 'Nie udało się odczytać konwersji: ' . $e->userMessage()]);
		}

		$this->sets->saveSnapshot($project->id, (string) $connection['customer_id'], self::SNAPSHOT_ACTIONS, 'all', $actions);
		$this->sets->saveSnapshot($project->id, (string) $connection['customer_id'], self::SNAPSHOT_GOALS, 'all', ['customer' => $customer, 'campaigns' => $campaigns]);
	}

	/**
	 * Dane strony: działania (lokalne + Google Ads), cele, kampanie, Health Check.
	 */
	public function overview(Project $project): array
	{
		$connection = $this->connections->currentForProject($project->id);
		$customer = $connection !== null && $connection['status'] === GoogleAdsConnectionRepository::STATUS_ACTIVE ? (string) $connection['customer_id'] : null;
		$snapshot = $customer !== null ? $this->sets->snapshot($project->id, $customer, self::SNAPSHOT_ACTIONS, 'all') : null;
		$goals = $customer !== null ? $this->sets->snapshot($project->id, $customer, self::SNAPSHOT_GOALS, 'all') : null;
		$api = [];

		foreach ((array) ($snapshot['data'] ?? []) as $state) {
			$api[(string) $state['id']] = $state;
		}

		$period = AnalysisPeriod::fromPreset('last_90', $project->timezone());
		$resolved = $this->metrics->resolve('conversion_metrics', $project->id, $period->range);
		$counts = [];

		foreach ($resolved->isEmpty() ? [] : $this->metrics->conversionActions($project->id, $resolved) as $row) {
			$counts[(int) $row['id']] = ['conversions' => (float) $row['conversions'], 'all' => (float) $row['all_conversions']];
		}

		$byCampaign = $this->campaignConversions($project, $period->range->fromString(), $period->range->toString());
		$rows = [];

		foreach ($this->actions->forProject($project->id) as $action) {
			$state = $action['external_id'] !== null ? ($api[(string) $action['external_id']] ?? null) : null;
			$settings = json_decode((string) ($this->db->scalar('SELECT settings FROM ' . $this->db->table('conversion_actions') . ' WHERE id = ?', [$action['id']]) ?? ''), true) ?: [];
			$type = $state['type'] ?? $action['type'];
			// Stan do polityki pól: odczyt z Google Ads (typ, status, właściciel) albo dane z synchronizacji.
			$editableBase = $state !== null ? $state : array_filter(['type' => $type, 'status' => $action['status_raw'] ?? strtoupper((string) $action['status'])], fn ($v) => $v !== null && $v !== '');
			$canEditApi = $action['external_id'] !== null && $customer !== null;

			// Wartości z Google Ads (odczyt na żądanie) mają pierwszeństwo przed danymi z synchronizacji/importu.
			$rows[] = [
				'api' => $state,
				'category' => $state['category'] ?? $action['category_code'],
				'origin' => $state['origin'] ?? ($settings['origin'] ?? null),
				'status' => $state['status'] ?? ($action['status'] === 'removed' ? 'REMOVED' : 'ENABLED'),
				'primary_for_goal' => $state !== null ? $state['primary_for_goal'] : ($action['primary_for_goal'] === null ? null : (bool) $action['primary_for_goal']),
				'counting_type' => $state['counting_type'] ?? $action['counting_type'],
				'click_window' => $state['click_through_lookback_window_days'] ?? ($settings['click_through_lookback_window_days'] ?? null),
				'view_window' => $state['view_through_lookback_window_days'] ?? null,
				'default_value' => $state['default_value'] ?? ($settings['default_value'] ?? null),
				'always_use_default_value' => $state['always_use_default_value'] ?? ($settings['always_use_default_value'] ?? null),
				'attribution_model' => $state['attribution_model'] ?? null,
				'editable' => $canEditApi ? ConversionFieldPolicy::editableFields($editableBase) : [],
				'locked_reason' => match (true) {
					$action['external_id'] === null => 'Brak identyfikatora Google Ads (dane z importu) – zsynchronizuj konto przez API.',
					$customer === null => 'Połącz projekt z kontem Google Ads.',
					default => ConversionFieldPolicy::actionBlock($editableBase)['reason'] ?? null,
				},
				'field_policy' => $canEditApi ? array_combine(ConversionFieldPolicy::API_FIELDS, array_map(fn ($f) => ConversionFieldPolicy::field($editableBase, $f), ConversionFieldPolicy::API_FIELDS)) : [],
				'conversions_90' => $counts[$action['id']]['conversions'] ?? ($resolved->isEmpty() ? null : 0.0),
				'all_conversions_90' => $counts[$action['id']]['all'] ?? ($resolved->isEmpty() ? null : 0.0),
				'campaigns' => $byCampaign[$action['id']] ?? [],
			] + $action;
		}

		$context = $project->context();
		$leadBusiness = ! in_array($context->get('primary_goal'), ['sales'], true);
		$measurement = $this->planner->measurement($project);

		return [
			'actions' => $rows,
			'goals' => $goals['data'] ?? null,
			'goals_read_at' => $goals['read_at'] ?? null,
			'api_read_at' => $snapshot['read_at'] ?? null,
			'connected' => $customer !== null,
			'measurement' => $measurement,
			'issues' => ConversionHealthCheck::check($rows, ['customer' => $goals['data']['customer'] ?? null, 'campaigns' => $goals['data']['campaigns'] ?? null], $measurement, $leadBusiness),
		];
	}

	/**
	 * @return array<int, array<int, array{name: string, conversions: float}>> id działania => kampanie
	 */
	private function campaignConversions(Project $project, string $from, string $to): array
	{
		$rows = $this->db->select(
			'SELECT m.conversion_action_id, c.name, SUM(m.conversions) AS conversions FROM ' . $this->db->table('conversion_metrics') . ' m INNER JOIN ' . $this->db->table('campaigns') . ' c ON c.id = m.campaign_scope
			WHERE m.project_id = ? AND m.campaign_scope > 0 AND m.date_from BETWEEN ? AND ? GROUP BY m.conversion_action_id, c.name ORDER BY conversions DESC',
			[$project->id, $from, $to]
		);
		$out = [];

		foreach ($rows as $row) {
			$out[(int) $row['conversion_action_id']][] = ['name' => (string) $row['name'], 'conversions' => (float) $row['conversions']];
		}

		return $out;
	}
}
