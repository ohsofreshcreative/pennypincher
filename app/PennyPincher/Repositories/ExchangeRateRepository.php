<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Kursy walut z datą i źródłem (np. NBP). Kurs = ile jednostek `currency_to` za 1 jednostkę `currency_from`.
 */
class ExchangeRateRepository
{
	public function __construct(private Db $db)
	{
	}

	public function upsert(string $from, string $to, float $rate, string $date, string $source): void
	{
		$this->db->statement(
			'INSERT INTO ' . $this->db->table('exchange_rates') . ' (currency_from, currency_to, rate, rate_date, source, fetched_at) VALUES (?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE rate = VALUES(rate), fetched_at = VALUES(fetched_at)',
			[strtoupper($from), strtoupper($to), sprintf('%.8F', $rate), $date, $source, $this->db->now()]
		);
	}

	/**
	 * Najnowszy kurs nie starszy niż $minDate.
	 *
	 * @return array{rate: float, rate_date: string, source: string}|null
	 */
	public function latest(string $from, string $to, string $minDate): ?array
	{
		$row = $this->db->first(
			'SELECT rate, rate_date, source FROM ' . $this->db->table('exchange_rates') . ' WHERE currency_from = ? AND currency_to = ? AND rate_date >= ? ORDER BY rate_date DESC LIMIT 1',
			[strtoupper($from), strtoupper($to), $minDate]
		);

		return $row ? ['rate' => (float) $row['rate'], 'rate_date' => (string) $row['rate_date'], 'source' => (string) $row['source']] : null;
	}
}
