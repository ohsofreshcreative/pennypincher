<?php

namespace App\PennyPincher\Infrastructure\GoogleAds\Sync;

use App\PennyPincher\Domain\GoogleAds\Enums;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsClient;
use App\PennyPincher\Infrastructure\Import\Writers\ReportWriter;

/**
 * Wykonuje pojedynczy krok synchronizacji: pobiera dane GAQL i zapisuje je w tabelach aplikacji.
 *
 * Zasady zapisu:
 * - encje (kampanie, grupy, słowa, działania konwersji) są aktualizowane po identyfikatorach Google Ads;
 *   encja znana wcześniej tylko z importu (po nazwie) dostaje identyfikator zamiast tworzenia duplikatu,
 * - metryki z API mają `source = 'api'` i ziarnistość dzienną; dla okna dat krok zastępuje wyłącznie
 *   wcześniejsze wiersze API z tego okna (w transakcji), więc ponowienie jest idempotentne,
 *   a dane z importów pozostają nietknięte,
 * - po udanym kroku metryk zapisujemy pokrycie okna (`data_coverage`) – dni bez wierszy w API to dni bez aktywności.
 */
class ApiSyncer
{
	public const STRUCTURE_STEPS = ['campaigns', 'locations', 'ad_groups', 'keywords', 'conversion_actions'];

	public const METRIC_STEPS = [
		'campaign_metrics' => 'campaigns',
		'keyword_metrics' => 'keywords',
		'search_term_metrics' => 'search_terms',
		'conversion_metrics' => 'conversions',
	];

	public function __construct(private Db $db, private GoogleAdsClient $client)
	{
	}

	/**
	 * @param array{type: string, from?: string, to?: string} $step
	 * @return int liczba zapisanych rekordów
	 */
	public function run(array $connection, string $accessToken, int $syncRunId, array $step): int
	{
		$projectId = (int) $connection['project_id'];
		$query = fn (string $gaql) => $this->client->search($accessToken, (string) $connection['customer_id'], $gaql, $connection['login_customer_id'] ?: null);

		return match ($step['type']) {
			'campaigns' => $this->campaigns($projectId, $query(Gaql::campaigns())),
			'locations' => $this->locations($projectId, $query(Gaql::locations()), $query),
			'ad_groups' => $this->adGroups($projectId, $query(Gaql::adGroups())),
			'keywords' => $this->keywords($projectId, $query(Gaql::keywords())),
			'conversion_actions' => $this->conversionActions($projectId, $query(Gaql::conversionActions())),
			'campaign_metrics' => $this->campaignMetrics($projectId, $syncRunId, $step, $query(Gaql::campaignMetrics($step['from'], $step['to']))),
			'keyword_metrics' => $this->keywordMetrics($projectId, $syncRunId, $step, $query(Gaql::keywordMetrics($step['from'], $step['to']))),
			'search_term_metrics' => $this->searchTermMetrics($projectId, $syncRunId, $step, $query(Gaql::searchTermMetrics($step['from'], $step['to']))),
			'conversion_metrics' => $this->conversionMetrics($projectId, $syncRunId, $step, $query(Gaql::conversionMetrics($step['from'], $step['to']))),
			default => throw new \InvalidArgumentException("Nieznany krok synchronizacji: {$step['type']}"),
		};
	}

