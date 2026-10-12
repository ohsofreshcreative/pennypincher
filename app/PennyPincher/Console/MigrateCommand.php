<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Infrastructure\Database\Migrator;
use Illuminate\Console\Command;

class MigrateCommand extends Command
{
	protected $signature = 'pp:migrate';

	protected $description = 'Penny Pincher: uruchamia brakujące migracje schematu bazy danych';

	public function handle(Migrator $migrator): int
	{
		$applied = $migrator->migrate();

		if ($applied === []) {
			$this->info('Schemat jest aktualny (' . $migrator->latestVersion() . ').');
		} else {
			foreach ($applied as $version) {
				$this->info("Zastosowano: {$version}");
			}
		}

		return self::SUCCESS;
	}
}
