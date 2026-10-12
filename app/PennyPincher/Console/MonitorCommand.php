<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\Monitoring\MonitoringService;
use Illuminate\Console\Command;

class MonitorCommand extends Command
{
	protected $signature = 'pp:monitor {project : ID projektu}';

	protected $description = 'Penny Pincher: monitoring projektu (alerty z danych w bazie, bez zapytań do API)';

	public function handle(MonitoringService $service, ProjectRepository $projects): int
	{
		$project = $projects->findById((int) $this->argument('project'));

		if ($project === null) {
			$this->error('Projekt nie istnieje.');

			return self::FAILURE;
		}

		$result = $service->run($project, 'cli');
		$this->info(sprintf('Wykryto: %d · nowe: %d · ponownie otwarte: %d · zaktualizowane: %d · warunek ustał: %d', $result['detected'], $result['created'], $result['reopened'], $result['updated'], $result['inactive']));

		return self::SUCCESS;
	}
}
