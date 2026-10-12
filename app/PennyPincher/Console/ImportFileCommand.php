<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Infrastructure\Import\FileInspector;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\ImportService;
use Illuminate\Console\Command;

/**
 * Import z linii poleceń – ten sam przebieg co w interfejsie (automatyczne mapowanie kolumn).
 */
class ImportFileCommand extends Command
{
	protected $signature = 'pp:import {project : ID projektu} {file : Ścieżka do pliku CSV/XLSX}
		{--type= : campaigns|search_terms|keywords|conversion_actions (domyślnie wykrywany)}
		{--from= : Początek zakresu (Y-m-d) dla raportów bez kolumny Dzień}
		{--to= : Koniec zakresu (Y-m-d)}';

	protected $description = 'Penny Pincher: importuje raport Google Ads do projektu';

	public function handle(ProjectRepository $projects, ImportService $service, ImportRepository $imports): int
	{
		$project = $projects->findById((int) $this->argument('project'));

		if ($project === null) {
			$this->error('Projekt nie istnieje.');

			return self::FAILURE;
		}

		$path = (string) $this->argument('file');

		try {
			$file = FileInspector::inspectPath($path, basename($path));
			$importId = $service->upload($project, 0, $file, $this->option('type') ?: null);
		} catch (InvalidValue $e) {
			$this->error($e->getMessage());

			return self::FAILURE;
		}

		$import = $imports->findInProject($project->id, $importId);
		$result = $service->confirm($project, $import, [
			'report_type' => $import['report_type'],
			'mapping' => $import['column_mapping'],
			'date_from' => $this->option('from') ?: ($import['meta']['detected_period'][0] ?? ''),
			'date_to' => $this->option('to') ?: ($import['meta']['detected_period'][1] ?? ''),
		]);

		if (! $result->success) {
			foreach ($result->errors as $error) {
				$this->error($error);
			}

			return self::FAILURE;
		}

		$this->info("Import #{$importId}: zapisano {$result->imported} wierszy, pominięto {$result->skipped}.");

		return self::SUCCESS;
	}
}
