<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Repositories\ProjectRepository;

/**
 * Autoryzacja na poziomie backendu. Kontrolery pobierają projekty i organizacje wyłącznie przez tę klasę.
 */
class AccessService
{
	public const CURRENT_PROJECT_META = 'pp_current_project';

	public function __construct(
		private ProjectRepository $projects,
		private OrganizationRepository $organizations,
	) {
	}

	public function userId(): int
	{
		return get_current_user_id();
	}

	/**
	 * Użytkownik może korzystać z aplikacji, jeśli należy do organizacji albo może ją założyć.
	 */
	public function canUseApp(int $userId): bool
	{
		return $userId > 0 && ($this->organizations->hasAnyMembership($userId) || $this->canCreateOrganizations($userId));
	}

	public function canCreateOrganizations(int $userId): bool
	{
		return (bool) apply_filters('pennypincher/can_create_organization', user_can($userId, 'manage_options'), $userId);
	}

	/**
	 * @throws NotFound gdy projekt nie istnieje lub użytkownik nie należy do jego organizacji
	 * @throws AccessDenied gdy rola nie pozwala na daną operację
	 */
	public function project(int $projectId, Ability $ability = Ability::ViewProject): Project
	{
		$project = $this->projects->findForUser($this->userId(), $projectId);

		if ($project === null) {
			throw new NotFound('Projekt nie istnieje albo nie masz do niego dostępu.');
		}

		$this->authorize($project->role, $ability);

		return $project;
	}

	/**
	 * @return array<string, mixed> organizacja z rolą użytkownika
	 */
	public function organization(int $organizationId, Ability $ability): array
	{
		$organization = $this->organizations->findForUser($this->userId(), $organizationId);

		if ($organization === null) {
			throw new NotFound('Organizacja nie istnieje albo nie masz do niej dostępu.');
		}

		$this->authorize(Role::tryFrom((string) $organization['role']), $ability);

		return $organization;
	}

	public function authorize(?Role $role, Ability $ability): void
	{
		if (! Policy::allows($role, $ability)) {
			throw new AccessDenied('Twoja rola w organizacji nie pozwala na tę operację.');
		}
	}

	/**
	 * @return array<int, Project>
	 */
	public function projects(): array
	{
		return $this->projects->forUser($this->userId());
	}

	public function rememberProject(Project $project): void
	{
		update_user_meta($this->userId(), self::CURRENT_PROJECT_META, $project->id);
	}

	public function currentProject(): ?Project
	{
		$remembered = (int) get_user_meta($this->userId(), self::CURRENT_PROJECT_META, true);

		if ($remembered > 0 && ($project = $this->projects->findForUser($this->userId(), $remembered))) {
			return $project;
		}

		foreach ($this->projects() as $project) {
			if ($project->status !== 'archived') {
				return $project;
			}
		}

		return null;
	}
}
