<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Services\Fx\ExchangeRateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pobranie kursów walut z publicznego API NBP (bezpłatne, bez klucza). Worker robi to automatycznie raz na 12 h,
 * gdy są dane do przeliczenia.
 */
class FxUpdateCommand extends Command
{
	protected $signature = 'pp:fx:update {currencies?* : Kody walut (domyślnie USD i EUR)}';

	protected $description = 'Penny Pincher: kursy walut z NBP (tabela A) do przeliczania CPC';

	public function handle(ExchangeRateService $service): int
	{
		$currencies = $this->argument('currencies') ?: ['USD', 'EUR'];

		try {
			$saved = $service->refresh($currencies);
		} catch (Throwable $e) {
			$this->error($e->getMessage());

			return self::FAILURE;
		}

		$this->info("Zapisano kursów: {$saved}.");

		foreach ($currencies as $currency) {
			$rate = $service->rate(strtoupper($currency), 'PLN');
			$this->line(strtoupper($currency) . '/PLN: ' . ($rate ? $rate['rate'] . ' (' . $rate['rate_date'] . ', ' . $rate['source'] . ')' : 'brak aktualnego kursu'));
		}

		return self::SUCCESS;
	}
}
