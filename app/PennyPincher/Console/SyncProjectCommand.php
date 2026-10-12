<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use App\PennyPincher\Services\GoogleAds\SyncService;
use Illuminate\Console\Command;

class SyncProjectCommand extends Command
{
	protected $signature = 'pp:sync {project : ID projektu} {--backfill : Pobierz historię 12 miesięcy} {--time=600 : Maksymalny czas pracy w sekundach}';

	protected $description = 'Penny Pincher: synchronizuje jeden projekt z Google Ads API';

	public function handle(SyncService $sync, GoogleAdsConnectionRepository $connections): int
	{
		$connection = $connections->currentForProject((int) $this->argument('project'));

		if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
			$this->error('Projekt nie ma aktywnego połączenia z Google Ads.');

			return self::FAILURE;
		}

		$run = $sync->enqueue($connection, $this->option('backfill') ? 'backfill' : 'incremental');
		$run = $sync->process($run, time() + max(5, (int) $this->option('time')));

		$this->line(sprintf('Przebieg #%d (%s): %s, krok %d/%d, rekordów: %d.', $run['id'], $run['type'], $run['status'], $run['step_index'], $run['steps_total'], $run['records_imported']));

		if ($run['status'] === SyncRunRepository::FAILED) {
			$this->error((string) $run['last_error']);

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
