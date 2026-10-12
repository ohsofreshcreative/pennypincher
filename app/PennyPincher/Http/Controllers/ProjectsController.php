<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\DemoProjectService;
use App\PennyPincher\Services\ProjectValidator;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

class ProjectsController extends Controller
{
	public function __construct(
		AccessService $access,
		private OrganizationRepository $organizations,
		private ProjectRepository $projectRepository,
		private ProjectValidator $validator,
	) {
		parent::__construct($access);
	}

	public function index()
	{
		$userId = $this->access->userId();
		$organizations = $this->organizations->forUser($userId);
		$projects = $this->access->projects();

		$grouped = [];

		foreach ($organizations as $organization) {
			$grouped[(int) $organization['id']] = [
				'organization' => $organization,
				'role' => Role::tryFrom((string) $organization['role']),
				'projects' => array_values(array_filter($projects, fn ($p) => $p->organizationId === (int) $organization['id'])),
			];
		}

		return $this->render('app.pages.projects.index', [
			'groups' => $grouped,
			'canCreateOrganization' => $this->access->canCreateOrganizations($userId),
			'pageTitle' => 'Projekty',
		]);
	}

	public function create(Request $request)
	{
		return $this->form($request);
	}

	public function store(Request $request)
	{
		$organizationId = (int) $request->input('organization_id');

		try {
			$this->access->organization($organizationId, Ability::CreateProject);
			$values = $this->validator->validate($request->input());
		} catch (ValidationFailed $e) {
			return $this->form($request, $e->errors, 422);
		} catch (\App\PennyPincher\Domain\Support\NotFound) {
			return $this->form($request, ['organization_id' => 'Wybierz organizację, w której możesz tworzyć projekty.'], 422);
		}

		$projectId = $this->projectRepository->create($organizationId, $values);

		return $this->redirect(AppUrl::project($projectId, 'imports'), 'Projekt został utworzony. Zaimportuj pierwszy raport z Google Ads.');
	}

	public function demo(DemoProjectService $demo)
	{
		// Projekt demo powstaje w nowej organizacji, więc wymaga tego samego uprawnienia co jej zakładanie.
		if (! $this->access->canCreateOrganizations($this->access->userId())) {
			throw new \App\PennyPincher\Domain\Access\AccessDenied('Nie możesz tworzyć organizacji, więc nie możesz też utworzyć projektu demonstracyjnego.');
		}

		$projectId = $demo->create($this->access->userId());

		return $this->redirect(AppUrl::project($projectId), 'Utworzono projekt demonstracyjny z fikcyjnymi danymi.');
	}

	private function form(Request $request, array $errors = [], int $status = 200)
	{
		$organizations = array_values(array_filter(
			$this->organizations->forUser($this->access->userId()),
			fn ($organization) => Policy::allows(Role::tryFrom((string) $organization['role']), Ability::CreateProject)
		));

		return $this->render('app.pages.projects.create', [
			'organizations' => $organizations,
			'canCreateOrganization' => $this->access->canCreateOrganizations($this->access->userId()),
			'errors' => $errors,
			'old' => $request->isMethod('POST') ? $request->input() : ['organization_id' => $request->query('organization')],
			'pageTitle' => 'Nowy projekt',
		], $status);
	}
}
