<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Services\Changes\ExecutionService;
use App\PennyPincher\Services\DataForSeo\DataForSeoService;
use App\PennyPincher\Services\Fx\ExchangeRateService;
use App\PennyPincher\Services\GoogleAds\SyncService;
use Illuminate\Console\Command;

/**
 * Zadanie dla systemowego crona (np. co 10 minut): planuje codzienne synchronizacje i przetwarza kolejkę.
 * Równoległe uruchomienia są bezpieczne – każde połączenie jest blokowane osobno (GET_LOCK).
 */
class SyncWorkCommand extends Command
{
	protected $signature = 'pp:sync:work {--time=240 : Maksymalny czas pracy w sekundach} {--no-schedule : Tylko przetwarzaj kolejkę}';

	protected $description = 'Penny Pincher: planuje i wykonuje synchronizacje z Google Ads API, zatwierdzone wdrożenia Change Center i zadania DataForSEO';

	public function handle(SyncService $sync, DataForSeoService $dataforseo, ExchangeRateService $fx, ExecutionService $changes): int
	{
		$started = time();
		$budget = max(5, (int) $this->option('time'));

		// Kolejka wdrożeń Change Center (tylko zatwierdzone i świadomie uruchomione zestawy) – przed synchronizacją,
		// żeby wdrożenie nie czekało na długi przebieg. Bez trybu write_enabled nic nie jest wysyłane.
		$deployed = $changes->work(min(60, $budget));

		if ($deployed['processed'] > 0 || $deployed['stale'] > 0) {
			$this->info(sprintf('Change Center: przetworzono zestawów: %d, przerwanych (do uzgodnienia): %d.', $deployed['processed'], $deployed['stale']));
		}

		$queued = $this->option('no-schedule') ? 0 : $sync->schedule();
		$result = $sync->work($budget);

		$this->info(sprintf('Zaplanowano: %d, przetworzono przebiegów: %d, wznowiono przerwanych: %d.', $queued, $result['processed'], $result['requeued']));

		// Zadania DataForSEO (wyłącznie zlecone i zatwierdzone przez użytkownika) – w pozostałym czasie.
		$dfs = $dataforseo->work(max(5, $budget - (time() - $started)));

		// Kursy NBP do przeliczania CPC z DataForSEO (bezpłatne, najwyżej raz na 12 h, tylko gdy są dane do przeliczenia).
		$rates = $fx->refreshIfDue();

		if ($rates !== null) {
			$this->info("Kursy walut NBP: zapisano {$rates}.");
		}

		if ($dfs['processed'] > 0 || $dfs['requeued'] > 0) {
			$this->info(sprintf('DataForSEO: przetworzono zadań: %d, wznowiono przerwanych: %d.', $dfs['processed'], $dfs['requeued']));
		}

		return self::SUCCESS;
	}
}
