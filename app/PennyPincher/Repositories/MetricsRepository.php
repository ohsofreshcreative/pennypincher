<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\PeriodResolver;
use App\PennyPincher\Domain\Metrics\ResolvedPeriods;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Support\Paginator;

/**
 * Odczyt metryk. Każde zapytanie:
 * - jest zawężone do projektu (project_id),
 * - korzysta tylko z danych wybranych przez PeriodResolver (bez sumowania nakładających się okresów),
 * - nigdy nie łączy tabel metryk różnych raportów (kampanie vs wyszukiwane hasła).
 */
class MetricsRepository
{
	public const CAMPAIGN_SORTS = [
		'name' => 'c.name',
		'status' => 'c.status',
		'spend' => 'agg.cost_micros',
		'clicks' => 'agg.clicks',
		'impressions' => 'agg.impressions',
		'ctr' => 'agg.clicks / NULLIF(agg.impressions, 0)',
		'cpc' => 'agg.cost_micros / NULLIF(agg.clicks, 0)',
		'conversions' => 'agg.conversions',
		'cpa' => 'agg.cost_micros / NULLIF(agg.conversions, 0)',
	];

	public const SEARCH_TERM_SORTS = [
		'term' => 'st.term',
		'campaign' => 'c.name',
		'spend' => 'agg.cost_micros',
		'clicks' => 'agg.clicks',
		'impressions' => 'agg.impressions',
		'conversions' => 'agg.conversions',
		'cpa' => 'agg.cost_micros / NULLIF(agg.conversions, 0)',
	];

	private const METRIC_TABLES = [
		'campaign_metrics' => 'campaign_id',
		'search_term_metrics' => 'search_term_id',
		'keyword_metrics' => 'keyword_id',
		'conversion_metrics' => 'conversion_action_id',
	];

	public function __construct(private Db $db)
	{
	}

	public const DATASETS = [
		'campaign_metrics' => 'campaigns',
		'search_term_metrics' => 'search_terms',
		'keyword_metrics' => 'keywords',
		'conversion_metrics' => 'conversions',
	];

	public function resolve(string $table, int $projectId, DateRange $range): ResolvedPeriods
	{
		$this->assertTable($table);

		$available = $this->db->select(
			sprintf(
				"SELECT DISTINCT granularity, date_from, date_to, source FROM %s WHERE project_id = ? AND source = 'import' AND date_to >= ? AND date_from <= ?",
				$this->db->table($table)
			),
			[$projectId, $range->fromString(), $range->toString()]
		);

		return PeriodResolver::resolve($available, $range, $this->apiCoverage($projectId, self::DATASETS[$table], $range));
	}

