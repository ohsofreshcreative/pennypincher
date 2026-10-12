<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoClient;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoConfig;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoException;
use App\PennyPincher\Services\DataForSeo\DataForSeoService;
use Illuminate\Console\Command;

/**
 * Sprawdzenie konfiguracji DataForSEO wyłącznie BEZPŁATNYMI endpointami (dane konta, lista lokalizacji).
 * Nie wykonuje żadnego płatnego zapytania i nie wyświetla danych logowania.
 */
class DataForSeoCheckCommand extends Command
{
	protected $signature = 'pp:dataforseo:check {--locations= : Kod kraju ISO (np. pl) – pokaż kody lokalizacji Google Ads (bezpłatne)}';

	protected $description = 'Penny Pincher: test połączenia z DataForSEO (bezpłatne endpointy, bez płatnych zapytań)';

	public function handle(DataForSeoConfig $config, DataForSeoService $service, DataForSeoClient $client): int
	{
		if (! $config->isConfigured()) {
			$this->error('Brak konfiguracji: ' . implode(', ', $config->missing()) . ' (wp-config.php lub zmienne środowiskowe).');

			return self::FAILURE;
		}

		$this->line('Tryb: ' . ($config->sandbox() ? 'sandbox (dane przykładowe, bezpłatne)' : 'produkcyjny'));
		$result = $service->testConnection();

		if (! $result['ok']) {
			$this->error($result['message']);

			return self::FAILURE;
		}

		$this->info($result['message'] . ' Saldo: ' . ($result['balance'] === null ? 'brak danych' : DataForSeoService::usd($result['balance']) . ' USD'));

		if ($country = $this->option('locations')) {
			try {
				$countries = array_filter($client->locations((string) $country), fn ($l) => ($l['location_type'] ?? '') === 'Country');
				foreach ($countries ?: array_slice($client->locations((string) $country), 0, 5) as $location) {
					$this->line(sprintf('%d  %s (%s)', $location['location_code'], $location['location_name'], $location['location_type'] ?? '—'));
				}
			} catch (DataForSeoException $e) {
				$this->error($e->getMessage());

				return self::FAILURE;
			}
		}

		return self::SUCCESS;
	}
}
