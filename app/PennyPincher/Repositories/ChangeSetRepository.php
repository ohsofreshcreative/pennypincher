<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Support\Paginator;

/**
 * Change Center: zestawy zmian, operacje, historia, próby kontaktu z Google Ads, powiązania z zasobami
 * i odczyty stanu. Każde zapytanie jest zawężone do projektu (albo identyfikatora zestawu już zawężonego).
 */
class ChangeSetRepository
{
	private const JSON_SET = ['validation'];

	private const JSON_OP = ['payload', 'before_state', 'current_state', 'warnings', 'verification', 'depends_on'];

	public function __construct(private Db $db)
	{
	}

	// --- Zestawy ----------------------------------------------------------------------------------

	public function create(array $values): int
	{
		$now = $this->db->now();

		return $this->db->insert('change_sets', self::encode($values, self::JSON_SET) + ['created_at' => $now, 'updated_at' => $now]);
	}

	public function find(int $projectId, int $id): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('change_sets') . ' WHERE project_id = ? AND id = ?', [$projectId, $id]);

		return $row ? self::decodeSet($row) : null;
	}

	/**
	 * Odczyt bez zawężenia do projektu – wyłącznie dla workera (projekt sprawdzany potem z zestawu).
	 */
	public function findForWorker(int $id): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('change_sets') . ' WHERE id = ?', [$id]);

		return $row ? self::decodeSet($row) : null;
	}

	public function update(int $id, array $values): void
	{
		$this->db->update('change_sets', self::encode($values, self::JSON_SET) + ['updated_at' => $this->db->now()], ['id' => $id]);
	}

	/**
	 * Zmiana statusu tylko z oczekiwanego stanu (ochrona przed wyścigiem, np. podwójnym kliknięciem). Zwraca, czy się udało.
	 *
	 * @param array<int, string> $from
	 */
	public function transition(int $id, array $from, string $to, array $values = []): bool
	{
		$set = ['status = ?', 'updated_at = ?'];
		$params = [$to, $this->db->now()];

		foreach (self::encode($values, self::JSON_SET) as $column => $value) {
			$set[] = "{$column} = ?";
			$params[] = $value;
		}

		$params[] = $id;

		return $this->db->statement(
			'UPDATE ' . $this->db->table('change_sets') . ' SET ' . implode(', ', $set) . ' WHERE id = ? AND status IN (' . implode(',', array_fill(0, count($from), '?')) . ')',
			[...$params, ...$from]
		) === 1;
	}

	/**
	 * Przejęcie zestawu przez workera – tylko jeden proces może go przetwarzać.
	 */
	public function claim(int $id, string $token): bool
	{
		return $this->db->statement(
			'UPDATE ' . $this->db->table('change_sets') . " SET worker_token = ?, deploy_started_at = ?, attempts = attempts + 1, updated_at = ?
			WHERE id = ? AND status = ? AND worker_token IS NULL AND (next_attempt_at IS NULL OR next_attempt_at <= ?)",
			[$token, $this->db->now(), $this->db->now(), $id, ChangeStatus::DEPLOYING, $this->db->now()]
		) === 1;
	}

	/**
	 * Zestawy w kolejce (jeszcze nieprzejęte przez workera) dla połączenia → „Gotowy do wdrożenia”. Zwraca liczbę.
	 * Zestaw już przetwarzany zatrzyma worker przed kolejnym żądaniem (ponowna kontrola zgody).
	 */
	public function stopQueued(int $projectId, int $connectionId, ?int $userId, string $note): int
	{
		$ids = array_map('intval', $this->db->column(
			'SELECT id FROM ' . $this->db->table('change_sets') . ' WHERE project_id = ? AND connection_id = ? AND status = ? AND worker_token IS NULL',
			[$projectId, $connectionId, ChangeStatus::DEPLOYING]
		));
		$stopped = 0;

		foreach ($ids as $id) {
			// Tylko zestaw nieprzejęty przez workera (worker_token IS NULL) – bez wyścigu z przetwarzaniem.
			$moved = $this->db->statement(
				'UPDATE ' . $this->db->table('change_sets') . ' SET status = ?, deploy_key = NULL, next_attempt_at = NULL, last_error = ?, updated_at = ? WHERE id = ? AND status = ? AND worker_token IS NULL',
				[ChangeStatus::READY, mb_substr($note, 0, 1000), $this->db->now(), $id, ChangeStatus::DEPLOYING]
			) === 1;

			if ($moved) {
				$this->addEvent($id, $projectId, 'deploy_stopped', $userId, ChangeStatus::DEPLOYING, ChangeStatus::READY, $note);
				$stopped++;
			}
		}

		return $stopped;
	}

	public function release(int $id): void
	{
		$this->db->update('change_sets', ['worker_token' => null, 'updated_at' => $this->db->now()], ['id' => $id]);
	}

	/**
	 * Zestawy czekające na wdrożenie (kolejka workera). Przejęte, ale porzucone > 30 min – patrz `stale()`.
	 *
	 * @return array<int, int>
	 */
	public function due(int $limit = 10): array
	{
		return array_map('intval', $this->db->column(
			'SELECT id FROM ' . $this->db->table('change_sets') . ' WHERE status = ? AND worker_token IS NULL AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY deploy_requested_at, id LIMIT ' . max(1, $limit),
			[ChangeStatus::DEPLOYING, $this->db->now()]
		));
	}

	/**
	 * Zestawy przejęte przez workera, który nie zakończył pracy (np. restart) – wymagają uzgodnienia, nie ponowienia.
	 *
	 * @return array<int, int>
	 */
	public function stale(int $minutes = 30): array
	{
		return array_map('intval', $this->db->column(
			'SELECT id FROM ' . $this->db->table('change_sets') . ' WHERE status = ? AND worker_token IS NOT NULL AND deploy_started_at < ?',
			[ChangeStatus::DEPLOYING, gmdate('Y-m-d H:i:s', time() - $minutes * 60)]
		));
	}

	public function paginate(int $projectId, array $filters, int $page, int $perPage = 20): Paginator
	{
		$where = ['s.project_id = ?'];
		$params = [$projectId];

		if (! empty($filters['status']) && array_key_exists($filters['status'], ChangeStatus::LABELS)) {
			$where[] = 's.status = ?';
			$params[] = $filters['status'];
		} elseif (($filters['status'] ?? '') === '') {
			$where[] = "s.status <> 'cancelled'";
		}

		if (! empty($filters['source']) && preg_match('/^[a-z_]+$/', $filters['source'])) {
			$where[] = 's.source_type = ?';
			$params[] = $filters['source'];
		}

		$sql = ' FROM ' . $this->db->table('change_sets') . ' s LEFT JOIN ' . $this->db->wpdb()->users . ' u ON u.ID = s.created_by WHERE ' . implode(' AND ', $where);
		$paginator = new Paginator((int) $this->db->scalar('SELECT COUNT(*)' . $sql, $params), $page, $perPage);
		$rows = $this->db->select(
			'SELECT s.*, u.display_name AS author_name, (SELECT COUNT(*) FROM ' . $this->db->table('change_operations') . ' o WHERE o.change_set_id = s.id) AS operation_count'
			. $sql . ' ORDER BY s.updated_at DESC, s.id DESC LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
			$params
		);

		return $paginator->withItems(array_map([self::class, 'decodeSet'], $rows));
	}

	/**
	 * @return array<string, int>
	 */
	public function counts(int $projectId): array
	{
		return array_map('intval', array_column($this->db->select('SELECT status, COUNT(*) AS n FROM ' . $this->db->table('change_sets') . ' WHERE project_id = ? GROUP BY status', [$projectId]), 'n', 'status'));
	}

	/**
	 * Otwarte (niezakończone) zestawy utworzone z danego źródła – ochrona przed dublowaniem szkiców.
	 */
	public function openForSource(int $projectId, string $sourceType, int $sourceId): ?array
	{
		$row = $this->db->first(
			'SELECT * FROM ' . $this->db->table('change_sets') . " WHERE project_id = ? AND source_type = ? AND source_id = ? AND status NOT IN ('cancelled', 'applied', 'partially_applied', 'failed') ORDER BY id DESC LIMIT 1",
			[$projectId, $sourceType, $sourceId]
		);

		return $row ? self::decodeSet($row) : null;
	}

	/**
	 * Zestawy powiązane ze źródłami (np. rekomendacjami) – do pokazania statusu przy źródle.
	 *
	 * @param array<int, int> $sourceIds
	 * @return array<int, array<string, mixed>> klucz = source_id (najnowszy zestaw)
	 */
	public function latestForSources(int $projectId, string $sourceType, array $sourceIds): array
	{
		$sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds))));

		if ($sourceIds === []) {
			return [];
		}

		$rows = $this->db->select(
			'SELECT id, source_id, status, deploy_finished_at, verified_at, updated_at FROM ' . $this->db->table('change_sets')
			. ' WHERE project_id = ? AND source_type = ? AND source_id IN (' . implode(',', array_fill(0, count($sourceIds), '?')) . ") AND status <> 'cancelled' ORDER BY id",
			[$projectId, $sourceType, ...$sourceIds]
		);
		$map = [];

		foreach ($rows as $row) {
			$map[(int) $row['source_id']] = $row;
		}

		return $map;
	}

	// --- Operacje ---------------------------------------------------------------------------------

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function operations(int $setId): array
	{
		return array_map([self::class, 'decodeOp'], $this->db->select('SELECT * FROM ' . $this->db->table('change_operations') . ' WHERE change_set_id = ? ORDER BY position, id', [$setId]));
	}

	public function findOperation(int $setId, int $id): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('change_operations') . ' WHERE change_set_id = ? AND id = ?', [$setId, $id]);

		return $row ? self::decodeOp($row) : null;
	}

	public function insertOperation(array $values): int
	{
		$now = $this->db->now();

		return $this->db->insert('change_operations', self::encode($values, self::JSON_OP) + ['created_at' => $now, 'updated_at' => $now]);
	}

	public function updateOperation(int $id, array $values): void
	{
		$this->db->update('change_operations', self::encode($values, self::JSON_OP) + ['updated_at' => $this->db->now()], ['id' => $id]);
	}

	public function deleteOperation(int $setId, int $id): void
	{
		$this->db->statement('DELETE FROM ' . $this->db->table('change_operations') . ' WHERE change_set_id = ? AND id = ?', [$setId, $id]);
	}

	public function nextPosition(int $setId): int
	{
		return (int) $this->db->scalar('SELECT COALESCE(MAX(position), 0) + 1 FROM ' . $this->db->table('change_operations') . ' WHERE change_set_id = ?', [$setId]);
	}

	/**
	 * Wdrożone operacje projektu (do historii i efektów).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function appliedOperations(int $projectId, int $limit = 200): array
	{
		return array_map([self::class, 'decodeOp'], $this->db->select(
			'SELECT o.*, s.title AS set_title, s.deploy_finished_at, s.customer_id FROM ' . $this->db->table('change_operations') . ' o INNER JOIN ' . $this->db->table('change_sets') . " s ON s.id = o.change_set_id
			WHERE o.project_id = ? AND o.status IN ('applied', 'verified', 'verify_mismatch') ORDER BY o.applied_at DESC LIMIT " . max(1, $limit),
			[$projectId]
		));
	}

	// --- Historia i próby -------------------------------------------------------------------------

	public function addEvent(int $setId, int $projectId, string $event, ?int $userId = null, ?string $from = null, ?string $to = null, ?string $note = null, ?int $operationId = null): void
	{
		$this->db->insert('change_events', [
			'change_set_id' => $setId, 'project_id' => $projectId, 'operation_id' => $operationId, 'user_id' => $userId, 'event' => $event,
			'from_status' => $from, 'to_status' => $to, 'note' => $note === null ? null : mb_substr($note, 0, 2000), 'created_at' => $this->db->now(),
		]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function events(int $projectId, int $setId): array
	{
		return $this->db->select(
			sprintf('SELECT e.*, u.display_name AS user_name FROM %s e LEFT JOIN %s u ON u.ID = e.user_id WHERE e.project_id = ? AND e.change_set_id = ? ORDER BY e.id DESC LIMIT 200', $this->db->table('change_events'), $this->db->wpdb()->users),
			[$projectId, $setId]
		);
	}

	/**
	 * Zapis próby kontaktu z Google Ads (bez treści odpowiedzi, tokenów i danych osobowych).
	 *
	 * @param array<int, int> $operationIds
	 */
	public function addAttempt(int $setId, int $projectId, string $kind, string $outcome, array $operationIds = [], array $details = []): void
	{
		$this->db->insert('change_attempts', [
			'change_set_id' => $setId, 'project_id' => $projectId, 'operation_ids' => mb_substr(implode(',', $operationIds), 0, 500), 'kind' => $kind, 'outcome' => $outcome,
			'customer_id' => $details['customer_id'] ?? null, 'http_status' => $details['http_status'] ?? null, 'request_id' => isset($details['request_id']) ? mb_substr((string) $details['request_id'], 0, 100) : null,
			'error_code' => isset($details['error_code']) ? mb_substr((string) $details['error_code'], 0, 100) : null,
			'message' => isset($details['message']) ? mb_substr(self::scrub((string) $details['message']), 0, 1000) : null,
			'user_id' => $details['user_id'] ?? null, 'created_at' => $this->db->now(),
		]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function attempts(int $projectId, int $setId): array
	{
		return $this->db->select('SELECT * FROM ' . $this->db->table('change_attempts') . ' WHERE project_id = ? AND change_set_id = ? ORDER BY id DESC LIMIT 200', [$projectId, $setId]);
	}

	// --- Powiązania i odczyty stanu ---------------------------------------------------------------

	public function link(int $projectId, string $customerId, string $localType, int $localId, string $resourceType, string $resourceName, ?int $operationId): void
	{
		$this->db->statement(
			'INSERT INTO ' . $this->db->table('google_ads_links') . ' (project_id, customer_id, local_type, local_id, resource_type, resource_name, change_operation_id, created_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE resource_name = VALUES(resource_name), resource_type = VALUES(resource_type), change_operation_id = VALUES(change_operation_id)',
			[$projectId, $customerId, $localType, $localId, $resourceType, $resourceName, $operationId, $this->db->now()]
		);
	}

	/**
	 * @param array<int, int> $localIds
	 * @return array<int, array<string, mixed>> klucz = local_id
	 */
	public function links(int $projectId, string $customerId, string $localType, array $localIds): array
	{
		$localIds = array_values(array_unique(array_map('intval', $localIds)));

		if ($localIds === []) {
			return [];
		}

		$rows = $this->db->select(
			'SELECT * FROM ' . $this->db->table('google_ads_links') . ' WHERE project_id = ? AND customer_id = ? AND local_type = ? AND local_id IN (' . implode(',', array_fill(0, count($localIds), '?')) . ')',
			[$projectId, $customerId, $localType, ...$localIds]
		);

		return array_column($rows, null, 'local_id');
	}

	public function saveSnapshot(int $projectId, string $customerId, string $kind, string $scope, array $data): void
	{
		$this->db->statement(
			'INSERT INTO ' . $this->db->table('google_ads_snapshots') . ' (project_id, customer_id, kind, scope_key, data, read_at) VALUES (?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE data = VALUES(data), read_at = VALUES(read_at)',
			[$projectId, $customerId, $kind, mb_substr($scope, 0, 191), wp_json_encode($data), $this->db->now()]
		);
	}

	/**
	 * @return array{data: array, read_at: string}|null
	 */
	public function snapshot(int $projectId, string $customerId, string $kind, string $scope): ?array
	{
		$row = $this->db->first('SELECT data, read_at FROM ' . $this->db->table('google_ads_snapshots') . ' WHERE project_id = ? AND customer_id = ? AND kind = ? AND scope_key = ?', [$projectId, $customerId, $kind, mb_substr($scope, 0, 191)]);

		return $row ? ['data' => json_decode((string) $row['data'], true) ?: [], 'read_at' => (string) $row['read_at']] : null;
	}

	// --- Kodowanie --------------------------------------------------------------------------------

	private static function encode(array $values, array $json): array
	{
		foreach ($json as $column) {
			if (array_key_exists($column, $values) && $values[$column] !== null && ! is_string($values[$column])) {
				$values[$column] = wp_json_encode($values[$column]);
			}
		}

		return $values;
	}

	public static function decodeSet(array $row): array
	{
		$row['validation'] = $row['validation'] ? (json_decode((string) $row['validation'], true) ?: []) : [];

		foreach (['id', 'project_id', 'organization_id', 'version', 'attempts'] as $int) {
			$row[$int] = (int) $row[$int];
		}

		return $row;
	}

	public static function decodeOp(array $row): array
	{
		foreach (self::JSON_OP as $column) {
			$row[$column] = isset($row[$column]) && $row[$column] !== null ? json_decode((string) $row[$column], true) : null;
		}

		$row['payload'] = is_array($row['payload']) ? $row['payload'] : [];
		$row['warnings'] = is_array($row['warnings']) ? $row['warnings'] : [];
		$row['depends_on'] = is_array($row['depends_on']) ? $row['depends_on'] : [];
		$row['id'] = (int) $row['id'];
		$row['change_set_id'] = (int) $row['change_set_id'];

		return $row;
	}

	/**
	 * Usunięcie z komunikatów czegokolwiek, co wygląda na token (Bearer, ya29.…, 1//…).
	 */
	public static function scrub(string $message): string
	{
		return (string) preg_replace(['/Bearer\s+[A-Za-z0-9._\-]+/i', '/ya29\.[A-Za-z0-9._\-]+/', '#1//[A-Za-z0-9._\-]+#'], ['Bearer [ukryto]', '[ukryto]', '[ukryto]'], $message);
	}
}
