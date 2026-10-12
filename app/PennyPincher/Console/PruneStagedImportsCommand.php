<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Services\ImportMaintenance;
use Illuminate\Console\Command;

class PruneStagedImportsCommand extends Command
{
	protected $signature = 'pp:imports:prune {--days=3 : Wiek porzuconych podglądów w dniach}';

	protected $description = 'Penny Pincher: usuwa surowe dane niezatwierdzonych importów';

	public function handle(ImportMaintenance $maintenance): int
	{
		$count = $maintenance->pruneStaged(max(0, (int) $this->option('days')));
		$this->info("Anulowano porzucone importy: {$count}.");

		return self::SUCCESS;
	}
}
