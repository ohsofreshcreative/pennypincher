<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Przebiegi analiz (Budget Leaks, słowa kluczowe) i ich wyniki. Każdy przebieg ma swój okres – wyniki różnych
 * okresów nie nadpisują się; statusy przenosimy między przebiegami po odcisku (fingerprint).
 */
class AnalysisRepository
{
	public const LEAK_STATUSES = ['new' => 'Nowy', 'reviewed' => 'Przejrzany', 'dismissed' => 'Pominięty'];

	public function __construct(private Db $db)
	{
	}

	public function createRun(int $projectId, string $type, string $from, string $to, string $preset, string $trigger, ?int $userId, array $summary = []): int
	{
		return $this->db->insert('analysis_runs', [
			'project_id' => $projectId,
			'type' => $type,
			'status' => 'completed',
			'date_from' => $from,
			'date_to' => $to,
			'range_preset' => $preset,
			'summary' => wp_json_encode($summary),
			'trigger_type' => $trigger,
			'requested_by' => $userId,
			'created_at' => $this->db->now(),
		]);
	}

	public function updateSummary(int $runId, array $summary): void
	{
		$this->db->update('analysis_runs', ['summary' => wp_json_encode($summary)], ['id' => $runId]);
	}

	public function latestRun(int $projectId, string $type, ?string $from = null, ?string $to = null): ?array
	{
		$sql = 'SELECT * FROM ' . $this->db->table('analysis_runs') . ' WHERE project_id = ? AND type = ?';
		$params = [$projectId, $type];

		if ($from !== null && $to !== null) {
			$sql .= ' AND date_from = ? AND date_to = ?';
			array_push($params, $from, $to);
		}

		$row = $this->db->first($sql . ' ORDER BY id DESC LIMIT 1', $params);

		return $row ? self::decodeRun($row) : null;
	}

	public function findRun(int $projectId, int $runId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('analysis_runs') . ' WHERE id = ? AND project_id = ?', [$runId, $projectId]);

		return $row ? self::decodeRun($row) : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function runs(int $projectId, string $type, int $limit = 10): array
	{
		return array_map([self::class, 'decodeRun'], $this->db->select(
			'SELECT * FROM ' . $this->db->table('analysis_runs') . ' WHERE project_id = ? AND type = ? ORDER BY id DESC LIMIT ' . (int) $limit,
			[$projectId, $type]
		));
	}

	// --- Budget Leaks -------------------------------------------------------------------------

	public function insertLeaks(array $rows): void
	{
		foreach ($rows as &$row) {
			$row['evidence'] = wp_json_encode($row['evidence']);
		}

		$this->db->insertMany('budget_leaks', $rows, 200);
	}

	/**
	 * @param array{campaign_id?: int, category?: string, priority?: string, status?: string} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function leaks(int $runId, array $filters = []): array
	{
		$where = ['analysis_run_id = ?'];
		$params = [$runId];

		foreach (['campaign_id', 'category', 'priority', 'status'] as $field) {
			if (isset($filters[$field]) && $filters[$field] !== '' && $filters[$field] !== null) {
				$where[] = "{$field} = ?";
				$params[] = $filters[$field];
			}
		}

		return array_map([self::class, 'decodeLeak'], $this->db->select(
			'SELECT * FROM ' . $this->db->table('budget_leaks') . ' WHERE ' . implode(' AND ', $where) . " ORDER BY (status = 'dismissed'), priority_score DESC, cost_micros DESC, id",
			$params
		));
	}

	public function findLeak(int $projectId, int $leakId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('budget_leaks') . ' WHERE id = ? AND project_id = ?', [$leakId, $projectId]);

		return $row ? self::decodeLeak($row) : null;
	}

	public function updateLeakStatus(int $leakId, string $status, int $userId): void
	{
		$this->db->update('budget_leaks', ['status' => $status, 'status_changed_by' => $userId, 'status_changed_at' => $this->db->now()], ['id' => $leakId]);
	}

	/**
	 * Ostatni ręcznie nadany status każdego odcisku w tabeli (budget_leaks).
	 *
	 * @return array<string, array{status: string, status_changed_by: ?int, status_changed_at: ?string}>
	 */
	public function lastLeakStatuses(int $projectId): array
	{
		$rows = $this->db->select(
			sprintf(
				'SELECT f.fingerprint, f.status, f.status_changed_by, f.status_changed_at FROM %1$s f
				INNER JOIN (SELECT fingerprint, MAX(status_changed_at) AS changed FROM %1$s WHERE project_id = ? AND status_changed_at IS NOT NULL GROUP BY fingerprint) last
					ON last.fingerprint = f.fingerprint AND last.changed = f.status_changed_at
				WHERE f.project_id = ?',
				$this->db->table('budget_leaks')
			),
			[$projectId, $projectId]
		);

		$statuses = [];

		foreach ($rows as $row) {
			$statuses[$row['fingerprint']] = ['status' => (string) $row['status'], 'status_changed_by' => $row['status_changed_by'] === null ? null : (int) $row['status_changed_by'], 'status_changed_at' => $row['status_changed_at']];
		}

		return $statuses;
	}

