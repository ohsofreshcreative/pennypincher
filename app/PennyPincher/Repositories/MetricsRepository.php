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

	public function resolve(string $table, int $projectId, DateRange $range): ResolvedPeriods
	{
		$this->assertTable($table);

		$available = $this->db->select(
			sprintf(
				'SELECT DISTINCT granularity, date_from, date_to FROM %s WHERE project_id = ? AND date_to >= ? AND date_from <= ?',
				$this->db->table($table)
			),
			[$projectId, $range->fromString(), $range->toString()]
		);

		return PeriodResolver::resolve($available, $range);
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

		return $row && $row['date_from'] ? [$row['date_from'], $row['date_to']] : null;
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
				FROM %s WHERE project_id = ? AND granularity = 'day' GROUP BY granularity
				UNION ALL
				SELECT granularity, date_from, date_to, NULL AS days FROM %s WHERE project_id = ? AND granularity = 'period'
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

		if ($resolved->days !== []) {
			$placeholders = implode(', ', array_fill(0, count($resolved->days), '?'));
			$campaignSql = $campaignId !== null ? ' AND campaign_id = ?' : '';

			$rows = $this->db->select(
				sprintf(
					"SELECT date_from AS date, SUM(cost_micros) AS cost_micros, SUM(clicks) AS clicks, SUM(conversions) AS conversions
					FROM %s WHERE project_id = ? AND granularity = 'day' AND date_from IN (%s)%s GROUP BY date_from",
					$this->db->table('campaign_metrics'),
					$placeholders,
					$campaignSql
				),
				[$projectId, ...$resolved->days, ...($campaignId !== null ? [$campaignId] : [])]
			);

			foreach ($rows as $row) {
				$byDate[$row['date']] = $row;
			}
		}

		$series = [];

		for ($day = $range->from; $day <= $range->to; $day = $day->modify('+1 day')) {
			$key = $day->format('Y-m-d');
			$row = $byDate[$key] ?? null;

			$series[] = [
				'date' => $key,
				'cost_micros' => isset($row['cost_micros']) ? (int) $row['cost_micros'] : null,
				'clicks' => isset($row['clicks']) ? (int) $row['clicks'] : null,
				'conversions' => isset($row['conversions']) ? (float) $row['conversions'] : null,
			];
		}

		return $series;
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
				"SELECT m.granularity, MIN(m.date_from) AS date_from, MAX(m.date_to) AS date_to, COUNT(*) AS rows_count,
					SUM(m.cost_micros) AS cost_micros, m.import_id, i.original_filename, i.report_type, i.completed_at
				FROM %s m LEFT JOIN %s i ON i.id = m.import_id
				WHERE m.project_id = ? AND m.campaign_id = ?
				GROUP BY m.import_id, m.granularity, CASE WHEN m.granularity = 'period' THEN m.date_from END, CASE WHEN m.granularity = 'period' THEN m.date_to END,
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

		if ($resolved->days !== []) {
			$parts[] = sprintf("(%s.granularity = 'day' AND %s.date_from IN (%s))", $alias, $alias, implode(', ', array_fill(0, count($resolved->days), '?')));
			array_push($params, ...$resolved->days);
		}

		foreach ($resolved->periods as [$from, $to]) {
			$parts[] = sprintf("(%s.granularity = 'period' AND %s.date_from = ? AND %s.date_to = ?)", $alias, $alias, $alias);
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
