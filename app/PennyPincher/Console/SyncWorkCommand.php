<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Services\DataForSeo\DataForSeoService;
use App\PennyPincher\Services\GoogleAds\SyncService;
use Illuminate\Console\Command;

/**
 * Zadanie dla systemowego crona (np. co 10 minut): planuje codzienne synchronizacje i przetwarza kolejkę.
 * Równoległe uruchomienia są bezpieczne – każde połączenie jest blokowane osobno (GET_LOCK).
 */
class SyncWorkCommand extends Command
{
	protected $signature = 'pp:sync:work {--time=240 : Maksymalny czas pracy w sekundach} {--no-schedule : Tylko przetwarzaj kolejkę}';

	protected $description = 'Penny Pincher: planuje i wykonuje synchronizacje z Google Ads API oraz zatwierdzone zadania DataForSEO';

	public function handle(SyncService $sync, DataForSeoService $dataforseo): int
	{
		$started = time();
		$budget = max(5, (int) $this->option('time'));
		$queued = $this->option('no-schedule') ? 0 : $sync->schedule();
		$result = $sync->work($budget);

		$this->info(sprintf('Zaplanowano: %d, przetworzono przebiegów: %d, wznowiono przerwanych: %d.', $queued, $result['processed'], $result['requeued']));

		// Zadania DataForSEO (wyłącznie zlecone i zatwierdzone przez użytkownika) – w pozostałym czasie.
		$dfs = $dataforseo->work(max(5, $budget - (time() - $started)));

		if ($dfs['processed'] > 0 || $dfs['requeued'] > 0) {
			$this->info(sprintf('DataForSEO: przetworzono zadań: %d, wznowiono przerwanych: %d.', $dfs['processed'], $dfs['requeued']));
		}

		return self::SUCCESS;
	}
}