	private function campaigns(int $projectId, array $rows): int
	{
		$this->db->transaction(function () use ($projectId, $rows) {
			foreach ($rows as $row) {
				$campaign = $row['campaign'] ?? [];
				$budget = $row['campaignBudget'] ?? [];
				$network = $campaign['networkSettings'] ?? [];
				$geo = $campaign['geoTargetTypeSetting'] ?? [];

				$id = $this->campaignId($projectId, (string) $campaign['id'], (string) ($campaign['name'] ?? ''));
				$settings = $this->campaignSettings($id);

				$settings = array_merge($settings, array_filter([
					'channel_sub_type' => $campaign['advertisingChannelSubType'] ?? null,
					'serving_status' => $campaign['servingStatus'] ?? null,
					// v23+: pola z czasem („yyyy-MM-dd HH:mm:ss”) – zapisujemy dokładnie to, co zwraca API.
					'start_date_time' => $campaign['startDateTime'] ?? null,
					'end_date_time' => $campaign['endDateTime'] ?? null,
					'networks' => [
						'google_search' => (bool) ($network['targetGoogleSearch'] ?? false),
						'search_partners' => (bool) ($network['targetSearchNetwork'] ?? false),
						'display' => (bool) ($network['targetContentNetwork'] ?? false),
						'partner_search' => (bool) ($network['targetPartnerSearchNetwork'] ?? false),
					],
					'geo_target_type' => $geo['positiveGeoTargetType'] ?? null,
					'negative_geo_target_type' => $geo['negativeGeoTargetType'] ?? null,
					'target_cpa_micros' => $campaign['targetCpa']['targetCpaMicros'] ?? $campaign['maximizeConversions']['targetCpaMicros'] ?? null,
					'target_roas' => $campaign['targetRoas']['targetRoas'] ?? $campaign['maximizeConversionValue']['targetRoas'] ?? null,
					'budget_shared' => isset($budget['explicitlyShared']) ? (bool) $budget['explicitlyShared'] : null,
					'synced_at' => gmdate('c'),
				], fn ($value) => $value !== null));

				$this->db->update('campaigns', [
					'name' => (string) ($campaign['name'] ?? ''),
					'status' => Enums::status($campaign['status'] ?? null),
					'status_raw' => $campaign['status'] ?? null,
					'channel_type' => $campaign['advertisingChannelType'] ?? null,
					'budget_micros' => isset($budget['amountMicros']) ? (int) $budget['amountMicros'] : null,
					'budget_period' => $budget['period'] ?? null,
					'bidding_strategy' => $campaign['biddingStrategyType'] ?? null,
					'settings' => wp_json_encode($settings),
					'updated_at' => $this->db->now(),
				], ['id' => $id]);
			}
		});

		return count($rows);
	}

	private function locations(int $projectId, array $rows, callable $query): int
	{
		$geoNames = [];
		$resources = array_values(array_unique(array_filter(array_map(fn ($row) => $row['campaignCriterion']['location']['geoTargetConstant'] ?? null, $rows))));

		foreach (array_chunk($resources, 200) as $chunk) {
			foreach ($query(Gaql::geoTargets($chunk)) as $geo) {
				$constant = $geo['geoTargetConstant'] ?? [];
				$geoNames[$constant['resourceName'] ?? ''] = $constant['canonicalName'] ?? $constant['name'] ?? null;
			}
		}

		$byCampaign = [];

		foreach ($rows as $row) {
			$criterion = $row['campaignCriterion'] ?? [];
			$campaignId = (string) ($row['campaign']['id'] ?? '');

			$byCampaign[$campaignId][] = array_filter([
				'type' => $criterion['type'] ?? null,
				'negative' => (bool) ($criterion['negative'] ?? false),
				'name' => isset($criterion['location']['geoTargetConstant']) ? ($geoNames[$criterion['location']['geoTargetConstant']] ?? $criterion['location']['geoTargetConstant']) : null,
				'radius' => $criterion['proximity']['radius'] ?? null,
				'radius_units' => $criterion['proximity']['radiusUnits'] ?? null,
			], fn ($value) => $value !== null);
		}

		$this->db->transaction(function () use ($projectId, $byCampaign) {
			$campaigns = $this->db->select('SELECT id, external_id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? AND external_id IS NOT NULL', [$projectId]);

			foreach ($campaigns as $campaign) {
				$settings = $this->campaignSettings((int) $campaign['id']);
				// Brak kryteriów lokalizacji = kampania kierowana na wszystkie kraje i regiony.
				$settings['locations'] = $byCampaign[$campaign['external_id']] ?? [];
				$settings['locations_synced'] = true;
				$this->db->update('campaigns', ['settings' => wp_json_encode($settings)], ['id' => (int) $campaign['id']]);
			}
		});

		return count($rows);
	}

	private function adGroups(int $projectId, array $rows): int
	{
		$campaignIds = $this->campaignIdMap($projectId);
		$now = $this->db->now();
		$records = [];

		foreach ($rows as $row) {
			$campaignId = $campaignIds[(string) ($row['campaign']['id'] ?? '')] ?? null;

			if ($campaignId === null) {
				continue;
			}

			$records[] = [
				'project_id' => $projectId,
				'campaign_id' => $campaignId,
				'natural_key' => ReportWriter::key('id', (string) $row['adGroup']['id']),
				'external_id' => (string) $row['adGroup']['id'],
				'name' => (string) ($row['adGroup']['name'] ?? ''),
				'status' => Enums::status($row['adGroup']['status'] ?? null),
				'created_at' => $now,
				'updated_at' => $now,
			];
		}

		$this->db->transaction(fn () => $this->db->insertMany('ad_groups', $records, 300,
			'ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), campaign_id = VALUES(campaign_id), external_id = VALUES(external_id), updated_at = VALUES(updated_at)'));

		return count($records);
	}

