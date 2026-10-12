<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Domain\Import\ReportRegistry;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Import\FileInspector;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\ImportService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Paginator;
use Illuminate\Http\Request;

class ImportsController extends Controller
{
	public function __construct(
		AccessService $access,
		private ImportService $service,
		private ImportRepository $imports,
		private ReportRegistry $registry,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request, ?string $uploadError = null, int $status = 200)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);

		$paginator = new Paginator($this->imports->count($project->id), max(1, (int) $request->query('page', 1)), 20);
		$paginator->withItems($this->imports->latest($project->id, $paginator->perPage, $paginator->offset()));

		return $this->render('app.pages.imports.index', [
			'project' => $project,
			'imports' => $paginator,
			'definitions' => $this->registry->all(),
			'maxBytes' => FileInspector::maxBytes(),
			'uploadError' => $uploadError,
			'old' => ['report_type' => (string) $request->input('report_type', '')],
			'pageTitle' => 'Importy',
			'section' => 'imports',
		], $status);
	}

	public function store(int $project, Request $request)
	{
		$projectModel = $this->access->project($project, Ability::ImportData);
		$type = (string) $request->input('report_type', '');

		try {
			$file = FileInspector::inspect($_FILES['report'] ?? null);
			$importId = $this->service->upload($projectModel, $this->access->userId(), $file, $this->registry->get($type)?->type());
		} catch (InvalidValue $e) {
			return $this->index($project, $request, $e->getMessage(), 422);
		}

		return redirect()->to(AppUrl::project($projectModel->id, "imports/{$importId}"));
	}

	public function show(int $project, int $import, Request $request, array $errors = [], int $status = 200)
	{
		$projectModel = $this->access->project($project);
		$importRow = $this->find($projectModel, $import);

		if ($importRow['status'] !== ImportRepository::STATUS_UPLOADED) {
			return $this->render('app.pages.imports.summary', [
				'project' => $projectModel,
				'import' => $importRow,
				'definition' => $this->registry->get($importRow['report_type']),
				'pageTitle' => 'Import #' . $importRow['id'],
				'section' => 'imports',
			]);
		}

		return $this->render('app.pages.imports.preview', [
			'project' => $projectModel,
			'import' => $importRow,
			'errors' => $errors,
			'old' => $request->isMethod('POST') ? $request->input() : [],
			'pageTitle' => 'Podgląd importu',
			'section' => 'imports',
		] + $this->service->preview($projectModel, $importRow), $status);
	}

	public function confirm(int $project, int $import, Request $request)
	{
		$projectModel = $this->access->project($project, Ability::ImportData);
		$importRow = $this->find($projectModel, $import);

		if ($importRow['status'] !== ImportRepository::STATUS_UPLOADED) {
			return redirect()->to(AppUrl::project($projectModel->id, "imports/{$import}"));
		}

		// Zmiana typu raportu: zapisujemy typ i proponujemy nowe mapowanie kolumn.
		if ($request->input('action') === 'retype') {
			$definition = $this->registry->get((string) $request->input('report_type'));

			if ($definition !== null) {
				$this->imports->update($import, [
					'report_type' => $definition->type(),
					'column_mapping' => $definition->guessMapping($importRow['meta']['headers'] ?? []),
					'errors' => null,
				]);
			}

			return redirect()->to(AppUrl::project($projectModel->id, "imports/{$import}"));
		}

		try {
			$result = $this->service->confirm($projectModel, $importRow, $request->input());
		} catch (ValidationFailed $e) {
			return $this->show($project, $import, $request, $e->errors, 422);
		}

		if (! $result->success) {
			return $this->show($project, $import, $request, ['processing' => 'Import nie został zapisany. Popraw błędy i spróbuj ponownie.'], 422);
		}

		return $this->redirect(AppUrl::project($projectModel->id, "imports/{$import}"), sprintf('Zaimportowano %d wierszy.', $result->imported));
	}

	public function cancel(int $project, int $import)
	{
		$projectModel = $this->access->project($project, Ability::ImportData);
		$importRow = $this->find($projectModel, $import);

		if ($importRow['status'] === ImportRepository::STATUS_UPLOADED) {
			$this->service->cancel($importRow);
		}

		return $this->redirect(AppUrl::project($projectModel->id, 'imports'), 'Import został anulowany. Żadne dane nie zostały zapisane.', 'info');
	}

	private function find(Project $project, int $importId): array
	{
		$import = $this->imports->findInProject($project->id, $importId);

		if ($import === null) {
			throw new NotFound('Import nie istnieje w tym projekcie.');
		}

		return $import;
	}
}
