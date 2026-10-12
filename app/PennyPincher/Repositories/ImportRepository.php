<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

class ImportRepository
{
	public const STATUS_UPLOADED = 'uploaded';

	public const STATUS_COMPLETED = 'completed';

	public const STATUS_FAILED = 'failed';

	public const STATUS_CANCELLED = 'cancelled';

	public function __construct(private Db $db)
	{
	}

	public function create(array $values): int
	{
		$now = $this->db->now();

		return $this->db->insert('imports', $values + ['created_at' => $now, 'updated_at' => $now]);
	}

	public function update(int $importId, array $values): void
	{
		foreach (['column_mapping', 'meta', 'errors'] as $json) {
			if (array_key_exists($json, $values) && is_array($values[$json])) {
				$values[$json] = wp_json_encode($values[$json]);
			}
		}

		$this->db->update('imports', $values + ['updated_at' => $this->db->now()], ['id' => $importId]);
	}

	/**
	 * Import zawsze w kontekście projektu – identyfikator spoza projektu zwraca null.
	 */
	public function findInProject(int $projectId, int $importId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('imports') . ' WHERE id = ? AND project_id = ?', [$importId, $projectId]);

		return $row ? $this->decode($row) : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function latest(int $projectId, int $limit = 20, int $offset = 0): array
	{
		$rows = $this->db->select(
			sprintf(
				'SELECT i.*, u.display_name AS user_name FROM %s i LEFT JOIN %s u ON u.ID = i.user_id
				WHERE i.project_id = ? ORDER BY i.created_at DESC, i.id DESC LIMIT %d OFFSET %d',
				$this->db->table('imports'),
				$this->db->wpdb()->users,
				$limit,
				$offset
			),
			[$projectId]
		);

		return array_map([$this, 'decode'], $rows);
	}

	public function count(int $projectId): int
	{
		return (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('imports') . ' WHERE project_id = ?', [$projectId]);
	}

	public function completedWithHash(int $projectId, string $hash, int $excludeId): ?array
	{
		$row = $this->db->first(
			'SELECT * FROM ' . $this->db->table('imports') . ' WHERE project_id = ? AND file_hash = ? AND status = ? AND id <> ? ORDER BY completed_at DESC LIMIT 1',
			[$projectId, $hash, self::STATUS_COMPLETED, $excludeId]
		);

		return $row ? $this->decode($row) : null;
	}

	/**
	 * @param array<int, array{line: int, cells: array<int, string|null>}> $rows
	 */
	public function stage(int $importId, array $rows): void
	{
		$records = array_map(fn (array $row) => [
			'import_id' => $importId,
			'line_no' => $row['line'],
			'cells' => wp_json_encode($row['cells'], JSON_UNESCAPED_UNICODE),
		], $rows);

		$this->db->insertMany('import_rows', $records, 300);
	}

	/**
	 * @return array<int, array{line: int, cells: array<int, string|null>}>
	 */
	public function staged(int $importId, ?int $limit = null, int $fromLine = 0): array
	{
		$sql = 'SELECT line_no, cells FROM ' . $this->db->table('import_rows') . ' WHERE import_id = ? AND line_no >= ? ORDER BY line_no';

		if ($limit !== null) {
			$sql .= ' LIMIT ' . (int) $limit;
		}

		return array_map(
			fn (array $row) => ['line' => (int) $row['line_no'], 'cells' => json_decode($row['cells'], true) ?: []],
			$this->db->select($sql, [$importId, $fromLine])
		);
	}

	public function clearStaged(int $importId): void
	{
		$this->db->statement('DELETE FROM ' . $this->db->table('import_rows') . ' WHERE import_id = ?', [$importId]);
	}

	private function decode(array $row): array
	{
		foreach (['column_mapping', 'meta', 'errors'] as $json) {
			$row[$json] = $row[$json] ? (json_decode($row[$json], true) ?: []) : [];
		}

		return $row;
	}
}