	private function keywords(int $projectId, array $rows): int
	{
		$campaignIds = $this->campaignIdMap($projectId);
		$adGroupIds = $this->adGroupIdMap($projectId);
		$now = $this->db->now();
		$records = [];

		foreach ($rows as $row) {
			$criterion = $row['adGroupCriterion'] ?? [];
			$adGroupId = $adGroupIds[(string) ($row['adGroup']['id'] ?? '')] ?? null;
			$campaignId = $campaignIds[(string) ($row['campaign']['id'] ?? '')] ?? null;

			if ($campaignId === null || $adGroupId === null || empty($criterion['criterionId'])) {
				continue;
			}

			$records[] = [
				'project_id' => $projectId,
				'campaign_id' => $campaignId,
				'ad_group_id' => $adGroupId,
				'natural_key' => ReportWriter::key('id', $adGroupId . '|' . $criterion['criterionId']),
				'external_id' => (string) $criterion['criterionId'],
				'text' => (string) ($criterion['keyword']['text'] ?? ''),
				'match_type' => Enums::keywordMatchType($criterion['keyword']['matchType'] ?? null),
				'status' => Enums::status($criterion['status'] ?? null),
				'status_raw' => $criterion['status'] ?? null,
				'quality_score' => isset($criterion['qualityInfo']['qualityScore']) ? (int) $criterion['qualityInfo']['qualityScore'] : null,
				'max_cpc_micros' => isset($criterion['effectiveCpcBidMicros']) ? (int) $criterion['effectiveCpcBidMicros'] : null,
				'created_at' => $now,
				'updated_at' => $now,
			];
		}

		$this->db->transaction(fn () => $this->db->insertMany('keywords', $records, 300,
			'ON DUPLICATE KEY UPDATE text = VALUES(text), match_type = VALUES(match_type), status = VALUES(status), status_raw = VALUES(status_raw),
			quality_score = VALUES(quality_score), max_cpc_micros = VALUES(max_cpc_micros), external_id = VALUES(external_id), updated_at = VALUES(updated_at)'));

		return count($records);
	}

	private function conversionActions(int $projectId, array $rows): int
	{
		$this->db->transaction(function () use ($projectId, $rows) {
			$table = $this->db->table('conversion_actions');

			foreach ($rows as $row) {
				$action = $row['conversionAction'] ?? [];
				$externalId = (string) ($action['id'] ?? '');
				$name = (string) ($action['name'] ?? '');

				if ($externalId === '') {
					continue;
				}

				$id = $this->db->scalar("SELECT id FROM {$table} WHERE project_id = ? AND external_id = ?", [$projectId, $externalId])
					?? $this->db->scalar("SELECT id FROM {$table} WHERE project_id = ? AND external_id IS NULL AND name = ? ORDER BY id LIMIT 1", [$projectId, $name]);

				$values = [
					'natural_key' => ReportWriter::key('id', $externalId),
					'external_id' => $externalId,
					'name' => $name,
					'category' => Enums::categoryLabel($action['category'] ?? null),
					'category_code' => $action['category'] ?? null,
					'source' => $action['origin'] ?? null,
					'status' => Enums::status($action['status'] ?? null),
					'status_raw' => $action['status'] ?? null,
					'include_in_conversions' => isset($action['includeInConversionsMetric']) ? (int) (bool) $action['includeInConversionsMetric'] : null,
					'primary_for_goal' => isset($action['primaryForGoal']) ? (int) (bool) $action['primaryForGoal'] : null,
					'counting_type' => $action['countingType'] ?? null,
					'type' => $action['type'] ?? null,
					'settings' => wp_json_encode(array_filter([
						'origin' => $action['origin'] ?? null,
						'click_through_lookback_window_days' => $action['clickThroughLookbackWindowDays'] ?? null,
						'default_value' => $action['valueSettings']['defaultValue'] ?? null,
						'always_use_default_value' => $action['valueSettings']['alwaysUseDefaultValue'] ?? null,
					], fn ($value) => $value !== null)),
					'updated_at' => $this->db->now(),
				];

				if ($id === null) {
					$this->db->insert('conversion_actions', $values + ['project_id' => $projectId, 'created_at' => $this->db->now()]);
				} else {
					$this->db->update('conversion_actions', $values, ['id' => (int) $id]);
				}
			}
		});

		return count($rows);
	}

	private function campaignMetrics(int $projectId, int $syncRunId, array $step, array $rows): int
	{
		$campaignIds = $this->campaignIdMap($projectId);
		$records = [];

		foreach ($rows as $row) {
			$campaignId = $campaignIds[(string) ($row['campaign']['id'] ?? '')] ?? null;

			if ($campaignId === null) {
				// Kampania spoza listy (np. utworzona w trakcie synchronizacji) – dodajemy ją minimalnie.
				$campaignId = $this->campaignId($projectId, (string) $row['campaign']['id'], 'Kampania ' . $row['campaign']['id']);
				$campaignIds[(string) $row['campaign']['id']] = $campaignId;
			}

			$records[] = ['campaign_id' => $campaignId] + $this->metricRow($projectId, $syncRunId, $row, true);
		}

		return $this->replaceWindow('campaign_metrics', 'campaign_id', $projectId, $syncRunId, $step, $records);
	}

	private function keywordMetrics(int $projectId, int $syncRunId, array $step, array $rows): int
	{
		$adGroupIds = $this->adGroupIdMap($projectId);
		$keywordIds = $this->keywordIdMap($projectId);
		$records = [];

		foreach ($rows as $row) {
			$adGroupId = $adGroupIds[(string) ($row['adGroup']['id'] ?? '')] ?? null;
			$keywordId = $adGroupId !== null ? ($keywordIds[$adGroupId . '|' . ($row['adGroupCriterion']['criterionId'] ?? '')] ?? null) : null;

			if ($keywordId === null) {
				continue; // słowo usunięte przed strukturą – metryki są nadal w danych kampanii
			}

			$records[] = ['keyword_id' => $keywordId] + $this->metricRow($projectId, $syncRunId, $row, false);
		}

		return $this->replaceWindow('keyword_metrics', 'keyword_id', $projectId, $syncRunId, $step, $records);
	}

	private function searchTermMetrics(int $projectId, int $syncRunId, array $step, array $rows): int
	{
		$campaignIds = $this->campaignIdMap($projectId);
		$adGroupIds = $this->adGroupIdMap($projectId);
		$now = $this->db->now();
		$entities = [];
		$metricRows = [];

		foreach ($rows as $row) {
			$campaignId = $campaignIds[(string) ($row['campaign']['id'] ?? '')] ?? null;

			if ($campaignId === null) {
				continue;
			}

			$adGroupId = $adGroupIds[(string) ($row['adGroup']['id'] ?? '')] ?? null;
			$term = (string) ($row['searchTermView']['searchTerm'] ?? '');
			$matchType = Enums::searchTermMatchType($row['segments']['searchTermMatchType'] ?? null);
			$keyword = $row['segments']['keyword']['info']['text'] ?? null;

			if ($term === '') {
				continue;
			}

			// Ten sam klucz naturalny co przy imporcie CSV (SearchTermWriter).
			$naturalKey = ReportWriter::key('term', implode('|', [$campaignId, $adGroupId ?? 0, mb_strtolower($term), $matchType ?? '', mb_strtolower((string) $keyword)]));

			$entities[$naturalKey] = [
				'project_id' => $projectId,
				'campaign_id' => $campaignId,
				'ad_group_id' => $adGroupId,
				'natural_key' => $naturalKey,
				'term' => mb_substr($term, 0, 500),
				'match_type' => $matchType,
				'keyword_text' => $keyword,
				'added_excluded' => $row['searchTermView']['status'] ?? null,
				'created_at' => $now,
				'updated_at' => $now,
			];

			$metricRows[] = [$naturalKey, $row];
		}

		return $this->db->transaction(function () use ($projectId, $syncRunId, $step, $entities, $metricRows) {
			$this->db->insertMany('search_terms', array_values($entities), 300,
				'ON DUPLICATE KEY UPDATE added_excluded = VALUES(added_excluded), updated_at = VALUES(updated_at)');

			$ids = [];

			foreach (array_chunk(array_keys($entities), 500) as $chunk) {
				$placeholders = implode(', ', array_fill(0, count($chunk), '?'));

				foreach ($this->db->select("SELECT id, natural_key FROM {$this->db->table('search_terms')} WHERE project_id = ? AND natural_key IN ({$placeholders})", [$projectId, ...$chunk]) as $entity) {
					$ids[$entity['natural_key']] = (int) $entity['id'];
				}
			}

			$records = [];

			foreach ($metricRows as [$naturalKey, $row]) {
				if (isset($ids[$naturalKey])) {
					$records[] = ['search_term_id' => $ids[$naturalKey]] + $this->metricRow($projectId, $syncRunId, $row, false);
				}
			}

			return $this->replaceWindow('search_term_metrics', 'search_term_id', $projectId, $syncRunId, $step, $records);
		});
	}

	private function conversionMetrics(int $projectId, int $syncRunId, array $step, array $rows): int
	{
		$actionIds = [];

		foreach ($this->db->select('SELECT id, external_id FROM ' . $this->db->table('conversion_actions') . ' WHERE project_id = ? AND external_id IS NOT NULL', [$projectId]) as $action) {
			$actionIds[(string) $action['external_id']] = (int) $action['id'];
		}

		$records = [];

		foreach ($rows as $row) {
			$resource = (string) ($row['segments']['conversionAction'] ?? '');
			$externalId = preg_match('/conversionActions\/(\d+)$/', $resource, $m) ? $m[1] : null;
			$actionId = $externalId !== null ? ($actionIds[$externalId] ?? null) : null;

			if ($actionId === null) {
				continue;
			}

			$metrics = $row['metrics'] ?? [];
			$key = $actionId . '|' . $row['segments']['date'];

			$records[$key] = [
				'project_id' => $projectId,
				'conversion_action_id' => $actionId,
				'campaign_scope' => 0,
				'granularity' => 'day',
				'date_from' => $row['segments']['date'],
				'date_to' => $row['segments']['date'],
				'source' => 'api',
				'conversions' => self::decimal($metrics['conversions'] ?? 0),
				'view_through_conversions' => null,
				'all_conversions' => self::decimal($metrics['allConversions'] ?? 0),
				'conversions_value_micros' => self::micros($metrics['conversionsValue'] ?? 0),
				'all_conversions_value_micros' => self::micros($metrics['allConversionsValue'] ?? 0),
				'currency' => null,
				'import_id' => null,
				'sync_run_id' => $syncRunId,
				'created_at' => $this->db->now(),
				'updated_at' => $this->db->now(),
			];
		}

		return $this->replaceWindow('conversion_metrics', 'conversion_action_id', $projectId, $syncRunId, $step, array_values($records));
	}

	/**
	 * Zastępuje wiersze API w oknie dat i zapisuje pokrycie – jedna transakcja.
	 */
	private function replaceWindow(string $table, string $entityColumn, int $projectId, int $syncRunId, array $step, array $records): int
	{
		$records = self::aggregate($records, $entityColumn);

		return $this->db->transaction(function () use ($table, $projectId, $syncRunId, $step, $records) {
			$this->db->statement(
				"DELETE FROM {$this->db->table($table)} WHERE project_id = ? AND source = 'api' AND granularity = 'day' AND date_from BETWEEN ? AND ?",
				[$projectId, $step['from'], $step['to']]
			);

			$this->db->insertMany($table, $records, 400);

			$this->db->statement(
				"INSERT INTO {$this->db->table('data_coverage')} (project_id, source, dataset, date_from, date_to, sync_run_id, created_at)
				VALUES (?, 'api', ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE sync_run_id = VALUES(sync_run_id), created_at = VALUES(created_at)",
				[$projectId, self::METRIC_STEPS[$table], $step['from'], $step['to'], $syncRunId, $this->db->now()]
			);

			return count($records);
		});
	}

	/**
	 * Sumuje wiersze o tym samym kluczu (encja + dzień), np. gdy API zwraca kilka wierszy segmentów.
	 */
	public static function aggregate(array $records, string $entityColumn): array
	{
		$out = [];

		foreach ($records as $record) {
			$key = $record[$entityColumn] . '|' . ($record['campaign_scope'] ?? '') . '|' . $record['date_from'];

			if (! isset($out[$key])) {
				$out[$key] = $record;
				continue;
			}

			foreach (['impressions', 'clicks', 'cost_micros', 'conversions_value_micros', 'all_conversions_value_micros'] as $column) {
				if (array_key_exists($column, $record) && $record[$column] !== null) {
					$out[$key][$column] = (int) ($out[$key][$column] ?? 0) + (int) $record[$column];
				}
			}

			foreach (['conversions', 'all_conversions', 'view_through_conversions'] as $column) {
				if (array_key_exists($column, $record) && $record[$column] !== null) {
					$out[$key][$column] = self::decimal((float) ($out[$key][$column] ?? 0) + (float) $record[$column]);
				}
			}
		}

		return array_values($out);
	}

	/**
	 * Metryki z API: brak pola w odpowiedzi oznacza 0 (API pomija zera), w odróżnieniu od importu.
	 */
	private function metricRow(int $projectId, int $syncRunId, array $row, bool $withViewThrough): array
	{
		$metrics = $row['metrics'] ?? [];
		$now = $this->db->now();

		return [
			'project_id' => $projectId,
			'granularity' => 'day',
			'date_from' => $row['segments']['date'],
			'date_to' => $row['segments']['date'],
			'source' => 'api',
			'impressions' => (int) ($metrics['impressions'] ?? 0),
			'clicks' => (int) ($metrics['clicks'] ?? 0),
			'cost_micros' => (int) ($metrics['costMicros'] ?? 0),
			'conversions' => self::decimal($metrics['conversions'] ?? 0),
			'view_through_conversions' => $withViewThrough ? self::decimal($metrics['viewThroughConversions'] ?? 0) : null,
			'conversions_value_micros' => self::micros($metrics['conversionsValue'] ?? 0),
			'all_conversions' => self::decimal($metrics['allConversions'] ?? 0),
			'currency' => null,
			'import_id' => null,
			'sync_run_id' => $syncRunId,
			'created_at' => $now,
			'updated_at' => $now,
		];
	}

	private function campaignId(int $projectId, string $externalId, string $name): int
	{
		$table = $this->db->table('campaigns');
		$id = $this->db->scalar("SELECT id FROM {$table} WHERE project_id = ? AND external_id = ?", [$projectId, $externalId]);

		if ($id === null) {
			// Kampania zaimportowana wcześniej z pliku bez identyfikatora – łączymy po nazwie.
			$id = $this->db->scalar("SELECT id FROM {$table} WHERE project_id = ? AND external_id IS NULL AND name = ? ORDER BY id LIMIT 1", [$projectId, $name]);

			if ($id !== null) {
				$this->db->update('campaigns', ['external_id' => $externalId, 'natural_key' => ReportWriter::key('id', $externalId)], ['id' => (int) $id]);
			}
		}

		if ($id === null) {
			$now = $this->db->now();
			$id = $this->db->insert('campaigns', [
				'project_id' => $projectId,
				'natural_key' => ReportWriter::key('id', $externalId),
				'external_id' => $externalId,
				'name' => $name,
				'created_at' => $now,
				'updated_at' => $now,
			]);
		}

		return (int) $id;
	}

	private function campaignSettings(int $campaignId): array
	{
		$raw = $this->db->scalar('SELECT settings FROM ' . $this->db->table('campaigns') . ' WHERE id = ?', [$campaignId]);

		return $raw ? (json_decode((string) $raw, true) ?: []) : [];
	}

	/**
	 * @return array<string, int> identyfikator Google Ads → id kampanii
	 */
	private function campaignIdMap(int $projectId): array
	{
		$rows = $this->db->select('SELECT id, external_id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? AND external_id IS NOT NULL', [$projectId]);

		return array_map('intval', array_column($rows, 'id', 'external_id'));
	}

	private function adGroupIdMap(int $projectId): array
	{
		$rows = $this->db->select('SELECT id, external_id FROM ' . $this->db->table('ad_groups') . ' WHERE project_id = ? AND external_id IS NOT NULL', [$projectId]);

		return array_map('intval', array_column($rows, 'id', 'external_id'));
	}

	/**
	 * @return array<string, int> "idGrupy|idKryterium" → id słowa kluczowego
	 */
	private function keywordIdMap(int $projectId): array
	{
		$map = [];

		foreach ($this->db->select('SELECT id, ad_group_id, external_id FROM ' . $this->db->table('keywords') . ' WHERE project_id = ? AND external_id IS NOT NULL', [$projectId]) as $row) {
			$map[$row['ad_group_id'] . '|' . $row['external_id']] = (int) $row['id'];
		}

		return $map;
	}

	private static function decimal(int|float|string $value): string
	{
		return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
	}

	private static function micros(int|float|string $value): int
	{
		return (int) round((float) $value * 1_000_000);
	}
}
