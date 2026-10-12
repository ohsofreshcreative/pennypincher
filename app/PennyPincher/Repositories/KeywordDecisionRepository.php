<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Decyzje o frazach (jedna na znormalizowaną frazę w projekcie) i ich historia.
 */
class KeywordDecisionRepository
{
	public function __construct(private Db $db)
	{
	}

	public function find(int $projectId, string $normalized): ?array
	{
		return $this->db->first('SELECT * FROM ' . $this->db->table('keyword_decisions') . ' WHERE project_id = ? AND normalized = ?', [$projectId, $normalized]);
	}

	public function findById(int $projectId, int $id): ?array
	{
		return $this->db->first('SELECT * FROM ' . $this->db->table('keyword_decisions') . ' WHERE project_id = ? AND id = ?', [$projectId, $id]);
	}

	/**
	 * @param array<int, string> $normalized
	 * @return array<string, array<string, mixed>> klucz = fraza znormalizowana
	 */
	public function forPhrases(int $projectId, array $normalized): array
	{
		$normalized = array_values(array_unique(array_filter($normalized, fn ($n) => $n !== '')));

		if ($normalized === []) {
			return [];
		}

		$map = [];

		foreach (array_chunk($normalized, 500) as $chunk) {
			$rows = $this->db->select(
				'SELECT * FROM ' . $this->db->table('keyword_decisions') . ' WHERE project_id = ? AND normalized IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
				[$projectId, ...$chunk]
			);

			foreach ($rows as $row) {
				$map[$row['normalized']] = $row;
			}
		}

		return $map;
	}

	/**
	 * @return array<string, int>
	 */
	public function counts(int $projectId): array
	{
		$rows = $this->db->select('SELECT status, COUNT(*) AS n FROM ' . $this->db->table('keyword_decisions') . ' WHERE project_id = ? GROUP BY status', [$projectId]);

		return array_map('intval', array_column($rows, 'n', 'status'));
	}

	/**
	 * Ustawia status (tworzy decyzję, jeśli jej nie ma). Zwraca [id, poprzedni status albo null].
	 *
	 * @return array{0: int, 1: ?string}
	 */
	public function setStatus(int $projectId, string $normalized, string $phrase, string $status, ?int $userId, array $extra = []): array
	{
		$now = $this->db->now();
		$existing = $this->find($projectId, $normalized);

		if ($existing === null) {
			$id = $this->db->insert('keyword_decisions', $extra + [
				'project_id' => $projectId,
				'normalized' => $normalized,
				'phrase' => mb_substr($phrase, 0, 255),
				'status' => $status,
				'decided_by' => $userId,
				'decided_at' => $now,
				'created_at' => $now,
				'updated_at' => $now,
			]);

			return [$id, null];
		}

		$this->db->update('keyword_decisions', $extra + ['status' => $status, 'decided_by' => $userId, 'decided_at' => $now, 'updated_at' => $now], ['id' => (int) $existing['id']]);

		return [(int) $existing['id'], (string) $existing['status']];
	}

	public function update(int $id, array $values): void
	{
		$this->db->update('keyword_decisions', $values + ['updated_at' => $this->db->now()], ['id' => $id]);
	}

	public function addEvent(int $decisionId, int $projectId, string $event, ?int $userId, ?string $from, ?string $to, ?string $note = null): void
	{
		$this->db->insert('keyword_decision_events', [
			'decision_id' => $decisionId,
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
	public function events(int $projectId, int $decisionId): array
	{
		return $this->db->select(
			sprintf('SELECT e.*, u.display_name AS user_name FROM %s e LEFT JOIN %s u ON u.ID = e.user_id WHERE e.project_id = ? AND e.decision_id = ? ORDER BY e.id DESC LIMIT 50', $this->db->table('keyword_decision_events'), $this->db->wpdb()->users),
			[$projectId, $decisionId]
		);
	}

	/**
	 * Frazy w statusie „Zaakceptowana” lub „W planie”, które pojawiły się jako aktywne słowa kluczowe na koncie.
	 *
	 * @param array<int, string> $activeKeywords znormalizowane teksty aktywnych słów kluczowych
	 * @return array<int, array<string, mixed>>
	 */
	public function pendingUsed(int $projectId, array $activeKeywords): array
	{
		$set = array_flip($activeKeywords);

		return array_values(array_filter(
			$this->db->select('SELECT * FROM ' . $this->db->table('keyword_decisions') . " WHERE project_id = ? AND status IN ('accepted', 'in_plan')", [$projectId]),
			fn ($row) => isset($set[$row['normalized']])
		));
	}
}
