<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Audit\RuleRegistry;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\AuditService;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Audyty projektu: wynik, problemy wg ważności, historia i statusy problemów.
 * Audyt tylko analizuje dane – nie wprowadza zmian na koncie Google Ads.
 */
class AuditsController extends Controller
{
	public function __construct(
		AccessService $access,
		private AuditService $service,
		private AuditRepository $audits,
	) {
		parent::__construct($access);
	}

	public function index(int $project)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);

		return $this->page($project, $this->audits->latestRun($project->id));
	}

	public function show(int $project, int $run)
	{
		$project = $this->access->project($project);
		$runRow = $this->audits->findRun($project->id, $run);

		if ($runRow === null) {
			throw new NotFound('Audyt nie istnieje.');
		}

		return $this->page($project, $runRow);
	}

	public function store(int $project)
	{
		$project = $this->access->project($project, Ability::ImportData);
		$runId = $this->service->run($project, 'manual', $this->access->userId());

		return $this->redirect(AppUrl::project($project->id, "audits/{$runId}"), 'Audyt został wykonany.');
	}

	public function updateStatus(int $project, int $finding, Request $request)
	{
		$project = $this->access->project($project, Ability::ImportData);

		try {
			$row = $this->service->updateStatus($project, $finding, (string) $request->input('status', ''), $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($project->id, 'audits'), (array_values($e->errors)[0] ?? $e->getMessage()), 'error');
		}

		return redirect()->to(AppUrl::project($project->id, 'audits/' . $row['audit_run_id']) . '#finding-' . $row['id']);
	}

	private function page($project, ?array $run)
	{
		$findings = $run ? $this->audits->findings((int) $run['id']) : [];
		$groups = ['critical' => [], 'warning' => [], 'info' => [], 'dismissed' => []];

		foreach ($findings as $finding) {
			$groups[$finding['status'] === 'dismissed' ? 'dismissed' : $finding['severity']][] = $finding;
		}

		return $this->render('app.pages.audits.index', [
			'project' => $project,
			'run' => $run,
			'groups' => $groups,
			'history' => $this->audits->runs($project->id, 20),
			'rules' => RuleRegistry::byId(),
			'canRun' => Policy::allows($project->role, Ability::ImportData),
			'pageTitle' => 'Audyt',
			'section' => 'audits',
		]);
	}
}
