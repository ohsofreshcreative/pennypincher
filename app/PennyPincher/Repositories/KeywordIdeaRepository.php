<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Pomysły słów kluczowych z DataForSEO zapisane dla projektu (bez ponownego pobierania przy każdym wejściu).
 */
class KeywordIdeaRepository
{
	public function __construct(private Db $db)
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	public function upsert(array $rows): void
	{
		$this->db->insertMany('keyword_ideas', $rows, 300,
			'ON DUPLICATE KEY UPDATE keyword = VALUES(keyword), task_id = VALUES(task_id), seed = VALUES(seed), search_volume = VALUES(search_volume), cpc_micros = VALUES(cpc_micros), cpc_currency = VALUES(cpc_currency),
			competition = VALUES(competition), competition_index = VALUES(competition_index), low_bid_micros = VALUES(low_bid_micros), high_bid_micros = VALUES(high_bid_micros), fetched_at = VALUES(fetched_at)');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function forProject(int $projectId, int $locationCode, string $languageCode, int $limit = 2000): array
	{
		return array_map(fn ($row) => [
			'keyword' => (string) $row['keyword'],
			'seed' => $row['seed'],
			'search_volume' => $row['search_volume'] === null ? null : (int) $row['search_volume'],
			'cpc_micros' => $row['cpc_micros'] === null ? null : (int) $row['cpc_micros'],
			'cpc_currency' => (string) ($row['cpc_currency'] ?? 'USD'),
			'competition' => $row['competition'],
			'source' => $row['source'],
			'fetched_at' => $row['fetched_at'],
		], $this->db->select(
			'SELECT * FROM ' . $this->db->table('keyword_ideas') . ' WHERE project_id = ? AND location_code = ? AND language_code = ? ORDER BY search_volume DESC LIMIT ' . (int) $limit,
			[$projectId, $locationCode, $languageCode]
		));
	}

	public function count(int $projectId): int
	{
		return (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('keyword_ideas') . ' WHERE project_id = ?', [$projectId]);
	}

	public function lastFetchedAt(int $projectId): ?string
	{
		$value = $this->db->scalar('SELECT MAX(fetched_at) FROM ' . $this->db->table('keyword_ideas') . ' WHERE project_id = ?', [$projectId]);

		return $value ? (string) $value : null;
	}
}
