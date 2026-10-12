<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Działania Action Plan (jedno na odcisk w projekcie) i ich historia.
 */
class ActionItemRepository
{
	public const STATUSES = ['todo' => 'Do zrobienia', 'in_progress' => 'W trakcie', 'blocked' => 'Zablokowane', 'done' => 'Wykonane', 'rejected' => 'Odrzucone'];

	public function __construct(private Db $db)
	{
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function byFingerprint(int $projectId): array
	{
		$map = [];

		foreach ($this->db->select('SELECT * FROM ' . $this->db->table('action_items') . ' WHERE project_id = ?', [$projectId]) as $row) {
			$map[$row['fingerprint']] = self::decode($row);
		}

		return $map;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list(int $projectId, array $filters = []): array
	{
		$where = ['project_id = ?'];
		$params = [$projectId];

		if (($filters['obsolete'] ?? 'active') === 'active') {
			$where[] = 'is_obsolete = 0';
		} elseif (($filters['obsolete'] ?? '') === 'obsolete') {
			$where[] = 'is_obsolete = 1';
		}

		foreach (['status', 'category', 'priority'] as $field) {
			if (! empty($filters[$field])) {
				$where[] = "{$field} = ?";
				$params[] = $filters[$field];
			}
		}

		return array_map([self::class, 'decode'], $this->db->select(
			'SELECT * FROM ' . $this->db->table('action_items') . ' WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(status, 'in_progress', 'todo', 'blocked', 'done', 'rejected'), position, priority_score DESC, id LIMIT 300",
			$params
		));
	}

	public function find(int $projectId, int $id): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('action_items') . ' WHERE id = ? AND project_id = ?', [$id, $projectId]);

		return $row ? self::decode($row) : null;
	}

	/**
	 * @return array<string, int>
	 */
	public function counts(int $projectId): array
	{
		$rows = $this->db->select('SELECT status, COUNT(*) AS n FROM ' . $this->db->table('action_items') . ' WHERE project_id = ? AND is_obsolete = 0 GROUP BY status', [$projectId]);

		return array_map('intval', array_column($rows, 'n', 'status'));
	}

	public function insert(array $values): int
	{
		return $this->db->insert('action_items', self::encode($values));
	}

	public function update(int $id, array $values): void
	{
		$this->db->update('action_items', self::encode($values) + ['updated_at' => $this->db->now()], ['id' => $id]);
	}

	public function addEvent(int $itemId, int $projectId, string $event, ?int $userId = null, ?string $from = null, ?string $to = null, ?string $note = null): void
	{
		$this->db->insert('action_item_events', ['action_item_id' => $itemId, 'project_id' => $projectId, 'user_id' => $userId, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => $this->db->now()]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function events(int $projectId, int $itemId): array
	{
		return $this->db->select(
			sprintf('SELECT e.*, u.display_name AS user_name FROM %s e LEFT JOIN %s u ON u.ID = e.user_id WHERE e.project_id = ? AND e.action_item_id = ? ORDER BY e.id DESC LIMIT 50', $this->db->table('action_item_events'), $this->db->wpdb()->users),
			[$projectId, $itemId]
		);
	}

	private static function encode(array $values): array
	{
		foreach (['details', 'recommendation_ids', 'depends_on'] as $json) {
			if (array_key_exists($json, $values) && is_array($values[$json])) {
				$values[$json] = wp_json_encode($values[$json]);
			}
		}

		if (isset($values['sources']) && is_array($values['sources'])) {
			$values['sources'] = implode(',', $values['sources']);
		}

		return $values;
	}

	private static function decode(array $row): array
	{
		foreach (['details', 'recommendation_ids', 'depends_on'] as $json) {
			$row[$json] = $row[$json] ? (json_decode((string) $row[$json], true) ?: []) : [];
		}

		$row['sources'] = $row['sources'] ? explode(',', (string) $row['sources']) : [];
		$row['is_obsolete'] = (bool) $row['is_obsolete'];
		$row['auto_blocked'] = (bool) $row['auto_blocked'];

		return $row;
	}
}
