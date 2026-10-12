<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

class AuditRepository
{
	public const STATUSES = ['new' => 'Nowy', 'reviewed' => 'Przejrzany', 'dismissed' => 'Pominięty'];

	public function __construct(private Db $db)
	{
	}

	public function createRun(array $values): int
	{
		foreach (['score_breakdown', 'data_quality'] as $json) {
			if (isset($values[$json]) && is_array($values[$json])) {
				$values[$json] = wp_json_encode($values[$json]);
			}
		}

		return $this->db->insert('audit_runs', $values + ['created_at' => $this->db->now()]);
	}

	public function updateRun(int $runId, array $values): void
	{
		if (isset($values['score_breakdown']) && is_array($values['score_breakdown'])) {
			$values['score_breakdown'] = wp_json_encode($values['score_breakdown']);
		}

		$this->db->update('audit_runs', $values, ['id' => $runId]);
	}

	public function insertFindings(array $rows): void
	{
		foreach ($rows as &$row) {
			$row['evidence'] = wp_json_encode($row['evidence']);
		}

		$this->db->insertMany('audit_findings', $rows);
	}

	public function latestRun(int $projectId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('audit_runs') . ' WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);

		return $row ? $this->decodeRun($row) : null;
	}

	/**
	 * Najnowszy audyt dokładnie tego okresu (te same daty) – audyty różnych okresów się nie nadpisują.
	 */
	public function latestRunForPeriod(int $projectId, string $from, string $to): ?array
	{
		$row = $this->db->first(
			'SELECT * FROM ' . $this->db->table('audit_runs') . ' WHERE project_id = ? AND date_from = ? AND date_to = ? ORDER BY id DESC LIMIT 1',
			[$projectId, $from, $to]
		);

		return $row ? $this->decodeRun($row) : null;
	}

	public function findRun(int $projectId, int $runId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('audit_runs') . ' WHERE id = ? AND project_id = ?', [$runId, $projectId]);

		return $row ? $this->decodeRun($row) : null;
	}

	/**
	 * Historia audytów z liczbą problemów wg ważności (bez pominiętych).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function runs(int $projectId, int $limit = 20): array
	{
		$rows = $this->db->select(
			sprintf(
				"SELECT r.*, SUM(f.severity = 'critical' AND f.status <> 'dismissed') AS critical_count,
					SUM(f.severity = 'warning' AND f.status <> 'dismissed') AS warning_count,
					SUM(f.severity = 'info' AND f.status <> 'dismissed') AS info_count
				FROM %s r LEFT JOIN %s f ON f.audit_run_id = r.id
				WHERE r.project_id = ? GROUP BY r.id ORDER BY r.id DESC LIMIT %d",
				$this->db->table('audit_runs'),
				$this->db->table('audit_findings'),
				$limit
			),
			[$projectId]
		);

		return array_map([$this, 'decodeRun'], $rows);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function findings(int $runId): array
	{
		$rows = $this->db->select(
			sprintf(
				"SELECT * FROM %s WHERE audit_run_id = ?
				ORDER BY (status = 'dismissed'), FIELD(severity, 'critical', 'warning', 'info'), penalty DESC, id",
				$this->db->table('audit_findings')
			),
			[$runId]
		);

		return array_map([$this, 'decodeFinding'], $rows);
	}

	public function findFinding(int $projectId, int $findingId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('audit_findings') . ' WHERE id = ? AND project_id = ?', [$findingId, $projectId]);

		return $row ? $this->decodeFinding($row) : null;
	}

	public function updateFinding(int $findingId, array $values): void
	{
		$this->db->update('audit_findings', $values, ['id' => $findingId]);
	}

	/**
	 * Ostatni status nadany ręcznie dla każdego odcisku problemu w projekcie (do przeniesienia na nowy audyt).
	 *
	 * @return array<string, array{status: string, status_changed_by: ?int, status_changed_at: ?string}>
	 */
	public function lastStatuses(int $projectId): array
	{
		$rows = $this->db->select(
			sprintf(
				'SELECT f.fingerprint, f.status, f.status_changed_by, f.status_changed_at FROM %1$s f
				INNER JOIN (SELECT fingerprint, MAX(status_changed_at) AS changed FROM %1$s WHERE project_id = ? AND status_changed_at IS NOT NULL GROUP BY fingerprint) last
					ON last.fingerprint = f.fingerprint AND last.changed = f.status_changed_at
				WHERE f.project_id = ?',
				$this->db->table('audit_findings')
			),
			[$projectId, $projectId]
		);

		$statuses = [];

		foreach ($rows as $row) {
			$statuses[$row['fingerprint']] = [
				'status' => (string) $row['status'],
				'status_changed_by' => $row['status_changed_by'] === null ? null : (int) $row['status_changed_by'],
				'status_changed_at' => $row['status_changed_at'],
			];
		}

		return $statuses;
	}

	private function decodeRun(array $row): array
	{
		foreach (['score_breakdown', 'data_quality'] as $json) {
			$row[$json] = $row[$json] ? (json_decode($row[$json], true) ?: []) : [];
		}

		$row['score'] = $row['score'] === null ? null : (int) $row['score'];

		return $row;
	}

	private function decodeFinding(array $row): array
	{
		$row['evidence'] = $row['evidence'] ? (json_decode($row['evidence'], true) ?: []) : [];
		$row['penalty'] = (float) $row['penalty'];

		return $row;
	}
}
