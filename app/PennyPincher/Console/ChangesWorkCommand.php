<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Services\Changes\ExecutionService;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use Illuminate\Console\Command;

/**
 * Worker Change Center: wysyła do Google Ads wyłącznie zestawy zatwierdzone i świadomie skierowane do wdrożenia
 * przez uprawnionego wykonawcę. W trybie innym niż write_enabled nie wysyła niczego (zestawy w kolejce zostają
 * zatrzymane z czytelnym powodem). Uruchamiany też przez `pp:sync:work`.
 */
class ChangesWorkCommand extends Command
{
	protected $signature = 'pp:changes:work {--time=60 : Maksymalny czas pracy w sekundach}';

	protected $description = 'Penny Pincher: przetwarza kolejkę wdrożeń Change Center (tylko zatwierdzone zestawy)';

	public function handle(ExecutionService $execution, WriteGuard $guard): int
	{
		$this->line('Tryb zapisu serwera: ' . $guard->serverMode()->label() . '.');
		$result = $execution->work(max(5, (int) $this->option('time')));
		$this->info(sprintf('Zestawy zmian: przetworzono %d, przerwanych (do uzgodnienia): %d.', $result['processed'], $result['stale']));

		return self::SUCCESS;
	}
}
