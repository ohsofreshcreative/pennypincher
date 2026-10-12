<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Dostęp do projektów. Każde odczytanie projektu przez użytkownika przechodzi przez JOIN z członkostwem
 * w organizacji – nie ma metody „pobierz projekt po id” bez kontekstu użytkownika poza CLI.
 */
class ProjectRepository
{
	public const EDITABLE = [
		'name', 'platform', 'currency', 'timezone', 'monthly_budget_micros', 'website_url', 'landing_page_url',
		'business_description', 'target_customer', 'min_project_value_micros', 'max_project_value_micros', 'status',
	];

	public function __construct(private Db $db)
	{
	}

	public function findForUser(int $userId, int $projectId): ?Project
	{
		$row = $this->db->first($this->baseQuery() . ' WHERE p.id = ?', [$userId, $projectId]);

		return $row ? Project::fromRow($row) : null;
	}

	/**
	 * @return array<int, Project>
	 */
	public function forUser(int $userId): array
	{
		$rows = $this->db->select($this->baseQuery() . " ORDER BY o.name, p.status = 'archived', p.is_demo, p.name", [$userId]);

		return array_map([Project::class, 'fromRow'], $rows);
	}

	/**
	 * Tylko dla CLI i usług systemowych (bez kontekstu użytkownika).
	 */
	public function findById(int $projectId): ?Project
	{
		$row = $this->db->first(
			sprintf(
				'SELECT p.*, o.name AS organization_name, NULL AS role FROM %s p INNER JOIN %s o ON o.id = p.organization_id WHERE p.id = ?',
				$this->db->table('projects'),
				$this->db->table('organizations')
			),
			[$projectId]
		);

		return $row ? Project::fromRow($row) : null;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(int $organizationId, array $values, bool $isDemo = false): int
	{
		$now = $this->db->now();
		$values = array_intersect_key($values, array_flip(self::EDITABLE));

		return $this->db->insert('projects', [
			'organization_id' => $organizationId,
			'slug' => $this->uniqueSlug($organizationId, (string) $values['name']),
			...$values,
			'is_demo' => $isDemo ? 1 : 0,
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function update(int $projectId, array $values): void
	{
		$values = array_intersect_key($values, array_flip(self::EDITABLE));
		$this->db->update('projects', $values + ['updated_at' => $this->db->now()], ['id' => $projectId]);
	}

	private function uniqueSlug(int $organizationId, string $name): string
	{
		$base = sanitize_title($name) ?: 'projekt';
		$slug = $base;
		$i = 2;

		while ($this->db->scalar('SELECT 1 FROM ' . $this->db->table('projects') . ' WHERE organization_id = ? AND slug = ?', [$organizationId, $slug]) !== null) {
			$slug = $base . '-' . $i++;
		}

		return $slug;
	}

	private function baseQuery(): string
	{
		return sprintf(
			'SELECT p.*, o.name AS organization_name, ou.role
			FROM %s p
			INNER JOIN %s o ON o.id = p.organization_id
			INNER JOIN %s ou ON ou.organization_id = p.organization_id AND ou.user_id = ?',
			$this->db->table('projects'),
			$this->db->table('organizations'),
			$this->db->table('organization_users')
		);
	}
}
