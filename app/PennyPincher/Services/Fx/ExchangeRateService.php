<?php

namespace App\PennyPincher\Services\Fx;

use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\Fx\NbpClient;
use App\PennyPincher\Repositories\ExchangeRateRepository;
use Throwable;

/**
 * Przeliczanie kwot między walutami na podstawie zapisanych kursów (NBP, tabela A).
 *
 * - Przeliczenie zawsze zwraca kurs, jego datę i źródło – oryginalna kwota i waluta zostają zapisane obok.
 * - Brak kursu nie starszego niż MAX_AGE_DAYS → null („porównanie niedostępne”), nigdy wartość wymyślona.
 * - Kursy są pobierane w tle (worker `pp:sync:work`, komenda `pp:fx:update`), nie przy wyświetlaniu strony.
 */
class ExchangeRateService
{
	public const MAX_AGE_DAYS = 10;

	public const BASE = 'PLN';

	private const OPTION = 'pp_fx_last_attempt';

	public function __construct(private ExchangeRateRepository $rates, private NbpClient $nbp, private Db $db)
	{
	}

	/**
	 * @return array{micros: int, rate: float, rate_date: string, source: string}|null
	 */
	public function convert(int $micros, string $from, string $to, ?string $asOf = null): ?array
	{
		$from = strtoupper($from);
		$to = strtoupper($to);

		if ($from === $to) {
			return ['micros' => $micros, 'rate' => 1.0, 'rate_date' => $asOf ?? gmdate('Y-m-d'), 'source' => 'ta sama waluta'];
		}

		$rate = $this->rate($from, $to, $asOf);

		return $rate === null ? null : ['micros' => (int) round($micros * $rate['rate']), 'rate' => $rate['rate'], 'rate_date' => $rate['rate_date'], 'source' => $rate['source']];
	}

	/**
	 * Kurs from→to (bezpośredni względem PLN albo krzyżowy przez PLN z kursów tego samego źródła).
	 *
	 * @return array{rate: float, rate_date: string, source: string}|null
	 */
	public function rate(string $from, string $to, ?string $asOf = null): ?array
	{
		$minDate = gmdate('Y-m-d', strtotime(($asOf ?? gmdate('Y-m-d')) . ' -' . self::MAX_AGE_DAYS . ' days'));

		if ($to === self::BASE) {
			return $this->rates->latest($from, self::BASE, $minDate);
		}

		if ($from === self::BASE) {
			$inverse = $this->rates->latest($to, self::BASE, $minDate);

			return $inverse === null ? null : ['rate' => 1 / $inverse['rate'], 'rate_date' => $inverse['rate_date'], 'source' => $inverse['source'] . ' (odwrotność)'];
		}

		$a = $this->rates->latest($from, self::BASE, $minDate);
		$b = $this->rates->latest($to, self::BASE, $minDate);

		if ($a === null || $b === null) {
			return null;
		}

		return ['rate' => $a['rate'] / $b['rate'], 'rate_date' => min($a['rate_date'], $b['rate_date']), 'source' => $a['source'] . ' (kurs krzyżowy przez PLN)'];
	}

	/**
	 * Pobranie kursów z NBP dla walut (z pominięciem PLN). Zwraca liczbę zapisanych kursów.
	 *
	 * @param array<int, string> $currencies
	 */
	public function refresh(array $currencies): int
	{
		$saved = 0;

		foreach (array_unique(array_map('strtoupper', $currencies)) as $currency) {
			if ($currency === self::BASE || ! preg_match('/^[A-Z]{3}$/', $currency)) {
				continue;
			}

			foreach ($this->nbp->lastRates($currency, 5) as $rate) {
				$this->rates->upsert($currency, self::BASE, $rate['rate'], $rate['date'], NbpClient::SOURCE);
				$saved++;
			}
		}

		return $saved;
	}

	/**
	 * Wywoływane przez worker: odświeża kursy potrzebne do porównań (waluty CPC z DataForSEO i waluty projektów),
	 * najwyżej raz na 12 godzin. Błąd jest logowany i nie przerywa pracy workera.
	 */
	public function refreshIfDue(): ?int
	{
		$last = (int) get_option(self::OPTION, 0);

		if (time() - $last < 12 * HOUR_IN_SECONDS) {
			return null;
		}

		$ideaCurrencies = $this->db->column('SELECT DISTINCT cpc_currency FROM ' . $this->db->table('keyword_ideas') . ' WHERE cpc_currency IS NOT NULL');
		$projectCurrencies = $this->db->column('SELECT DISTINCT currency FROM ' . $this->db->table('projects'));
		$needed = array_values(array_diff(array_unique([...$ideaCurrencies, ...$projectCurrencies]), [self::BASE]));
		$foreign = array_diff($ideaCurrencies, $projectCurrencies);

		if ($foreign === [] && count(array_unique($projectCurrencies)) <= 1 && $ideaCurrencies === []) {
			return null; // nic do przeliczania
		}

		update_option(self::OPTION, time(), false);

		try {
			return $this->refresh($needed);
		} catch (Throwable $e) {
			error_log('[Penny Pincher] Kursy NBP: ' . $e->getMessage());

			return 0;
		}
	}
}
