<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Services\GoogleAds\SyncService;
use Illuminate\Console\Command;

/**
 * Zadanie dla systemowego crona (np. co 10 minut): planuje codzienne synchronizacje i przetwarza kolejkę.
 * Równoległe uruchomienia są bezpieczne – każde połączenie jest blokowane osobno (GET_LOCK).
 */
class SyncWorkCommand extends Command
{
	protected $signature = 'pp:sync:work {--time=240 : Maksymalny czas pracy w sekundach} {--no-schedule : Tylko przetwarzaj kolejkę}';

	protected $description = 'Penny Pincher: planuje i wykonuje synchronizacje z Google Ads API';

	public function handle(SyncService $sync): int
	{
		$queued = $this->option('no-schedule') ? 0 : $sync->schedule();
		$result = $sync->work(max(5, (int) $this->option('time')));

		$this->info(sprintf('Zaplanowano: %d, przetworzono przebiegów: %d, wznowiono przerwanych: %d.', $queued, $result['processed'], $result['requeued']));

		return self::SUCCESS;
	}
}
