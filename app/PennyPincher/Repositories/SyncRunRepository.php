<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

class SyncRunRepository
{
	public const QUEUED = 'queued';

	public const RUNNING = 'running';

	public const COMPLETED = 'completed';

	public const FAILED = 'failed';

	public const CANCELLED = 'cancelled';

	public function __construct(private Db $db)
	{
	}

	public function create(array $values): int
	{
		$now = $this->db->now();
		$values['steps'] = wp_json_encode($values['steps']);

		return $this->db->insert('sync_runs', $values + ['created_at' => $now, 'updated_at' => $now]);
	}

	public function update(int $runId, array $values): void
	{
		$this->db->update('sync_runs', $values + ['updated_at' => $this->db->now()], ['id' => $runId]);
	}

	public function find(int $runId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('sync_runs') . ' WHERE id = ?', [$runId]);

		return $row ? $this->decode($row) : null;
	}

	public function findInProject(int $projectId, int $runId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('sync_runs') . ' WHERE id = ? AND project_id = ?', [$runId, $projectId]);

		return $row ? $this->decode($row) : null;
	}

	/**
	 * Niezakończony przebieg połączenia (kolejka albo w toku) – blokuje dodanie kolejnego.
	 */
	public function openForConnection(int $connectionId): ?array
	{
		$row = $this->db->first(
			'SELECT * FROM ' . $this->db->table('sync_runs') . ' WHERE connection_id = ? AND status IN (?, ?) ORDER BY id LIMIT 1',
			[$connectionId, self::QUEUED, self::RUNNING]
		);

		return $row ? $this->decode($row) : null;
	}

	public function lastCompleted(int $connectionId, ?string $type = null): ?array
	{
		$sql = 'SELECT * FROM ' . $this->db->table('sync_runs') . ' WHERE connection_id = ? AND status = ?';
		$params = [$connectionId, self::COMPLETED];

		if ($type !== null) {
			$sql .= ' AND type = ?';
			$params[] = $type;
		}

		$row = $this->db->first($sql . ' ORDER BY finished_at DESC LIMIT 1', $params);

		return $row ? $this->decode($row) : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function due(int $limit = 10): array
	{
		$rows = $this->db->select(
			'SELECT * FROM ' . $this->db->table('sync_runs') . ' WHERE status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT ' . (int) $limit,
			[self::QUEUED, $this->db->now()]
		);

		return array_map([$this, 'decode'], $rows);
	}

	/**
	 * Przebiegi „w toku” bez aktywności – proces przerwany (timeout, restart). Wracają do kolejki.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function stale(int $minutes = 30): array
	{
		$rows = $this->db->select(
			'SELECT * FROM ' . $this->db->table('sync_runs') . ' WHERE status = ? AND updated_at < ?',
			[self::RUNNING, gmdate('Y-m-d H:i:s', time() - $minutes * 60)]
		);

		return array_map([$this, 'decode'], $rows);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function recentForProject(int $projectId, int $limit = 10): array
	{
		$rows = $this->db->select(
			'SELECT * FROM ' . $this->db->table('sync_runs') . ' WHERE project_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
			[$projectId]
		);

		return array_map([$this, 'decode'], $rows);
	}

	public function cancelOpen(int $connectionId): void
	{
		$this->db->statement(
			'UPDATE ' . $this->db->table('sync_runs') . ' SET status = ?, updated_at = ?, finished_at = ? WHERE connection_id = ? AND status IN (?, ?)',
			[self::CANCELLED, $this->db->now(), $this->db->now(), $connectionId, self::QUEUED, self::RUNNING]
		);
	}

	private function decode(array $row): array
	{
		$row['steps'] = json_decode((string) $row['steps'], true) ?: [];
		$row['log'] = $row['log'] ? (json_decode((string) $row['log'], true) ?: []) : [];

		return $row;
	}
}
