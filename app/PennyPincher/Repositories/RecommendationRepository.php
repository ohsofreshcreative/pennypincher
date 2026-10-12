<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Support\Paginator;

/**
 * Rekomendacje projektu – jedna pozycja na odcisk (fingerprint), aktualizowana przy każdej analizie.
 * Status, notatka i historia zmian należą do użytkownika i nie są nadpisywane przez ponowną analizę.
 */
class RecommendationRepository
{
	public const MANUAL_OBSOLETE = 'Oznaczona ręcznie jako nieaktualna';

	public const SORTS = [
		'priority' => 'priority_score DESC, id DESC',
		'impact' => 'impact_micros IS NULL, impact_micros DESC, priority_score DESC',
		'newest' => 'first_seen_at DESC, id DESC',
		'updated' => 'updated_at DESC, id DESC',
	];

	public function __construct(private Db $db)
	{
	}

	/**
	 * @return array<string, array<string, mixed>> klucz = fingerprint
	 */
	public function byFingerprint(int $projectId): array
	{
		$rows = $this->db->select('SELECT * FROM ' . $this->db->table('recommendations') . ' WHERE project_id = ?', [$projectId]);
		$map = [];

		foreach ($rows as $row) {
			$map[$row['fingerprint']] = self::decode($row);
		}

		return $map;
	}

	public function insert(array $values): int
	{
		$values['evidence'] = wp_json_encode($values['evidence'] ?? []);

		return $this->db->insert('recommendations', $values);
	}

	public function update(int $id, array $values): void
	{
		if (array_key_exists('evidence', $values) && is_array($values['evidence'])) {
			$values['evidence'] = wp_json_encode($values['evidence']);
		}

		$this->db->update('recommendations', $values, ['id' => $id]);
	}

	public function find(int $projectId, int $id): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('recommendations') . ' WHERE id = ? AND project_id = ?', [$id, $projectId]);

		return $row ? self::decode($row) : null;
	}

	/**
	 * @param array{status?: string, priority?: string, source?: string, campaign_id?: int, obsolete?: string, from?: string, to?: string, sort?: string} $filters
	 */
	public function paginate(int $projectId, array $filters = [], int $page = 1, int $perPage = 25): Paginator
	{
		$where = ['project_id = ?'];
		$params = [$projectId];

		foreach (['status', 'priority', 'source', 'campaign_id', 'confidence'] as $field) {
			if (isset($filters[$field]) && $filters[$field] !== '' && $filters[$field] !== null) {
				$where[] = "{$field} = ?";
				$params[] = $filters[$field];
			}
		}

		$obsolete = $filters['obsolete'] ?? 'active';

		if ($obsolete === 'active') {
			$where[] = 'is_obsolete = 0';
		} elseif ($obsolete === 'obsolete') {
			$where[] = 'is_obsolete = 1';
		}

		if (! empty($filters['from']) && ! empty($filters['to'])) {
			$where[] = 'date_from = ? AND date_to = ?';
			array_push($params, $filters['from'], $filters['to']);
		}

		$sql = ' FROM ' . $this->db->table('recommendations') . ' WHERE ' . implode(' AND ', $where);
		$order = self::SORTS[$filters['sort'] ?? 'priority'] ?? self::SORTS['priority'];
		$paginator = new Paginator((int) $this->db->scalar('SELECT COUNT(*)' . $sql, $params), $page, $perPage);
		$statusOrder = ($filters['sort'] ?? 'priority') === 'priority' ? "FIELD(status, 'new', 'to_review', 'accepted', 'done', 'rejected'), " : '';
		$rows = $this->db->select('SELECT *' . $sql . " ORDER BY {$statusOrder}{$order} LIMIT " . $paginator->perPage . ' OFFSET ' . $paginator->offset(), $params);

		return $paginator->withItems(array_map([self::class, 'decode'], $rows));
	}

	/**
	 * Liczba aktywnych rekomendacji wg statusu.
	 *
	 * @return array<string, int>
	 */
	public function counts(int $projectId): array
	{
		$rows = $this->db->select('SELECT status, COUNT(*) AS n FROM ' . $this->db->table('recommendations') . ' WHERE project_id = ? AND is_obsolete = 0 GROUP BY status', [$projectId]);

		return array_map('intval', array_column($rows, 'n', 'status'));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function top(int $projectId, int $limit = 5): array
	{
		return array_map([self::class, 'decode'], $this->db->select(
			'SELECT * FROM ' . $this->db->table('recommendations') . " WHERE project_id = ? AND is_obsolete = 0 AND status IN ('new', 'to_review', 'accepted') ORDER BY priority_score DESC, id DESC LIMIT " . (int) $limit,
			[$projectId]
		));
	}

	public function addEvent(int $recommendationId, int $projectId, string $event, ?int $userId = null, ?string $from = null, ?string $to = null, ?string $note = null): void
	{
		$this->db->insert('recommendation_events', [
			'recommendation_id' => $recommendationId,
			'project_id' => $projectId,
			'user_id' => $userId,
			'event' => $event,
			'from_status' => $from,
			'to_status' => $to,
			'note' => $note,
			'created_at' => $this->db->now(),
		]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function events(int $projectId, int $recommendationId): array
	{
		return $this->db->select(
			sprintf('SELECT e.*, u.display_name AS user_name FROM %s e LEFT JOIN %s u ON u.ID = e.user_id WHERE e.project_id = ? AND e.recommendation_id = ? ORDER BY e.id DESC LIMIT 50', $this->db->table('recommendation_events'), $this->db->wpdb()->users),
			[$projectId, $recommendationId]
		);
	}

	private static function decode(array $row): array
	{
		$evidence = $row['evidence'] ? (json_decode((string) $row['evidence'], true) ?: []) : [];
		$row['evidence'] = $evidence['pairs'] ?? [];
		$row['sources'] = $evidence['sources'] ?? [$row['source']];
		$row['impact_micros'] = $row['impact_micros'] === null ? null : (int) $row['impact_micros'];
		$row['is_obsolete'] = (bool) $row['is_obsolete'];
		$row['manually_obsolete'] = $row['is_obsolete'] && $row['obsolete_reason'] === self::MANUAL_OBSOLETE;

		return $row;
	}
}
