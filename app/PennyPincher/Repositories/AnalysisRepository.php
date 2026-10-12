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
		}

		$this->db->insertMany('keyword_opportunities', $rows, 200);
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

		return array_map(function ($row) {
			$row['score_breakdown'] = json_decode((string) $row['score_breakdown'], true) ?: [];
			$row['evidence'] = json_decode((string) $row['evidence'], true) ?: [];

			return $row;
		}, $this->db->select('SELECT * FROM ' . $this->db->table('keyword_opportunities') . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$order}, id", $params));
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
