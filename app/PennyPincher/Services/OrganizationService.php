<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\OrganizationRepository;

class OrganizationService
{
	public function __construct(private OrganizationRepository $organizations, private Db $db)
	{
	}

	/**
	 * Tworzy organizację; twórca zostaje jej właścicielem.
	 */
	public function create(string $name, int $ownerId): int
	{
		$name = trim(sanitize_text_field($name));

		if ($name === '') {
			throw new ValidationFailed(['organization_name' => 'Podaj nazwę organizacji.']);
		}

		if (mb_strlen($name) > 191) {
			throw new ValidationFailed(['organization_name' => 'Nazwa może mieć maksymalnie 191 znaków.']);
		}

		return $this->db->transaction(function () use ($name, $ownerId) {
			$id = $this->organizations->create($name, $this->uniqueSlug($name));
			$this->organizations->addMember($id, $ownerId, Role::Owner);

			return $id;
		});
	}

	/**
	 * Dodaje istniejącego użytkownika WordPressa (po e-mailu lub loginie) albo zmienia jego rolę.
	 */
	public function addMember(int $organizationId, Role $actorRole, string $identifier, string $roleValue): void
	{
		$role = Role::tryFrom($roleValue);

		if ($role === null) {
			throw new ValidationFailed(['role' => 'Wybierz rolę.']);
		}

		$this->assertCanAssign($actorRole, $role);

		$identifier = trim($identifier);
		$user = is_email($identifier) ? get_user_by('email', $identifier) : get_user_by('login', $identifier);

		if (! $user) {
			throw new ValidationFailed(['identifier' => 'Nie znaleziono użytkownika o tym adresie e-mail lub loginie. Konto musi najpierw istnieć w WordPressie.']);
		}

		$current = $this->organizations->roleOf($user->ID, $organizationId);

		if ($current === Role::Owner && $role !== Role::Owner) {
			$this->assertNotLastOwner($organizationId);
			$this->assertCanAssign($actorRole, Role::Owner);
		}

		$this->organizations->addMember($organizationId, $user->ID, $role);
	}

	public function removeMember(int $organizationId, Role $actorRole, int $userId): void
	{
		$current = $this->organizations->roleOf($userId, $organizationId);

		if ($current === null) {
			return;
		}

		$this->assertCanAssign($actorRole, $current);

		if ($current === Role::Owner) {
			$this->assertNotLastOwner($organizationId);
		}

		$this->organizations->removeMember($organizationId, $userId);
	}

	private function assertCanAssign(Role $actor, Role $target): void
	{
		if (! Policy::allows($actor, Ability::ManageMembers)) {
			throw new AccessDenied('Tylko właściciel lub administrator może zarządzać członkami organizacji.');
		}

		if ($target === Role::Owner && ! Policy::allows($actor, Ability::ManageOwners)) {
			throw new AccessDenied('Tylko właściciel może nadawać lub odbierać rolę właściciela.');
		}
	}

	private function assertNotLastOwner(int $organizationId): void
	{
		if ($this->organizations->countOwners($organizationId) <= 1) {
			throw new ValidationFailed(['identifier' => 'Organizacja musi mieć co najmniej jednego właściciela.']);
		}
	}

	private function uniqueSlug(string $name): string
	{
		$base = sanitize_title($name) ?: 'organizacja';
		$slug = $base;
		$i = 2;

		while ($this->organizations->slugExists($slug)) {
			$slug = $base . '-' . $i++;
		}

		return $slug;
	}
}
