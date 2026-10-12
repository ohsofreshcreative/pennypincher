<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\Money;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Migrator;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\DashboardService;
use App\PennyPincher\Services\DemoProjectService;
use App\PennyPincher\Services\ProjectValidator;
use Tests\IntegrationTestCase;

class ProjectTest extends IntegrationTestCase
{
	public function testMigrationsAreCurrentAndIdempotent(): void
	{
		// Migracje (DDL) nie mogą działać w transakcji testu – sprawdzamy tylko stan i brak zmian.
		$migrator = $this->make(Migrator::class);
		$this->assertTrue($migrator->isCurrent());

		foreach (['organizations', 'organization_users', 'projects', 'imports', 'import_rows', 'campaigns', 'ad_groups', 'keywords', 'search_terms', 'conversion_actions', 'campaign_metrics', 'keyword_metrics', 'search_term_metrics', 'conversion_metrics'] as $table) {
			$this->assertSame($this->db->table($table), $this->db->scalar('SHOW TABLES LIKE ?', [$this->db->table($table)]), "Tabela {$table}");
		}
	}

	public function testValidatorNormalizesProjectSettings(): void
	{
		$values = $this->make(ProjectValidator::class)->validate([
			'name' => 'OhSoFresh – strony internetowe',
			'platform' => 'google_ads',
			'currency' => 'PLN',
			'timezone' => 'Europe/Warsaw',
			'monthly_budget' => '3 000',
			'min_project_value' => '8000',
			'max_project_value' => '30 000,00',
			'website_url' => 'https://ohsofresh.pl',
			'landing_page_url' => 'https://ohsofresh.pl/strony-internetowe/',
			'business_description' => 'Projektowanie i tworzenie stron dla firm.',
			'target_customer' => 'B2B, firmy rozważające redesign.',
			'status' => 'active',
		]);

		$this->assertSame(3000 * Money::MICROS, $values['monthly_budget_micros']);
		$this->assertSame(30000 * Money::MICROS, $values['max_project_value_micros']);
		$this->assertSame('https://ohsofresh.pl/strony-internetowe/', $values['landing_page_url']);
	}

	public function testValidatorRejectsInvalidInput(): void
	{
		$e = $this->assertThrows(ValidationFailed::class, fn () => $this->make(ProjectValidator::class)->validate([
			'name' => '',
			'currency' => 'XXX',
			'timezone' => 'Mars/Base',
			'monthly_budget' => 'dużo',
			'min_project_value' => '30000',
			'max_project_value' => '8000',
			'website_url' => 'javascript:alert(1)',
			'status' => 'deleted',
		]));

		foreach (['name', 'currency', 'timezone', 'monthly_budget', 'max_project_value', 'website_url', 'status'] as $field) {
			$this->assertTrue(isset($e->errors[$field]), "Brak błędu dla {$field}");
		}
	}

	public function testCreateAndUpdateProject(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];
		$repository = $this->make(ProjectRepository::class);

		$repository->update($project->id, ['name' => 'Nowa nazwa', 'monthly_budget_micros' => 5000 * Money::MICROS, 'slug' => 'hack', 'organization_id' => 999]);
		$updated = $repository->findForUser($user, $project->id);

		$this->assertSame('Nowa nazwa', $updated->name);
		$this->assertSame(5000 * Money::MICROS, $updated->monthlyBudgetMicros);
		$this->assertSame($project->organizationId, $updated->organizationId, 'Pola spoza listy edytowalnych są ignorowane.');
		$this->assertSame($project->slug, $updated->slug);
	}

	public function testDashboardUsesBudgetAndCampaignDataOnly(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];
		$this->import($project, $user, 'campaigns-en-period.csv');
		$this->import($project, $user, 'search-terms-pl.csv');

		$data = $this->make(DashboardService::class)->build($project, new DateRange('2026-09-01', '2026-09-30'));
		$kpis = $data['kpis'];

		$this->assertSame(1_870_380_000, $kpis['spend']);
		$this->assertSame(798, $kpis['clicks']);
		$this->assertSame(16345, $kpis['impressions']);
		$this->assertEqualsWithDelta(798 / 16345 * 100, $kpis['ctr'], 0.0001);
		$this->assertSame((int) round(1_870_380_000 / 798), $kpis['cpc']);
		$this->assertSame((int) round(1_870_380_000 / 12.5), $kpis['cpa']);
		$this->assertSame(3000 * Money::MICROS, $kpis['budget_for_range']);
		$this->assertEqualsWithDelta(1870.38 / 3000 * 100, $kpis['budget_used'], 0.0001);
		$this->assertSame([], $data['series'], 'Raport okresowy nie daje wykresów dziennych.');
		$this->assertSame(3, $data['searchTerms']->total);
	}

	public function testEmptyProjectDashboard(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		$data = $this->make(DashboardService::class)->build($project, new DateRange('2026-09-01', '2026-09-30'));

		$this->assertFalse($data['hasAnyData']);
		$this->assertNull($data['kpis']['spend']);
		$this->assertNull($data['kpis']['ctr']);
		$this->assertNull($data['kpis']['budget_used']);
	}

	public function testPaginationAndFilters(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];
		$this->import($project, $user, 'campaigns-en-period.csv');
		$this->import($project, $user, 'search-terms-pl.csv');

		$metrics = $this->make(\App\PennyPincher\Repositories\MetricsRepository::class);
		$range = new DateRange('2026-09-01', '2026-09-30');
		$resolved = $metrics->resolve('campaign_metrics', $project->id, $range);

		$page = $metrics->campaigns($project->id, $resolved, [], 'spend', 'desc', 2, 2);
		$this->assertSame(3, $page->total);
		$this->assertSame(2, $page->lastPage);
		$this->assertCount(1, $page->items);

		$this->assertSame(1, $metrics->campaigns($project->id, $resolved, ['q' => 'redesign'], 'name', 'asc', 1, 25)->total);
		$this->assertSame(1, $metrics->campaigns($project->id, $resolved, ['status' => 'paused'], 'name', 'asc', 1, 25)->total);
		// Wstrzyknięcie w parametr LIKE jest traktowane dosłownie.
		$this->assertSame(0, $metrics->campaigns($project->id, $resolved, ['q' => "%' OR 1=1 -- "], 'name', 'asc', 1, 25)->total);

		$termsResolved = $metrics->resolve('search_term_metrics', $project->id, $range);
		$this->assertSame(2, $metrics->searchTerms($project->id, $termsResolved, ['conversions' => 'with'], 'spend', 'desc', 1, 25)->total);
		$this->assertSame(1, $metrics->searchTerms($project->id, $termsResolved, ['conversions' => 'without'], 'spend', 'desc', 1, 25)->total);
		$this->assertSame('strony internetowe warszawa', $metrics->searchTerms($project->id, $termsResolved, [], 'spend', 'desc', 1, 25)->items[0]['term']);
	}

	public function testDemoProjectIsSeparatedAndFlagged(): void
	{
		$user = $this->user('owner');
		$projectId = $this->make(DemoProjectService::class)->create($user);
		$project = $this->make(ProjectRepository::class)->findForUser($user, $projectId);

		$this->assertTrue($project->isDemo);
		$this->assertSame('Demo', $project->organizationName);
		$this->assertTrue($this->count('campaign_metrics', $projectId) > 0);
		$this->assertTrue($this->count('search_term_metrics', $projectId) > 0);
	}
}