	/**
	 * Zakresy dat zsynchronizowane z API dla zbioru danych (tylko zakończone kroki synchronizacji).
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function apiCoverage(int $projectId, string $dataset, ?DateRange $range = null): array
	{
		$sql = "SELECT date_from, date_to FROM {$this->db->table('data_coverage')} WHERE project_id = ? AND source = 'api' AND dataset = ?";
		$params = [$projectId, $dataset];

		if ($range !== null) {
			$sql .= ' AND date_to >= ? AND date_from <= ?';
			$params[] = $range->fromString();
			$params[] = $range->toString();
		}

		return array_map(fn ($row) => [$row['date_from'], $row['date_to']], $this->db->select($sql . ' ORDER BY date_from', $params));
	}

	/**
	 * Pełny zakres dat dostępnych danych (dla ustawienia „Wszystkie dane”).
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public function dataExtent(int $projectId, string $table = 'campaign_metrics'): ?array
	{
		$this->assertTable($table);

		$row = $this->db->first(
			sprintf('SELECT MIN(date_from) AS date_from, MAX(date_to) AS date_to FROM %s WHERE project_id = ?', $this->db->table($table)),
			[$projectId]
		);

		$extent = $row && $row['date_from'] ? [$row['date_from'], $row['date_to']] : null;

		foreach ($this->apiCoverage($projectId, self::DATASETS[$table]) as [$from, $to]) {
			$extent = $extent === null ? [$from, $to] : [min($extent[0], $from), max($extent[1], $to)];
		}

		return $extent;
	}

	/**
	 * Okresy dostępne w projekcie (do opisu źródeł danych).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function availablePeriods(int $projectId, string $table): array
	{
		$this->assertTable($table);

		return $this->db->select(
			sprintf(
				"SELECT granularity, MIN(date_from) AS date_from, MAX(date_to) AS date_to, COUNT(DISTINCT date_from) AS days
				FROM %s WHERE project_id = ? AND source = 'import' AND granularity = 'day' GROUP BY granularity
				UNION ALL
				SELECT granularity, date_from, date_to, NULL AS days FROM %s WHERE project_id = ? AND source = 'import' AND granularity = 'period'
				GROUP BY granularity, date_from, date_to ORDER BY date_from",
				$this->db->table($table),
				$this->db->table($table)
			),
			[$projectId, $projectId]
		);
	}

	/**
	 * Sumy metryk kampanii dla projektu.
	 *
	 * @return array{impressions: int|null, clicks: int|null, cost_micros: int|null, conversions: string|null, conversions_value_micros: int|null}
	 */
	public function campaignTotals(int $projectId, ResolvedPeriods $resolved, ?int $campaignId = null): array
	{
		[$condition, $params] = $this->condition($resolved, 'm');
		$campaignSql = $campaignId !== null ? ' AND m.campaign_id = ?' : '';

		$row = $this->db->first(
			sprintf(
				'SELECT SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.cost_micros) AS cost_micros,
					SUM(m.conversions) AS conversions, SUM(m.conversions_value_micros) AS conversions_value_micros
				FROM %s m WHERE m.project_id = ? AND (%s)%s',
				$this->db->table('campaign_metrics'),
				$condition,
				$campaignSql
			),
			[$projectId, ...$params, ...($campaignId !== null ? [$campaignId] : [])]
		) ?? [];

		return self::castMetrics($row);
	}

	/**
	 * Dzienna seria (tylko dni wybrane przez resolver), uzupełniona o brakujące dni wartością null.
	 *
	 * @return array<int, array{date: string, cost_micros: int|null, clicks: int|null, conversions: float|null}>
	 */
	public function dailySeries(int $projectId, ResolvedPeriods $resolved, DateRange $range, ?int $campaignId = null): array
	{
		$byDate = [];

		if ($resolved->hasDaily()) {
			[$condition, $params] = $this->condition($resolved, 'm');
			$campaignSql = $campaignId !== null ? ' AND m.campaign_id = ?' : '';

			$rows = $this->db->select(
				sprintf(
					"SELECT m.date_from AS date, SUM(m.cost_micros) AS cost_micros, SUM(m.clicks) AS clicks, SUM(m.impressions) AS impressions, SUM(m.conversions) AS conversions
					FROM %s m WHERE m.project_id = ? AND m.granularity = 'day' AND (%s)%s GROUP BY m.date_from",
					$this->db->table('campaign_metrics'),
					$condition,
					$campaignSql
				),
				[$projectId, ...$params, ...($campaignId !== null ? [$campaignId] : [])]
			);

			foreach ($rows as $row) {
				$byDate[$row['date']] = $row;
			}
		}

		$series = [];

		for ($day = $range->from; $day <= $range->to; $day = $day->modify('+1 day')) {
			$key = $day->format('Y-m-d');
			$row = $byDate[$key] ?? null;

			// Dzień objęty synchronizacją API bez wierszy = brak aktywności (0), a nie brak danych.
			$apiDay = $row === null && $this->inRanges($key, $resolved->apiRanges);

			$series[] = [
				'date' => $key,
				'cost_micros' => isset($row['cost_micros']) ? (int) $row['cost_micros'] : ($apiDay ? 0 : null),
				'clicks' => isset($row['clicks']) ? (int) $row['clicks'] : ($apiDay ? 0 : null),
				'impressions' => isset($row['impressions']) ? (int) $row['impressions'] : ($apiDay ? 0 : null),
				'conversions' => isset($row['conversions']) ? (float) $row['conversions'] : ($apiDay ? 0.0 : null),
			];
		}

		return $series;
	}