	// --- Słowa kluczowe ------------------------------------------------------------------------

	public function insertOpportunities(array $rows): void
	{
		foreach ($rows as &$row) {
			$row['score_breakdown'] = wp_json_encode($row['score_breakdown']);
			$row['evidence'] = wp_json_encode($row['evidence']);

			if (isset($row['explanation']) && is_array($row['explanation'])) {
				$row['explanation'] = wp_json_encode($row['explanation']);
			}
		}

		$this->db->insertMany('keyword_opportunities', $rows, 200);
	}

	public function insertClusters(array $rows): void
	{
		foreach ($rows as &$row) {
			$row['members'] = wp_json_encode($row['members']);
			$row['cpc_original'] = wp_json_encode($row['cpc_original']);
		}

		$this->db->insertMany('keyword_clusters', $rows, 200);
	}

	/**
	 * Klastry przebiegu z liczbą fraz wg statusu decyzji (bez N+1).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function clusters(int $runId, int $projectId): array
	{
		$rows = $this->db->select('SELECT * FROM ' . $this->db->table('keyword_clusters') . ' WHERE analysis_run_id = ? AND project_id = ? ORDER BY score DESC, phrase_count DESC, id', [$runId, $projectId]);

		return array_map(function ($row) {
			$row['members'] = json_decode((string) $row['members'], true) ?: [];
			$row['cpc_original'] = json_decode((string) $row['cpc_original'], true) ?: [];

			return $row;
		}, $rows);
	}

	public function findCluster(int $runId, int $projectId, string $clusterKey): ?array
	{
		foreach ($this->clusters($runId, $projectId) as $cluster) {
			if ($cluster['cluster_key'] === $clusterKey) {
				return $cluster;
			}
		}

		return null;
	}

	/**
	 * Strona fraz przebiegu z decyzjami użytkownika (status), filtrami i sortowaniem.
	 *
	 * @param array{min_score?: ?int, source?: ?string, sort?: string, status?: ?string, cluster?: ?string, intent?: ?string, q?: string, hide_rejected?: bool} $filters
	 */
	public function opportunitiesPage(int $runId, int $projectId, array $filters, int $page, int $perPage = 50): \App\PennyPincher\Support\Paginator
	{
		$where = ['o.analysis_run_id = ?', 'o.project_id = ?'];
		$params = [$runId, $projectId];

		if (! empty($filters['min_score'])) {
			$where[] = 'o.score >= ?';
			$params[] = (int) $filters['min_score'];
		}

		if (! empty($filters['source'])) {
			$where[] = 'o.sources LIKE ?';
			$params[] = '%' . $this->db->wpdb()->esc_like((string) $filters['source']) . '%';
		}

		if (! empty($filters['cluster'])) {
			$where[] = 'o.cluster_key = ?';
			$params[] = (string) $filters['cluster'];
		}

		if (! empty($filters['intent'])) {
			$where[] = 'o.intent = ?';
			$params[] = (string) $filters['intent'];
		}

		if (($filters['q'] ?? '') !== '') {
			$where[] = 'o.phrase LIKE ?';
			$params[] = '%' . $this->db->wpdb()->esc_like((string) $filters['q']) . '%';
		}

		if (! empty($filters['status'])) {
			if ($filters['status'] === 'new') {
				$where[] = "(d.status IS NULL OR d.status = 'new')";
			} else {
				$where[] = 'd.status = ?';
				$params[] = (string) $filters['status'];
			}
		} elseif (! empty($filters['hide_rejected'])) {
			$where[] = "(d.status IS NULL OR d.status <> 'rejected')";
		}

		$order = match ($filters['sort'] ?? 'score') {
			'volume' => 'o.search_volume IS NULL, o.search_volume DESC, o.score DESC',
			'cpc' => 'o.cpc_project_micros IS NULL, o.cpc_project_micros ASC, o.score DESC',
			'confidence' => 'o.confidence_score DESC, o.score DESC',
			default => 'o.score DESC, o.confidence_score DESC',
		};

		$from = sprintf(' FROM %s o LEFT JOIN %s d ON d.project_id = o.project_id AND d.normalized = o.normalized WHERE %s', $this->db->table('keyword_opportunities'), $this->db->table('keyword_decisions'), implode(' AND ', $where));
		$paginator = new \App\PennyPincher\Support\Paginator((int) $this->db->scalar('SELECT COUNT(*)' . $from, $params), $page, $perPage);
		$rows = $this->db->select('SELECT o.*, d.id AS decision_id, d.status AS decision_status, d.note AS decision_note' . $from . " ORDER BY {$order}, o.id LIMIT " . $paginator->perPage . ' OFFSET ' . $paginator->offset(), $params);

		return $paginator->withItems(array_map([self::class, 'decodeOpportunity'], $rows));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function opportunitiesByNormalized(int $runId, int $projectId, array $normalized): array
	{
		if ($normalized === []) {
			return [];
		}

		$rows = $this->db->select(
			'SELECT * FROM ' . $this->db->table('keyword_opportunities') . ' WHERE analysis_run_id = ? AND project_id = ? AND normalized IN (' . implode(',', array_fill(0, count($normalized), '?')) . ')',
			[$runId, $projectId, ...array_values($normalized)]
		);

		return array_map([self::class, 'decodeOpportunity'], $rows);
	}

	public static function decodeOpportunity(array $row): array
	{
		foreach (['score_breakdown', 'evidence', 'explanation'] as $json) {
			$row[$json] = isset($row[$json]) && $row[$json] ? (json_decode((string) $row[$json], true) ?: []) : [];
		}

		$row['quality_flags'] = isset($row['quality_flags']) && $row['quality_flags'] ? explode(',', (string) $row['quality_flags']) : [];

		foreach (['cpc_micros', 'cpc_project_micros', 'search_volume'] as $int) {
			if (array_key_exists($int, $row)) {
				$row[$int] = $row[$int] === null ? null : (int) $row[$int];
			}
		}

		return $row;
	}

	public function insertNegativeSuggestions(array $rows): void
	{
		foreach ($rows as &$row) {
			$row['related_terms'] = wp_json_encode($row['related_terms']);
		}

		$this->db->insertMany('negative_suggestions', $rows, 200);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function opportunities(int $runId, array $filters = []): array
	{
		$where = ['analysis_run_id = ?'];
		$params = [$runId];

		if (! empty($filters['min_score'])) {
			$where[] = 'score >= ?';
			$params[] = (int) $filters['min_score'];
		}

		if (! empty($filters['source'])) {
			$where[] = 'sources LIKE ?';
			$params[] = '%' . $this->db->wpdb()->esc_like((string) $filters['source']) . '%';
		}

		$order = ($filters['sort'] ?? '') === 'volume' ? 'search_volume DESC' : (($filters['sort'] ?? '') === 'cpc' ? 'cpc_micros ASC' : 'score DESC');

		return array_map([self::class, 'decodeOpportunity'], $this->db->select('SELECT * FROM ' . $this->db->table('keyword_opportunities') . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$order}, id", $params));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function negativeSuggestions(int $runId): array
	{
		return array_map(function ($row) {
			$row['related_terms'] = json_decode((string) $row['related_terms'], true) ?: [];

			return $row;
		}, $this->db->select('SELECT * FROM ' . $this->db->table('negative_suggestions') . ' WHERE analysis_run_id = ? ORDER BY already_excluded, cost_micros DESC, id', [$runId]));
	}

	private static function decodeRun(array $row): array
	{
		$row['summary'] = $row['summary'] ? (json_decode($row['summary'], true) ?: []) : [];

		return $row;
	}

	private static function decodeLeak(array $row): array
	{
		$evidence = $row['evidence'] ? (json_decode($row['evidence'], true) ?: []) : [];
		$row['evidence'] = ['pairs' => $evidence['pairs'] ?? [], 'terms' => $evidence['terms'] ?? []];
		$row['savings_micros'] = $row['savings_micros'] === null ? null : (int) $row['savings_micros'];

		return $row;
	}
}
