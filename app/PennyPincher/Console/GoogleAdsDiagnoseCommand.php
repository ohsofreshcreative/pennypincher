<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use Illuminate\Console\Command;

/**
 * Diagnostyka połączenia projektu z Google Ads API: tylko odczyt, bez wyświetlania tokenów i sekretów.
 */
class GoogleAdsDiagnoseCommand extends Command
{
	protected $signature = 'pp:google-ads:diagnose {project : ID projektu}';

	protected $description = 'Penny Pincher: sprawdza listę kont dostępnych przez połączenie OAuth projektu (bez sekretów)';

	public function handle(ConnectionService $service, GoogleAdsConnectionRepository $connections): int
	{
		$connection = $connections->currentForProject((int) $this->argument('project'));

		if ($connection === null) {
			$this->error('Projekt nie ma połączenia z Google Ads.');

			return self::FAILURE;
		}

		$this->line(sprintf('Połączenie #%d, status: %s, konto Google: %s', $connection['id'], $connection['status'], $connection['google_email'] ?: '—'));

		$rows = $service->diagnose($connection);
		$this->table(['Endpoint', 'HTTP', 'Kod Google', 'Komunikat', 'Konta'], array_map(fn ($row) => [
			$row['endpoint'], $row['status'] ?? '—', $row['code'] ?? '—', $row['message'] ?? '—', $row['count'] ?? '—',
		], $rows));

		return array_filter($rows, fn ($row) => $row['code'] !== null) === [] ? self::SUCCESS : self::FAILURE;
	}
}
