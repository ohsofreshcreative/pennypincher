<?php

namespace Tests;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Metrics\Money;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\Import\FileInspector;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\ImportService;
use App\PennyPincher\Services\OrganizationService;
use App\PennyPincher\Services\ProcessResult;
use Roots\Acorn\Application;

/**
 * Każdy test działa w transakcji wycofywanej w tearDown – w bazie nie zostają żadne dane testowe.
 */
abstract class IntegrationTestCase extends TestCase
{
	protected Db $db;

	public function setUp(): void
	{
		$this->db = $this->make(Db::class);
		$this->db->begin();
		wp_set_current_user(0);
	}

	public function tearDown(): void
	{
		while ($this->db->transactionLevel() > 0) {
			$this->db->rollback();
		}

		wp_set_current_user(0);
	}

	/**
	 * @template T
	 * @param class-string<T> $class
	 * @return T
	 */
	protected function make(string $class): object
	{
		return Application::getInstance()->make($class);
	}

	protected function user(string $name, string $role = 'subscriber'): int
	{
		$id = wp_insert_user([
			'user_login' => 'pp_test_' . $name . '_' . wp_generate_password(6, false),
			'user_email' => 'pp-test-' . $name . '-' . wp_generate_password(6, false) . '@example.test',
			'user_pass' => wp_generate_password(),
			'role' => $role,
		]);

		if (is_wp_error($id)) {
			throw new \RuntimeException($id->get_error_message());
		}

		return $id;
	}

	/**
	 * @return array{organization: int, project: Project}
	 */
	protected function organizationWithProject(int $ownerId, string $name, string $currency = 'PLN'): array
	{
		$organizationId = $this->make(OrganizationService::class)->create($name, $ownerId);
		$projectId = $this->make(ProjectRepository::class)->create($organizationId, [
			'name' => $name . ' projekt',
			'platform' => 'google_ads',
			'currency' => $currency,
			'timezone' => 'Europe/Warsaw',
			'monthly_budget_micros' => 3000 * Money::MICROS,
			'min_project_value_micros' => 8000 * Money::MICROS,
			'status' => 'active',
		]);

		return ['organization' => $organizationId, 'project' => $this->make(ProjectRepository::class)->findForUser($ownerId, $projectId)];
	}

	protected function addMember(int $organizationId, int $userId, Role $role): void
	{
		$this->make(OrganizationRepository::class)->addMember($organizationId, $userId, $role);
	}

	/**
	 * Przesyła plik z katalogu fixtures i zatwierdza import z automatycznym mapowaniem.
	 */
	protected function import(Project $project, int $userId, string $fixture, array $overrides = []): array
	{
		$service = $this->make(ImportService::class);
		$copy = wp_tempnam($fixture);
		copy($this->fixture($fixture), $copy);

		try {
			$importId = $service->upload($project, $userId, FileInspector::inspectPath($copy, $fixture), $overrides['type'] ?? null);
		} finally {
			@unlink($copy);
		}

		$import = $this->make(ImportRepository::class)->findInProject($project->id, $importId);
		$result = $service->confirm($project, $import, $overrides + [
			'report_type' => $import['report_type'],
			'mapping' => $import['column_mapping'],
			'date_from' => $import['meta']['detected_period'][0] ?? '',
			'date_to' => $import['meta']['detected_period'][1] ?? '',
		]);

		return [$importId, $result];
	}

	protected function count(string $table, int $projectId): int
	{
		return (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table($table) . ' WHERE project_id = ?', [$projectId]);
	}

	protected function sum(string $table, string $column, int $projectId): ?int
	{
		$value = $this->db->scalar("SELECT SUM({$column}) FROM " . $this->db->table($table) . ' WHERE project_id = ?', [$projectId]);

		return $value === null ? null : (int) $value;
	}
}