	private function inRanges(string $day, array $ranges): bool
	{
		foreach ($ranges as [$from, $to]) {
			if ($day >= $from && $day <= $to) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{q?: string, status?: string} $filters
	 */
	public function campaigns(int $projectId, ResolvedPeriods $resolved, array $filters, string $sort, string $direction, int $page, int $perPage): Paginator
	{
		[$condition, $params] = $this->condition($resolved, 'm');
		$where = ['c.project_id = ?'];
		$whereParams = [$projectId];

		if (! empty($filters['q'])) {
			$where[] = 'c.name LIKE ?';
			$whereParams[] = '%' . $this->db->wpdb()->esc_like($filters['q']) . '%';
		}

		if (! empty($filters['status'])) {
			if ($filters['status'] === 'unknown') {
				$where[] = 'c.status IS NULL';
			} else {
				$where[] = 'c.status = ?';
				$whereParams[] = $filters['status'];
			}
		}

		$from = sprintf(
			'FROM %s c LEFT JOIN (
				SELECT m.campaign_id, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.cost_micros) AS cost_micros,
					SUM(m.conversions) AS conversions, SUM(m.conversions_value_micros) AS conversions_value_micros
				FROM %s m WHERE m.project_id = ? AND (%s) GROUP BY m.campaign_id
			) agg ON agg.campaign_id = c.id
			WHERE %s',
			$this->db->table('campaigns'),
			$this->db->table('campaign_metrics'),
			$condition,
			implode(' AND ', $where)
		);

		$allParams = [$projectId, ...$params, ...$whereParams];
		$total = (int) $this->db->scalar('SELECT COUNT(*) ' . $from, $allParams);

		$order = self::CAMPAIGN_SORTS[$sort] ?? self::CAMPAIGN_SORTS['spend'];
		$direction = $direction === 'asc' ? 'ASC' : 'DESC';
		$paginator = new Paginator($total, $page, $perPage);

		$rows = $this->db->select(
			sprintf(
				'SELECT c.*, agg.impressions, agg.clicks, agg.cost_micros, agg.conversions, agg.conversions_value_micros %s
				ORDER BY (%s) IS NULL, %s %s, c.name ASC LIMIT %d OFFSET %d',
				$from,
				$order,
				$order,
				$direction,
				$paginator->perPage,
				$paginator->offset()
			),
			$allParams
		);

		return $paginator->withItems(array_map(fn ($row) => array_merge($row, self::castMetrics($row)), $rows));
	}

	/**
	 * @param array{q?: string, campaign_id?: int, conversions?: string} $filters
	 */
	public function searchTerms(int $projectId, ResolvedPeriods $resolved, array $filters, string $sort, string $direction, int $page, int $perPage): Paginator
	{
		[$condition, $params] = $this->condition($resolved, 'm');
		$where = ['st.project_id = ?'];
		$whereParams = [$projectId];

		if (! empty($filters['q'])) {
			$where[] = 'st.term LIKE ?';
			$whereParams[] = '%' . $this->db->wpdb()->esc_like($filters['q']) . '%';
		}

		if (! empty($filters['campaign_id'])) {
			$where[] = 'st.campaign_id = ?';
			$whereParams[] = (int) $filters['campaign_id'];
		}

		if (($filters['conversions'] ?? '') === 'with') {
			$where[] = 'agg.conversions > 0';
		} elseif (($filters['conversions'] ?? '') === 'without') {
			$where[] = '(agg.conversions IS NULL OR agg.conversions = 0)';
		}

		$from = sprintf(
			'FROM %s st
			INNER JOIN (
				SELECT m.search_term_id, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.cost_micros) AS cost_micros,
					SUM(m.conversions) AS conversions, SUM(m.conversions_value_micros) AS conversions_value_micros
				FROM %s m WHERE m.project_id = ? AND (%s) GROUP BY m.search_term_id
			) agg ON agg.search_term_id = st.id
			INNER JOIN %s c ON c.id = st.campaign_id AND c.project_id = st.project_id
			LEFT JOIN %s ag ON ag.id = st.ad_group_id
			WHERE %s',
			$this->db->table('search_terms'),
			$this->db->table('search_term_metrics'),
			$condition,
			$this->db->table('campaigns'),
			$this->db->table('ad_groups'),
			implode(' AND ', $where)
		);

