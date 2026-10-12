<?php

namespace App\PennyPincher\Infrastructure\Import\Writers;

use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Wspólna logika zapisu znormalizowanych wierszy: encje (kampanie, grupy reklam) i metryki.
 *
 * Idempotencja: metryki mają unikalny klucz (encja, ziarnistość, od, do), więc ponowny import
 * tego samego raportu nadpisuje wartości zamiast je dublować. Wiersze z tym samym kluczem w jednym
 * pliku (np. segmentacja po urządzeniu) są najpierw sumowane.
 */
abstract class ReportWriter
{
	/** @var array<string, int> */
	private array $campaignCache = [];

	/** @var array<string, int> */
	private array $adGroupCache = [];

	public function __construct(protected Db $db)
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	abstract public function write(ImportContext $context, array $rows): WriteResult;

	protected function campaignId(ImportContext $context, string $name, ?string $externalId): int
	{
		$cacheKey = ($externalId ?? '') . '|' . mb_strtolower($name);

		if (isset($this->campaignCache[$cacheKey])) {
			return $this->campaignCache[$cacheKey];
		}

		$table = $this->db->table('campaigns');
		$now = $this->db->now();
		$id = null;

		if ($externalId !== null) {
			$id = $this->db->scalar("SELECT id FROM {$table} WHERE project_id = ? AND external_id = ? LIMIT 1", [$context->projectId, $externalId]);

			if ($id === null) {
				// Kampania znana wcześniej tylko z nazwy – dopisujemy jej identyfikator.
				$id = $this->db->scalar(
					"SELECT id FROM {$table} WHERE project_id = ? AND external_id IS NULL AND name = ? ORDER BY id LIMIT 1",
					[$context->projectId, $name]
				);

				if ($id !== null) {
					$this->db->update('campaigns', ['external_id' => $externalId, 'natural_key' => self::key('id', $externalId), 'updated_at' => $now], ['id' => (int) $id]);
				}
			}
		} else {
			$id = $this->db->scalar("SELECT id FROM {$table} WHERE project_id = ? AND name = ? ORDER BY id LIMIT 1", [$context->projectId, $name]);
		}

		if ($id === null) {
			$id = $this->db->insert('campaigns', [
				'project_id' => $context->projectId,
				'natural_key' => $externalId !== null ? self::key('id', $externalId) : self::key('name', mb_strtolower($name)),
				'external_id' => $externalId,
				'name' => $name,
				'last_import_id' => $context->importId,
				'created_at' => $now,
				'updated_at' => $now,
			]);
		} else {
			$this->db->statement("UPDATE {$table} SET name = ?, last_import_id = ?, updated_at = ? WHERE id = ?", [$name, $context->importId, $now, (int) $id]);
		}

		return $this->campaignCache[$cacheKey] = (int) $id;
	}

	protected function adGroupId(ImportContext $context, int $campaignId, ?string $name, ?string $externalId): ?int
	{
		if ($name === null && $externalId === null) {
			return null;
		}

		$name ??= 'Grupa ' . $externalId;
		$naturalKey = $externalId !== null ? self::key('id', $externalId) : self::key('name', $campaignId . '|' . mb_strtolower($name));

		if (isset($this->adGroupCache[$naturalKey])) {
			return $this->adGroupCache[$naturalKey];
		}

		$now = $this->db->now();
		$this->db->statement(
			sprintf(
				'INSERT INTO %s (project_id, campaign_id, natural_key, external_id, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE name = VALUES(name), campaign_id = VALUES(campaign_id), updated_at = VALUES(updated_at)',
				$this->db->table('ad_groups')
			),
			[$context->projectId, $campaignId, $naturalKey, $externalId, $name, $now, $now]
		);

		$id = (int) $this->db->scalar(
			sprintf('SELECT id FROM %s WHERE project_id = ? AND natural_key = ?', $this->db->table('ad_groups')),
			[$context->projectId, $naturalKey]
		);

		return $this->adGroupCache[$naturalKey] = $id;
	}

