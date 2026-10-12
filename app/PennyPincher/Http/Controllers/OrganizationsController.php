<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\OrganizationService;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

class OrganizationsController extends Controller
{
	public function __construct(
		AccessService $access,
		private OrganizationService $service,
		private OrganizationRepository $organizations,
	) {
		parent::__construct($access);
	}

	public function store(Request $request)
	{
		if (! $this->access->canCreateOrganizations($this->access->userId())) {
			throw new AccessDenied('Nie możesz tworzyć organizacji. Poproś administratora o dodanie do istniejącej.');
		}

		try {
			$organizationId = $this->service->create((string) $request->input('organization_name'), $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::to('projects'), reset($e->errors), 'error');
		}

		return $this->redirect(AppUrl::to('projects/new', ['organization' => $organizationId]), 'Organizacja została utworzona. Dodaj w niej pierwszy projekt.');
	}

	public function members(int $organization, Request $request, array $errors = [], int $status = 200)
	{
		$org = $this->access->organization($organization, Ability::ViewProject);
		$role = Role::from($org['role']);

		return $this->render('app.pages.organizations.members', [
			'organization' => $org,
			'role' => $role,
			'members' => $this->organizations->members($organization),
			'canManage' => $role->atLeast(Role::Admin),
			'roles' => $role === Role::Owner ? Role::options() : array_diff_key(Role::options(), [Role::Owner->value => true]),
			'errors' => $errors,
			'old' => $request->isMethod('POST') ? $request->input() : [],
			'pageTitle' => 'Członkowie: ' . $org['name'],
		], $status);
	}

	public function addMember(int $organization, Request $request)
	{
		$org = $this->access->organization($organization, Ability::ManageMembers);

		try {
			$this->service->addMember($organization, Role::from($org['role']), (string) $request->input('identifier'), (string) $request->input('role'));
		} catch (ValidationFailed $e) {
			return $this->members($organization, $request, $e->errors, 422);
		}

		return $this->redirect(AppUrl::to("organizations/{$organization}/members"), 'Zapisano członka organizacji.');
	}

	public function removeMember(int $organization, int $user, Request $request)
	{
		$org = $this->access->organization($organization, Ability::ManageMembers);

		try {
			$this->service->removeMember($organization, Role::from($org['role']), $user);
		} catch (ValidationFailed $e) {
			return $this->members($organization, $request, $e->errors, 422);
		}

		if ($user === $this->access->userId()) {
			return $this->redirect(AppUrl::to('projects'), 'Opuściłeś organizację.');
		}

		return $this->redirect(AppUrl::to("organizations/{$organization}/members"), 'Usunięto członka organizacji.');
	}
}
