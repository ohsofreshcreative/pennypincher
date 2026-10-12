<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\DashboardService;
use Tests\IntegrationTestCase;

/**
 * Regresja importu „Konwersje.csv” z Edytora raportów Google Ads – pełny przebieg do bazy i dashboardu.
 */
class ConversionImportTest extends IntegrationTestCase
{
	public function testReportEditorConversionsImportEndToEnd(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'OhSoFresh')['project'];

		[$importId, $result] = $this->import($project, $user, 'konwersje-pl-edytor-raportow.csv');

		$this->assertTrue($result->success, implode(' ', $result->errors));
		$this->assertSame(6, $result->imported);

		$import = $this->make(ImportRepository::class)->findInProject($project->id, $importId);
		$this->assertSame('conversion_actions', $import['report_type']);
		$this->assertSame('period', $import['granularity']);
		$this->assertSame('2026-01-01', $import['date_from']);
		$this->assertSame('2026-06-29', $import['date_to']);
		$this->assertSame(['2026-01-01', '2026-06-29'], $import['meta']['detected_period']);

		$this->assertSame(6, $this->count('conversion_actions', $project->id));
		$this->assertSame('181.0000', (string) $this->db->scalar('SELECT SUM(conversions) FROM ' . $this->db->table('conversion_metrics') . ' WHERE project_id = ?', [$project->id]));
		$this->assertSame('2.0000', (string) $this->db->scalar('SELECT SUM(view_through_conversions) FROM ' . $this->db->table('conversion_metrics') . ' WHERE project_id = ?', [$project->id]));

		$data = $this->make(DashboardService::class)->build($project, new DateRange('2026-01-01', '2026-06-29'));
		$this->assertTrue($data['hasAnyData']);
		$this->assertCount(6, $data['conversionActions']);
		$this->assertSame('30 sekund - Bezpłatna konsultacja', $data['conversionActions'][0]['name']);
		$this->assertSame('129.0000', (string) $data['conversionActions'][0]['conversions']);
		$this->assertNull($data['kpis']['spend'], 'Raport konwersji nie tworzy wydatków – brak danych, nie zero.');

		// Zakres, w który raport okresowy nie wchodzi w całości → brak danych, z informacją o dostępnym okresie.
		$partial = $this->make(DashboardService::class)->build($project, new DateRange('2026-03-01', '2026-03-31'));
		$this->assertSame([], $partial['conversionActions']);
		$this->assertSame([['2026-01-01', '2026-06-29']], $partial['conversionResolved']->outsidePeriods);
	}

	public function testReimportAndOverlappingConversionReportsAreNotDoubleCounted(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		$this->import($project, $user, 'konwersje-pl-edytor-raportow.csv');
		$this->import($project, $user, 'konwersje-pl-edytor-raportow.csv');
		$this->import($project, $user, 'konwersje-pl.xlsx');
		$this->assertSame(6, $this->count('conversion_metrics', $project->id), 'Ponowny import nadpisuje te same rekordy.');

		// Ten sam plik jako krótszy okres w środku – nakłada się na pełny raport.
		$this->import($project, $user, 'konwersje-pl-edytor-raportow.csv', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28']);

		$metrics = $this->make(MetricsRepository::class);
		$resolved = $metrics->resolve('conversion_metrics', $project->id, new DateRange('2026-01-01', '2026-06-29'));
		$actions = $metrics->conversionActions($project->id, $resolved);

		$this->assertEqualsWithDelta(181.0, array_sum(array_map(fn ($a) => (float) $a['conversions'], $actions)), 0.0001);
		$this->assertCount(1, $resolved->excludedPeriods);
	}

	public function testConversionDataIsIsolatedBetweenOrganizations(): void
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$a = $this->organizationWithProject($alice, 'A')['project'];
		$b = $this->organizationWithProject($bob, 'B')['project'];

		$this->import($a, $alice, 'konwersje-pl-edytor-raportow.csv');

		$metrics = $this->make(MetricsRepository::class);
		$range = new DateRange('2026-01-01', '2026-06-29');

		$this->assertCount(6, $metrics->conversionActions($a->id, $metrics->resolve('conversion_metrics', $a->id, $range)));
		$this->assertSame([], $metrics->conversionActions($b->id, $metrics->resolve('conversion_metrics', $b->id, $range)));
	}

	public function testVariantsFileImportsAndSkipsTotals(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		[, $result] = $this->import($project, $user, 'konwersje-pl-warianty.csv');

		$this->assertTrue($result->success, implode(' ', $result->errors));
		$this->assertSame(3, $result->imported);
		$this->assertSame('3703.6800', (string) $this->db->scalar('SELECT SUM(conversions) FROM ' . $this->db->table('conversion_metrics') . ' WHERE project_id = ?', [$project->id]));
	}
}