	/**
	 * Upsert encji po kluczu naturalnym; zwraca id.
	 *
	 * @param array<string, mixed> $values kolumny ustawiane przy wstawieniu i aktualizacji
	 * @param array<int, string> $keepExisting kolumny, w których NULL z importu nie nadpisuje istniejącej wartości
	 */
	protected function upsertEntity(string $table, ImportContext $context, string $naturalKey, array $values, array $keepExisting = []): int
	{
		$now = $this->db->now();
		$row = ['project_id' => $context->projectId, 'natural_key' => $naturalKey] + $values + [
			'last_import_id' => $context->importId,
			'created_at' => $now,
			'updated_at' => $now,
		];

		$updates = [];

		foreach (array_keys($values) as $column) {
			$updates[] = in_array($column, $keepExisting, true)
				? "`{$column}` = COALESCE(VALUES(`{$column}`), `{$column}`)"
				: "`{$column}` = VALUES(`{$column}`)";
		}

		$updates[] = '`last_import_id` = VALUES(`last_import_id`)';
		$updates[] = '`updated_at` = VALUES(`updated_at`)';

		$this->db->insertMany($table, [$row], 1, 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates));

		return (int) $this->db->scalar(
			sprintf('SELECT id FROM %s WHERE project_id = ? AND natural_key = ?', $this->db->table($table)),
			[$context->projectId, $naturalKey]
		);
	}

	/**
	 * Sumuje metryki wierszy o tym samym kluczu i zapisuje je z nadpisaniem wcześniejszych wartości.
	 *
	 * @param array<int, array{entity_id: int, date_from: string, date_to: string, metrics: array<string, mixed>}> $items
	 */
	protected function writeMetrics(string $table, string $entityColumn, ImportContext $context, array $items): int
	{
		$aggregated = [];

		foreach ($items as $item) {
			$key = $item['entity_id'] . '|' . $item['date_from'] . '|' . $item['date_to'];

			if (! isset($aggregated[$key])) {
				$aggregated[$key] = $item;
				continue;
			}

			foreach ($item['metrics'] as $metric => $value) {
				$aggregated[$key]['metrics'][$metric] = self::addNullable($aggregated[$key]['metrics'][$metric] ?? null, $value);
			}
		}

		$now = $this->db->now();
		$rows = [];

		foreach ($aggregated as $item) {
			$rows[] = [
				'project_id' => $context->projectId,
				$entityColumn => $item['entity_id'],
				'granularity' => $context->granularityFor($item['date_from'], $item['date_to']),
				'date_from' => $item['date_from'],
				'date_to' => $item['date_to'],
				...$item['metrics'],
				'currency' => $context->currency,
				'import_id' => $context->importId,
				'created_at' => $now,
				'updated_at' => $now,
			];
		}

		if ($rows === []) {
			return 0;
		}

		$metricColumns = array_keys(reset($aggregated)['metrics']);
		$updates = array_map(fn ($c) => "`{$c}` = VALUES(`{$c}`)", [...$metricColumns, 'currency', 'import_id', 'updated_at']);

		$this->db->insertMany($table, $rows, 400, 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates));

		return count($rows);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{0: string, 1: string}
	 */
	protected function periodFor(ImportContext $context, array $row): array
	{
		if ($context->daily) {
			return [$row['date'], $row['date']];
		}

		return [$context->dateFrom, $context->dateTo];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function performanceMetrics(array $row): array
	{
		return [
			'impressions' => $row['impressions'] ?? null,
			'clicks' => $row['clicks'] ?? null,
			'cost_micros' => $row['cost'] ?? null,
			'conversions' => $row['conversions'] ?? null,
			'view_through_conversions' => $row['view_through_conversions'] ?? null,
			'conversions_value_micros' => $row['conversions_value'] ?? null,
			'all_conversions' => $row['all_conversions'] ?? null,
		];
	}

	public static function key(string $type, string $value): string
	{
		return sha1($type . ':' . $value);
	}

	public static function addNullable(mixed $a, mixed $b): mixed
	{
		if ($a === null) {
			return $b;
		}

		if ($b === null) {
			return $a;
		}

		if (is_int($a) && is_int($b)) {
			return $a + $b;
		}

		// Konwersje (string dziesiętny) – suma z dokładnością do 4 miejsc.
		return rtrim(rtrim(number_format((float) $a + (float) $b, 4, '.', ''), '0'), '.');
	}
}
