<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Zadania DataForSEO (kolejka, koszty) i pamięć podręczna odpowiedzi.
 */
class DataForSeoRepository
{
	public const QUEUED = 'queued';

	public const RUNNING = 'running';

	public const COMPLETED = 'completed';

	public const FAILED = 'failed';

	public const STATUSES = [
		self::QUEUED => 'W kolejce',
		self::RUNNING => 'W trakcie',
		self::COMPLETED => 'Zakończone',
		self::FAILED => 'Błąd',
	];

	public function __construct(private Db $db)
	{
	}

	public function create(array $values): int
	{
		$now = $this->db->now();
		$values['payload'] = wp_json_encode($values['payload']);

		return $this->db->insert('dataforseo_tasks', $values + ['status' => self::QUEUED, 'created_at' => $now, 'updated_at' => $now]);
	}

	public function update(int $id, array $values): void
	{
		$this->db->update('dataforseo_tasks', $values + ['updated_at' => $this->db->now()], ['id' => $id]);
	}

	public function find(int $id): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('dataforseo_tasks') . ' WHERE id = ?', [$id]);

		return $row ? self::decode($row) : null;
	}

	public function findForProject(int $projectId, int $id): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('dataforseo_tasks') . ' WHERE id = ? AND project_id = ?', [$id, $projectId]);

		return $row ? self::decode($row) : null;
	}

	/**
	 * Zadanie o tej samej treści, które czeka lub trwa (idempotencja – brak podwójnego płacenia).
	 */
	public function activeByHash(int $projectId, string $hash): ?array
	{
		$row = $this->db->first(
			'SELECT * FROM ' . $this->db->table('dataforseo_tasks') . ' WHERE project_id = ? AND payload_hash = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 1',
			[$projectId, $hash, self::QUEUED, self::RUNNING]
		);

		return $row ? self::decode($row) : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function forProject(int $projectId, int $limit = 20): array
	{
		return array_map([self::class, 'decode'], $this->db->select(
			'SELECT * FROM ' . $this->db->table('dataforseo_tasks') . ' WHERE project_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
			[$projectId]
		));
	}

	/**
	 * Przejęcie następnego zadania z kolejki (atomowo – równoległe workery nie wezmą tego samego).
	 */
	public function claimNext(): ?array
	{
		$now = $this->db->now();
		$ids = $this->db->column(
			'SELECT id FROM ' . $this->db->table('dataforseo_tasks') . ' WHERE status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT 5',
			[self::QUEUED, $now]
		);

		foreach ($ids as $id) {
			$claimed = $this->db->statement(
				'UPDATE ' . $this->db->table('dataforseo_tasks') . ' SET status = ?, started_at = ?, attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = ?',
				[self::RUNNING, $now, $now, (int) $id, self::QUEUED]
			);

			if ($claimed === 1) {
				return $this->find((int) $id);
			}
		}

		return null;
	}

	/**
	 * Zadania przerwane (np. restart serwera) – wracają do kolejki.
	 */
	public function requeueStale(int $minutes = 15): int
	{
		$limit = gmdate('Y-m-d H:i:s', time() - $minutes * 60);

		return $this->db->statement(
			'UPDATE ' . $this->db->table('dataforseo_tasks') . ' SET status = ?, updated_at = ? WHERE status = ? AND started_at < ?',
			[self::QUEUED, $this->db->now(), self::RUNNING, $limit]
		);
	}

	/**
	 * Wydatki w bieżącym miesiącu (USD): koszt zakończonych + szacunek oczekujących.
	 *
	 * @return array{spent: float, reserved: float}
	 */
	public function monthlyUsage(string $column, int $id, string $monthStart): array
	{
		$column = $column === 'organization_id' ? 'organization_id' : 'project_id';
		$row = $this->db->first(
			"SELECT
				COALESCE(SUM(CASE WHEN status IN ('completed', 'failed') THEN COALESCE(cost, 0) ELSE 0 END), 0) AS spent,
				COALESCE(SUM(CASE WHEN status IN ('queued', 'running') THEN COALESCE(estimated_cost, 0) ELSE 0 END), 0) AS reserved
			FROM " . $this->db->table('dataforseo_tasks') . " WHERE {$column} = ? AND created_at >= ?",
			[$id, $monthStart]
		);

		return ['spent' => round((float) ($row['spent'] ?? 0), 4), 'reserved' => round((float) ($row['reserved'] ?? 0), 4)];
	}

	public function cacheGet(string $key): ?array
	{
		$row = $this->db->first('SELECT response FROM ' . $this->db->table('dataforseo_cache') . ' WHERE cache_key = ? AND expires_at > ?', [$key, $this->db->now()]);
		$decoded = $row ? json_decode((string) $row['response'], true) : null;

		return is_array($decoded) ? $decoded : null;
	}

	public function cachePut(string $key, string $endpoint, array $response, ?float $cost, int $days): void
	{
		$now = $this->db->now();

		$this->db->statement(
			'INSERT INTO ' . $this->db->table('dataforseo_cache') . ' (cache_key, endpoint, response, cost, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE response = VALUES(response), cost = VALUES(cost), expires_at = VALUES(expires_at), created_at = VALUES(created_at)',
			[$key, $endpoint, wp_json_encode($response), $cost, gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS), $now]
		);
	}

	public function pruneCache(): int
	{
		return $this->db->statement('DELETE FROM ' . $this->db->table('dataforseo_cache') . ' WHERE expires_at <= ?', [$this->db->now()]);
	}

	private static function decode(array $row): array
	{
		$row['payload'] = json_decode((string) $row['payload'], true) ?: [];
		$row['estimated_cost'] = $row['estimated_cost'] === null ? null : (float) $row['estimated_cost'];
		$row['cost'] = $row['cost'] === null ? null : (float) $row['cost'];
		$row['attempts'] = (int) $row['attempts'];

		return $row;
	}
}