		$allParams = [$projectId, ...$params, ...$whereParams];
		$total = (int) $this->db->scalar('SELECT COUNT(*) ' . $from, $allParams);

		$order = self::SEARCH_TERM_SORTS[$sort] ?? self::SEARCH_TERM_SORTS['spend'];
		$direction = $direction === 'asc' ? 'ASC' : 'DESC';
		$paginator = new Paginator($total, $page, $perPage);

		$rows = $this->db->select(
			sprintf(
				'SELECT st.*, c.name AS campaign_name, ag.name AS ad_group_name,
					agg.impressions, agg.clicks, agg.cost_micros, agg.conversions, agg.conversions_value_micros %s
				ORDER BY (%s) IS NULL, %s %s, st.term ASC LIMIT %d OFFSET %d',
				$from,
				$order,
				$order,
				$direction,
				$paginator->perPage,
				$paginator->offset()
			),
			$allParams
		);

		return $paginator->withItems(array_map(fn ($row) => array_merge($row, self::castMetrics($row)), $rows));
	}

	/**
	 * Kampanie projektu do filtrów (id → nazwa).
	 *
	 * @return array<int, string>
	 */
	public function campaignOptions(int $projectId): array
	{
		$rows = $this->db->select('SELECT id, name FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? ORDER BY name', [$projectId]);

		return array_column($rows, 'name', 'id');
	}

	public function findCampaign(int $projectId, int $campaignId): ?array
	{
		return $this->db->first('SELECT * FROM ' . $this->db->table('campaigns') . ' WHERE id = ? AND project_id = ?', [$campaignId, $projectId]);
	}

	/**
	 * Wszystkie zapisane wiersze metryk kampanii z informacją o źródle (imporcie).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function campaignMetricSources(int $projectId, int $campaignId): array
	{
		return $this->db->select(
			sprintf(
				"SELECT m.source, m.granularity, MIN(m.date_from) AS date_from, MAX(m.date_to) AS date_to, COUNT(*) AS rows_count,
					SUM(m.cost_micros) AS cost_micros, m.import_id, i.original_filename, i.report_type, i.completed_at
				FROM %s m LEFT JOIN %s i ON i.id = m.import_id
				WHERE m.project_id = ? AND m.campaign_id = ?
				GROUP BY m.source, m.import_id, m.granularity, CASE WHEN m.granularity = 'period' THEN m.date_from END, CASE WHEN m.granularity = 'period' THEN m.date_to END,
					i.original_filename, i.report_type, i.completed_at
				ORDER BY date_from DESC",
				$this->db->table('campaign_metrics'),
				$this->db->table('imports')
			),
			[$projectId, $campaignId]
		);
	}

	/**
	 * Działania powodujące konwersję w zakresie. Dane dla całego konta (campaign_scope = 0) mają pierwszeństwo
	 * przed sumą danych per kampania z tego samego działania – inaczej te same konwersje liczylibyśmy dwa razy.
	 * Konwersje po obejrzeniu są zwracane osobno i nie są dodawane do konwersji.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function conversionActions(int $projectId, ResolvedPeriods $resolved): array
	{
		[$condition, $params] = $this->condition($resolved, 'm');

		$rows = $this->db->select(
			sprintf(
				'SELECT ca.id, ca.name, ca.category, ca.source, ca.status, ca.status_raw, ca.include_in_conversions,
					(m.campaign_scope = 0) AS account_level,
					SUM(m.conversions) AS conversions, SUM(m.view_through_conversions) AS view_through_conversions,
					SUM(m.all_conversions) AS all_conversions, SUM(m.conversions_value_micros) AS conversions_value_micros
				FROM %s m INNER JOIN %s ca ON ca.id = m.conversion_action_id AND ca.project_id = m.project_id
				WHERE m.project_id = ? AND (%s)
				GROUP BY ca.id, ca.name, ca.category, ca.source, ca.status, ca.status_raw, ca.include_in_conversions, (m.campaign_scope = 0)',
				$this->db->table('conversion_metrics'),
				$this->db->table('conversion_actions'),
				$condition
			),
			[$projectId, ...$params]
		);

		$actions = [];

		foreach ($rows as $row) {
			$id = (int) $row['id'];

			if (! isset($actions[$id]) || (int) $row['account_level'] === 1) {
				$actions[$id] = $row;
			}
		}

		usort($actions, fn ($a, $b) => ((float) $b['conversions'] <=> (float) $a['conversions']) ?: strcmp($a['name'], $b['name']));

		return $actions;
	}

	/**
	 * Metryki każdej kampanii projektu w wybranych danych (do audytu).
	 *
	 * @return array<int, array<string, mixed>> klucz = id kampanii
	 */
	public function campaignMetricsByCampaign(int $projectId, ResolvedPeriods $resolved): array
	{
		if ($resolved->isEmpty()) {
			return [];
		}

		[$condition, $params] = $this->condition($resolved, 'm');

		$rows = $this->db->select(
			sprintf(
				'SELECT m.campaign_id, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.cost_micros) AS cost_micros,
					SUM(m.conversions) AS conversions, SUM(m.conversions_value_micros) AS conversions_value_micros
				FROM %s m WHERE m.project_id = ? AND (%s) GROUP BY m.campaign_id',
				$this->db->table('campaign_metrics'),
				$condition
			),
			[$projectId, ...$params]
		);

		$result = [];

		foreach ($rows as $row) {
			$metrics = self::castMetrics($row);
			$metrics['conversions'] = $metrics['conversions'] === null ? null : (float) $metrics['conversions'];
			$result[(int) $row['campaign_id']] = $metrics;
		}

		return $result;
	}

	/**
	 * Wyszukiwane hasła z co najmniej $minClicks kliknięciami i zerem konwersji (null = brak danych, nie zero)
	 * oraz łączny koszt wszystkich haseł w tych samych danych.
	 *
	 * @return array{terms: array<int, array{term: string, campaign: string, clicks: int, cost_micros: int}>, total_cost_micros: ?int}
	 */
	public function searchTermsWithoutConversions(int $projectId, ResolvedPeriods $resolved, int $minClicks = 10, int $limit = 200): array
	{
		if ($resolved->isEmpty()) {
			return ['terms' => [], 'total_cost_micros' => null];
		}

		[$condition, $params] = $this->condition($resolved, 'm');
		$aggregate = sprintf(
			'SELECT m.search_term_id, SUM(m.clicks) AS clicks, SUM(m.cost_micros) AS cost_micros, SUM(m.conversions) AS conversions
			FROM %s m WHERE m.project_id = ? AND (%s) GROUP BY m.search_term_id',
			$this->db->table('search_term_metrics'),
			$condition
		);

		$total = $this->db->scalar('SELECT SUM(agg.cost_micros) FROM (' . $aggregate . ') agg', [$projectId, ...$params]);

		$rows = $this->db->select(
			sprintf(
				'SELECT st.term, c.name AS campaign, agg.clicks, agg.cost_micros
				FROM (%s) agg
				INNER JOIN %s st ON st.id = agg.search_term_id AND st.project_id = ?
				INNER JOIN %s c ON c.id = st.campaign_id
				WHERE agg.clicks >= ? AND agg.conversions IS NOT NULL AND agg.conversions = 0 AND agg.cost_micros > 0
				ORDER BY agg.cost_micros DESC LIMIT %d',
				$aggregate,
				$this->db->table('search_terms'),
				$this->db->table('campaigns'),
				$limit
			),
			[$projectId, ...$params, $projectId, $minClicks]
		);

		return [
			'terms' => array_map(fn ($row) => [
				'term' => (string) $row['term'],
				'campaign' => (string) $row['campaign'],
				'clicks' => (int) $row['clicks'],
				'cost_micros' => (int) $row['cost_micros'],
			], $rows),
			'total_cost_micros' => $total === null ? null : (int) $total,
		];
	}

	/**
	 * Wszystkie wyszukiwane hasła z sumami w okresie (do analiz) – posortowane po koszcie, z limitem.
	 *
	 * @return array<int, array{id: int, term: string, campaign_id: int, ad_group_id: ?int, keyword_text: ?string, match_type: ?string, added_excluded: ?string, impressions: int, clicks: int, cost_micros: int, conversions: ?float}>
	 */
	public function searchTermStats(int $projectId, ResolvedPeriods $resolved, int $limit = 5000): array
	{
		if ($resolved->isEmpty()) {
			return [];
		}

		[$condition, $params] = $this->condition($resolved, 'm');

		$rows = $this->db->select(
			sprintf(
				'SELECT st.id, st.term, st.campaign_id, st.ad_group_id, st.keyword_text, st.match_type, st.added_excluded,
					agg.impressions, agg.clicks, agg.cost_micros, agg.conversions
				FROM (
					SELECT m.search_term_id, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.cost_micros) AS cost_micros, SUM(m.conversions) AS conversions
					FROM %s m WHERE m.project_id = ? AND (%s) GROUP BY m.search_term_id
				) agg
				INNER JOIN %s st ON st.id = agg.search_term_id AND st.project_id = ?
				ORDER BY agg.cost_micros DESC LIMIT %d',
				$this->db->table('search_term_metrics'),
				$condition,
				$this->db->table('search_terms'),
				$limit
			),
			[$projectId, ...$params, $projectId]
		);

		return array_map(fn ($row) => [
			'id' => (int) $row['id'],
			'term' => (string) $row['term'],
			'campaign_id' => (int) $row['campaign_id'],
			'ad_group_id' => $row['ad_group_id'] === null ? null : (int) $row['ad_group_id'],
			'keyword_text' => $row['keyword_text'],
			'match_type' => $row['match_type'],
			'added_excluded' => $row['added_excluded'],
			'impressions' => (int) $row['impressions'],
			'clicks' => (int) $row['clicks'],
			'cost_micros' => (int) $row['cost_micros'],
			'conversions' => $row['conversions'] === null ? null : (float) $row['conversions'],
		], $rows);
	}

	/**
	 * Słowa kluczowe z sumami w okresie (tylko te z danymi).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function keywordStats(int $projectId, ResolvedPeriods $resolved, int $limit = 5000): array
	{
		if ($resolved->isEmpty()) {
			return [];
		}

		[$condition, $params] = $this->condition($resolved, 'm');

		$rows = $this->db->select(
			sprintf(
				'SELECT k.id, k.text, k.match_type, k.status, k.campaign_id, k.ad_group_id, k.quality_score,
					agg.impressions, agg.clicks, agg.cost_micros, agg.conversions
				FROM (
					SELECT m.keyword_id, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.cost_micros) AS cost_micros, SUM(m.conversions) AS conversions
					FROM %s m WHERE m.project_id = ? AND (%s) GROUP BY m.keyword_id
				) agg
				INNER JOIN %s k ON k.id = agg.keyword_id AND k.project_id = ?
				ORDER BY agg.cost_micros DESC LIMIT %d',
				$this->db->table('keyword_metrics'),
				$condition,
				$this->db->table('keywords'),
				$limit
			),
			[$projectId, ...$params, $projectId]
		);

		return array_map(fn ($row) => [
			'id' => (int) $row['id'],
			'text' => (string) $row['text'],
			'match_type' => $row['match_type'],
			'status' => $row['status'],
			'campaign_id' => (int) $row['campaign_id'],
			'ad_group_id' => $row['ad_group_id'] === null ? null : (int) $row['ad_group_id'],
			'quality_score' => $row['quality_score'] === null ? null : (int) $row['quality_score'],
			'impressions' => (int) $row['impressions'],
			'clicks' => (int) $row['clicks'],
			'cost_micros' => (int) $row['cost_micros'],
			'conversions' => $row['conversions'] === null ? null : (float) $row['conversions'],
		], $rows);
	}

	/**
	 * Wszystkie słowa kluczowe projektu (także bez danych w okresie) – do sprawdzania pokrycia fraz.
	 *
	 * @return array<int, array{id: int, text: string, match_type: ?string, status: ?string, campaign_id: int, ad_group_id: ?int}>
	 */
	public function allKeywords(int $projectId): array
	{
		return array_map(fn ($row) => [
			'id' => (int) $row['id'],
			'text' => (string) $row['text'],
			'match_type' => $row['match_type'],
			'status' => $row['status'],
			'campaign_id' => (int) $row['campaign_id'],
			'ad_group_id' => $row['ad_group_id'] === null ? null : (int) $row['ad_group_id'],
		], $this->db->select('SELECT id, text, match_type, status, campaign_id, ad_group_id FROM ' . $this->db->table('keywords') . ' WHERE project_id = ?', [$projectId]));
	}

	/**
	 * @return array<int, array{id: int, name: string, campaign_id: int, status: ?string}>
	 */
	public function adGroups(int $projectId): array
	{
		$groups = [];

		foreach ($this->db->select('SELECT id, name, campaign_id, status FROM ' . $this->db->table('ad_groups') . ' WHERE project_id = ?', [$projectId]) as $row) {
			$groups[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'campaign_id' => (int) $row['campaign_id'], 'status' => $row['status']];
		}

		return $groups;
	}

	/**
	 * Słowa wykluczające zsynchronizowane z Google Ads.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function negativeKeywords(int $projectId): array
	{
		return $this->db->select('SELECT id, level, campaign_id, ad_group_id, shared_set_name, text, normalized, match_type FROM ' . $this->db->table('negative_keywords') . ' WHERE project_id = ?', [$projectId]);
	}

	public function hasAnyData(int $projectId): bool
	{
		foreach (array_keys(self::METRIC_TABLES) as $table) {
			if ($this->db->scalar('SELECT 1 FROM ' . $this->db->table($table) . ' WHERE project_id = ? LIMIT 1', [$projectId]) !== null) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Warunek SQL wybierający dane wskazane przez resolver.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	public function condition(ResolvedPeriods $resolved, string $alias): array
	{
		$parts = [];
		$params = [];

		foreach ($resolved->apiRanges as [$from, $to]) {
			$parts[] = sprintf("(%1\$s.source = 'api' AND %1\$s.granularity = 'day' AND %1\$s.date_from BETWEEN ? AND ?)", $alias);
			$params[] = $from;
			$params[] = $to;
		}

		if ($resolved->days !== []) {
			$parts[] = sprintf("(%1\$s.source = 'import' AND %1\$s.granularity = 'day' AND %1\$s.date_from IN (%2\$s))", $alias, implode(', ', array_fill(0, count($resolved->days), '?')));
			array_push($params, ...$resolved->days);
		}

		foreach ($resolved->periods as [$from, $to]) {
			$parts[] = sprintf("(%1\$s.source = 'import' AND %1\$s.granularity = 'period' AND %1\$s.date_from = ? AND %1\$s.date_to = ?)", $alias);
			$params[] = $from;
			$params[] = $to;
		}

		return [$parts === [] ? '1 = 0' : implode(' OR ', $parts), $params];
	}

	public static function castMetrics(array $row): array
	{
		$int = fn ($key) => isset($row[$key]) && $row[$key] !== null ? (int) $row[$key] : null;

		return [
			'impressions' => $int('impressions'),
			'clicks' => $int('clicks'),
			'cost_micros' => $int('cost_micros'),
			'conversions' => isset($row['conversions']) && $row['conversions'] !== null ? (string) $row['conversions'] : null,
			'conversions_value_micros' => $int('conversions_value_micros'),
		];
	}

	private function assertTable(string $table): void
	{
		if (! isset(self::METRIC_TABLES[$table])) {
			throw new \InvalidArgumentException("Nieznana tabela metryk: {$table}");
		}
	}
}
