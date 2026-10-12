<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Infrastructure\Database\Db;

class OrganizationRepository
{
	public function __construct(private Db $db)
	{
	}

	public function create(string $name, string $slug): int
	{
		$now = $this->db->now();

		return $this->db->insert('organizations', ['name' => $name, 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now]);
	}

	public function slugExists(string $slug): bool
	{
		return $this->db->scalar('SELECT 1 FROM ' . $this->db->table('organizations') . ' WHERE slug = ? LIMIT 1', [$slug]) !== null;
	}

	/**
	 * Organizacje użytkownika z jego rolą.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forUser(int $userId): array
	{
		return $this->db->select(
			sprintf(
				'SELECT o.*, ou.role FROM %s o INNER JOIN %s ou ON ou.organization_id = o.id AND ou.user_id = ? ORDER BY o.name',
				$this->db->table('organizations'),
				$this->db->table('organization_users')
			),
			[$userId]
		);
	}

	/**
	 * @return array<string, mixed>|null organizacja z rolą użytkownika albo null, gdy nie jest członkiem
	 */
	public function findForUser(int $userId, int $organizationId): ?array
	{
		return $this->db->first(
			sprintf(
				'SELECT o.*, ou.role FROM %s o INNER JOIN %s ou ON ou.organization_id = o.id AND ou.user_id = ? WHERE o.id = ?',
				$this->db->table('organizations'),
				$this->db->table('organization_users')
			),
			[$userId, $organizationId]
		);
	}

	public function roleOf(int $userId, int $organizationId): ?Role
	{
		$role = $this->db->scalar(
			'SELECT role FROM ' . $this->db->table('organization_users') . ' WHERE user_id = ? AND organization_id = ?',
			[$userId, $organizationId]
		);

		return $role === null ? null : Role::tryFrom((string) $role);
	}

	public function hasAnyMembership(int $userId): bool
	{
		return $this->db->scalar('SELECT 1 FROM ' . $this->db->table('organization_users') . ' WHERE user_id = ? LIMIT 1', [$userId]) !== null;
	}

	public function addMember(int $organizationId, int $userId, Role $role): void
	{
		$now = $this->db->now();

		$this->db->statement(
			'INSERT INTO ' . $this->db->table('organization_users') . ' (organization_id, user_id, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE role = VALUES(role), can_execute_changes = IF(VALUES(role) = \'member\', 0, can_execute_changes), updated_at = VALUES(updated_at)',
			[$organizationId, $userId, $role->value, $now, $now]
		);
	}

	public function removeMember(int $organizationId, int $userId): void
	{
		$this->db->statement('DELETE FROM ' . $this->db->table('organization_users') . ' WHERE organization_id = ? AND user_id = ?', [$organizationId, $userId]);
	}

	public function removeUserEverywhere(int $userId): void
	{
		$this->db->statement('DELETE FROM ' . $this->db->table('organization_users') . ' WHERE user_id = ?', [$userId]);
	}

	public function countOwners(int $organizationId): int
	{
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table('organization_users') . ' WHERE organization_id = ? AND role = ?',
			[$organizationId, Role::Owner->value]
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function members(int $organizationId): array
	{
		return $this->db->select(
			sprintf(
				'SELECT ou.user_id, ou.role, ou.can_execute_changes, ou.created_at, u.user_email, u.display_name, u.user_login
				FROM %s ou LEFT JOIN %s u ON u.ID = ou.user_id
				WHERE ou.organization_id = ? ORDER BY FIELD(ou.role, \'owner\', \'admin\', \'member\'), u.display_name',
				$this->db->table('organization_users'),
				$this->db->wpdb()->users
			),
			[$organizationId]
		);
	}

	/**
	 * Wykonawca zmian Google Ads: członek z rolą co najmniej administratora i flagą nadaną przez właściciela.
	 */
	public function isExecutor(int $organizationId, int $userId): bool
	{
		$row = $this->db->first('SELECT role, can_execute_changes FROM ' . $this->db->table('organization_users') . ' WHERE organization_id = ? AND user_id = ?', [$organizationId, $userId]);

		return $row !== null && (int) $row['can_execute_changes'] === 1 && in_array($row['role'], [Role::Owner->value, Role::Admin->value], true);
	}

	public function setExecutor(int $organizationId, int $userId, bool $enabled): void
	{
		$this->db->statement(
			'UPDATE ' . $this->db->table('organization_users') . " SET can_execute_changes = ?, updated_at = ? WHERE organization_id = ? AND user_id = ? AND role IN ('owner', 'admin')",
			[$enabled ? 1 : 0, $this->db->now(), $organizationId, $userId]
		);
	}
}
