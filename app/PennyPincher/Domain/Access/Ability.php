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
	// Prompt 4: planowanie i monitoring (rozszerzenie istniejących ról – bez nowego systemu ról).
	case ManageKeywords = 'manage_keywords';
	case EditPlans = 'edit_plans';
	case ApprovePlans = 'approve_plans';
	case ManageBudget = 'manage_budget';
	case RunPaidAnalysis = 'run_paid_analysis';
	case ManageAlerts = 'manage_alerts';
	// Prompt 5: zmiany Google Ads (Change Center). Wykonanie wymaga dodatkowo flagi „wykonawca” nadanej przez właściciela.
	case PrepareChanges = 'prepare_changes';
	case ApproveChanges = 'approve_changes';
	case ExecuteChanges = 'execute_changes';
	case ManageWriteAccess = 'manage_write_access';

	public function minimumRole(): Role
	{
		return match ($this) {
			self::ViewProject, self::ImportData, self::ManageKeywords, self::EditPlans, self::ManageAlerts, self::PrepareChanges => Role::Member,
			self::UpdateProject, self::CreateProject, self::ManageMembers, self::ApprovePlans, self::ManageBudget, self::RunPaidAnalysis,
			self::ApproveChanges, self::ExecuteChanges => Role::Admin,
			self::ManageOwners, self::ManageWriteAccess => Role::Owner,
		};
	}
}
