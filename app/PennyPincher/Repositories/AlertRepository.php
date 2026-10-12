<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Support\Paginator;

/**
 * Alerty monitoringu (jeden na odcisk w projekcie), historia zdarzeń i przebiegi monitoringu.
 */
class AlertRepository
{
	public const STATUSES = ['new' => 'Nowy', 'acknowledged' => 'Potwierdzony', 'muted' => 'Wyciszony', 'resolved' => 'Rozwiązany'];

	public const SEVERITIES = ['critical' => 'Krytyczny', 'warning' => 'Ostrzeżenie', 'info' => 'Informacja'];

	public function __construct(private Db $db)
	{
	}

	public function newCount(int $projectId): int
	{
		return (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('alerts') . " WHERE project_id = ? AND status = 'new'", [$projectId]);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function byFingerprint(int $projectId): array
	{
		$map = [];

		foreach ($this->db->select('SELECT * FROM ' . $this->db->table('alerts') . ' WHERE project_id = ?', [$projectId]) as $row) {
			$map[$row['fingerprint']] = self::decode($row);
		}

		return $map;
	}

	public function paginate(int $projectId, array $filters, int $page, int $perPage = 25): Paginator
	{
		$where = ['project_id = ?'];
		$params = [$projectId];

		if (! empty($filters['status'])) {
			$where[] = 'status = ?';
			$params[] = $filters['status'];
		} else {
			$where[] = "status <> 'resolved'";
		}

		foreach (['severity', 'type'] as $field) {
			if (! empty($filters[$field])) {
				$where[] = "{$field} = ?";
				$params[] = $filters[$field];
			}
		}

		$sql = ' FROM ' . $this->db->table('alerts') . ' WHERE ' . implode(' AND ', $where);
		$paginator = new Paginator((int) $this->db->scalar('SELECT COUNT(*)' . $sql, $params), $page, $perPage);
		$rows = $this->db->select('SELECT *' . $sql . " ORDER BY FIELD(status, 'new', 'acknowledged', 'muted', 'resolved'), FIELD(severity, 'critical', 'warning', 'info'), last_detected_at DESC, id DESC LIMIT " . $paginator->perPage . ' OFFSET ' . $paginator->offset(), $params);

		return $paginator->withItems(array_map([self::class, 'decode'], $rows));
	}

	/**
	 * @return array<string, int>
	 */
	public function counts(int $projectId): array
	{
		$rows = $this->db->select('SELECT status, COUNT(*) AS n FROM ' . $this->db->table('alerts') . ' WHERE project_id = ? GROUP BY status', [$projectId]);

		return array_map('intval', array_column($rows, 'n', 'status'));
	}

	public function find(int $projectId, int $id): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('alerts') . ' WHERE id = ? AND project_id = ?', [$id, $projectId]);

		return $row ? self::decode($row) : null;
	}

	public function insert(array $values): int
	{
		$values['evidence'] = wp_json_encode($values['evidence'] ?? []);

		return $this->db->insert('alerts', $values);
	}

	public function update(int $id, array $values): void
	{
		if (isset($values['evidence']) && is_array($values['evidence'])) {
			$values['evidence'] = wp_json_encode($values['evidence']);
		}

		$this->db->update('alerts', $values + ['updated_at' => $this->db->now()], ['id' => $id]);
	}

	public function addEvent(int $alertId, int $projectId, string $event, ?int $userId = null, ?string $from = null, ?string $to = null, ?string $note = null): void
	{
		$this->db->insert('alert_events', ['alert_id' => $alertId, 'project_id' => $projectId, 'user_id' => $userId, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => $this->db->now()]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function events(int $projectId, int $alertId): array
	{
		return $this->db->select(
			sprintf('SELECT e.*, u.display_name AS user_name FROM %s e LEFT JOIN %s u ON u.ID = e.user_id WHERE e.project_id = ? AND e.alert_id = ? ORDER BY e.id DESC LIMIT 50', $this->db->table('alert_events'), $this->db->wpdb()->users),
			[$projectId, $alertId]
		);
	}

	/**
	 * Zapis przebiegu; false, gdy ten przebieg synchronizacji był już monitorowany (idempotencja).
	 */
	public function startRun(int $projectId, ?int $syncRunId, string $trigger): ?int
	{
		if ($syncRunId !== null && $this->db->scalar('SELECT 1 FROM ' . $this->db->table('monitoring_runs') . ' WHERE project_id = ? AND sync_run_id = ?', [$projectId, $syncRunId]) !== null) {
			return null;
		}

		return $this->db->insert('monitoring_runs', ['project_id' => $projectId, 'sync_run_id' => $syncRunId, 'trigger_type' => $trigger, 'created_at' => $this->db->now()]);
	}

	public function finishRun(int $runId, array $summary): void
	{
		$this->db->update('monitoring_runs', ['summary' => wp_json_encode($summary)], ['id' => $runId]);
	}

	public function lastRun(int $projectId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('monitoring_runs') . ' WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);

		if ($row) {
			$row['summary'] = $row['summary'] ? (json_decode((string) $row['summary'], true) ?: []) : [];
		}

		return $row ?: null;
	}

	/**
	 * Alerty do powiadomienia e-mail: nowe, niewyciszone i jeszcze niewysłane (ponowne otwarcie zeruje znacznik wysyłki).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pendingNotifications(int $projectId, array $severities): array
	{
		if ($severities === []) {
			return [];
		}

		return array_map([self::class, 'decode'], $this->db->select(
			'SELECT * FROM ' . $this->db->table('alerts') . " WHERE project_id = ? AND status = 'new' AND severity IN (" . implode(',', array_fill(0, count($severities), '?')) . ') AND last_notified_at IS NULL ORDER BY FIELD(severity, \'critical\', \'warning\', \'info\'), id LIMIT 50',
			[$projectId, ...$severities]
		));
	}

	private static function decode(array $row): array
	{
		$row['evidence'] = $row['evidence'] ? (json_decode((string) $row['evidence'], true) ?: []) : [];
		$row['condition_active'] = (bool) $row['condition_active'];

		return $row;
	}
}
