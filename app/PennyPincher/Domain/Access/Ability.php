<?php

namespace App\PennyPincher\Domain\Access;

/**
 * Uprawnienia w obrębie organizacji i jej projektów.
 */
enum Ability: string
{
	case ViewProject = 'view_project';
	case ImportData = 'import_data';
	case UpdateProject = 'update_project';
	case CreateProject = 'create_project';
	case ManageMembers = 'manage_members';
	case ManageOwners = 'manage_owners';

	public function minimumRole(): Role
	{
		return match ($this) {
			self::ViewProject, self::ImportData => Role::Member,
			self::UpdateProject, self::CreateProject, self::ManageMembers => Role::Admin,
			self::ManageOwners => Role::Owner,
		};
	}
}
