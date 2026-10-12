<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Import\FileInspector;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\ImportService;
use Tests\IntegrationTestCase;

class ImportTest extends IntegrationTestCase
{
	public function testCampaignPeriodReportCsv(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		[$importId, $result] = $this->import($project, $user, 'campaigns-en-period.csv');

		$this->assertTrue($result->success, implode(' ', $result->errors));
		$this->assertSame(3, $result->imported);
		$this->assertSame(2, $result->skipped, 'Dwa wiersze podsumowań.');
		$this->assertSame(3, $this->count('campaigns', $project->id));
		$this->assertSame(1_870_380_000, $this->sum('campaign_metrics', 'cost_micros', $project->id));

		$import = $this->make(ImportRepository::class)->findInProject($project->id, $importId);
		$this->assertSame('completed', $import['status']);
		$this->assertSame('period', $import['granularity']);
		$this->assertSame('2026-09-01', $import['date_from']);
		$this->assertSame('2026-09-30', $import['date_to']);
		$this->assertSame([], $this->make(ImportRepository::class)->staged($importId), 'Surowe wiersze są usuwane po imporcie.');

		$campaign = $this->db->first('SELECT * FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? AND external_id = ?', [$project->id, '111222444']);
		$this->assertSame('paused', $campaign['status']);
		$this->assertSame('30000000', (string) $campaign['budget_micros']);

		// „--” w pliku = brak danych (NULL), nie zero.
		$pmax = $this->db->first(
			'SELECT m.* FROM ' . $this->db->table('campaign_metrics') . ' m JOIN ' . $this->db->table('campaigns') . ' c ON c.id = m.campaign_id WHERE c.project_id = ? AND c.external_id = ?',
			[$project->id, '111222555']
		);
		$this->assertNull($pmax['impressions']);
		$this->assertNull($pmax['conversions']);
		$this->assertSame('0', (string) $pmax['cost_micros']);
	}

	public function testReimportIsIdempotent(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		$this->import($project, $user, 'campaigns-en-period.csv');
		$this->import($project, $user, 'search-terms-pl.csv');
		$before = [$this->count('campaign_metrics', $project->id), $this->sum('campaign_metrics', 'cost_micros', $project->id), $this->count('search_term_metrics', $project->id), $this->count('campaigns', $project->id)];

		[, $again] = $this->import($project, $user, 'campaigns-en-period.csv');
		$this->import($project, $user, 'search-terms-pl.csv');
		$after = [$this->count('campaign_metrics', $project->id), $this->sum('campaign_metrics', 'cost_micros', $project->id), $this->count('search_term_metrics', $project->id), $this->count('campaigns', $project->id)];

		$this->assertTrue($again->success);
		$this->assertSame($before, $after);
	}

	public function testSearchTermsDoNotChangeCampaignSpend(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		$this->import($project, $user, 'campaigns-en-period.csv');
		[, $result] = $this->import($project, $user, 'search-terms-pl.csv');

		$this->assertTrue($result->success, implode(' ', $result->errors));
		$this->assertSame(3, $this->count('search_terms', $project->id));
		$this->assertSame(272_570_000, $this->sum('search_term_metrics', 'cost_micros', $project->id));
		// Kampanie dopasowane po nazwie do istniejących – bez duplikatów.
		$this->assertSame(3, $this->count('campaigns', $project->id));
		$this->assertSame(1_870_380_000, $this->sum('campaign_metrics', 'cost_micros', $project->id));

		$term = $this->db->first('SELECT * FROM ' . $this->db->table('search_terms') . ' WHERE project_id = ? AND term = ?', [$project->id, 'redesign strony firmowej']);
		$this->assertSame('exact_close_variant', $term['match_type']);
		$this->assertSame('[redesign strony]', $term['keyword_text']);
	}

	public function testDailyXlsxImports(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		[$importId, $result] = $this->import($project, $user, 'campaigns-en-daily.xlsx');

		$this->assertTrue($result->success, implode(' ', $result->errors));
		$this->assertSame(60, $result->imported);
		$this->assertSame(60, $this->count('campaign_metrics', $project->id));
		$this->assertSame((int) round(30 * (49.95 + 12.4) * 1_000_000), $this->sum('campaign_metrics', 'cost_micros', $project->id));

		$import = $this->make(ImportRepository::class)->findInProject($project->id, $importId);
		$this->assertSame('day', $import['granularity']);
		$this->assertSame('2026-09-01', $import['date_from']);
		$this->assertSame('2026-09-30', $import['date_to']);
	}

	public function testKeywordXlsxImport(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		[, $result] = $this->import($project, $user, 'keywords-pl-daily.xlsx');

		$this->assertTrue($result->success, implode(' ', $result->errors));
		$this->assertSame(2, $this->count('keywords', $project->id));
		$this->assertSame(4, $this->count('keyword_metrics', $project->id));

		$keyword = $this->db->first('SELECT * FROM ' . $this->db->table('keywords') . ' WHERE project_id = ? AND text = ?', [$project->id, 'strony internetowe']);
		$this->assertSame('exact', $keyword['match_type']);
		$this->assertSame('7', (string) $keyword['quality_score']);
		$this->assertSame('enabled', $keyword['status']);
	}

	public function testConversionActionsImport(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		[, $result] = $this->import($project, $user, 'conversion-actions-en.csv', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);

		$this->assertTrue($result->success, implode(' ', $result->errors));
		$this->assertSame(2, $this->count('conversion_actions', $project->id));

		$action = $this->db->first('SELECT * FROM ' . $this->db->table('conversion_actions') . ' WHERE project_id = ? AND name = ?', [$project->id, 'Kliknięcie w telefon']);
		$this->assertSame('0', (string) $action['include_in_conversions']);
		$this->assertSame('Phone call lead', $action['category']);
	}

	public function testPeriodIsRequiredWithoutDayColumn(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		$e = $this->assertThrows(ValidationFailed::class, fn () => $this->import($project, $user, 'conversion-actions-en.csv'));
		$this->assertTrue(isset($e->errors['period']));
		$this->assertSame(0, $this->count('conversion_actions', $project->id));
	}

	public function testInvalidValuesSaveNothing(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		[$importId, $result] = $this->import($project, $user, 'invalid-values.csv', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);

		$this->assertFalse($result->success);
		$this->assertSame(2, $result->errorCount);
		$this->assertSame(0, $this->count('campaigns', $project->id));
		$this->assertSame(0, $this->count('campaign_metrics', $project->id));

		$import = $this->make(ImportRepository::class)->findInProject($project->id, $importId);
		$this->assertSame('uploaded', $import['status'], 'Import czeka na poprawę mapowania.');
		$this->assertCount(2, $this->make(ImportRepository::class)->staged($importId));
	}

	public function testCurrencyMismatchIsRejected(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma', 'EUR')['project'];

		[, $result] = $this->import($project, $user, 'campaigns-en-period.csv');

		$this->assertFalse($result->success);
		$this->assertContains('Waluta raportu (PLN)', $result->errors[0]);
		$this->assertSame(0, $this->count('campaign_metrics', $project->id));
	}

	public function testUnsafeOrUnreadableFilesAreRejected(): void
	{
		$this->assertThrows(InvalidValue::class, fn () => FileInspector::inspectPath($this->fixture('fake.xlsx'), 'fake.xlsx'), 'nie jest arkuszem');

		$php = wp_tempnam('x.csv');
		file_put_contents($php, "<?php echo 1;");
		$this->assertThrows(InvalidValue::class, fn () => FileInspector::inspectPath($php, 'raport.csv'));
		$this->assertThrows(InvalidValue::class, fn () => FileInspector::inspectPath($php, 'raport.php'), 'Nieobsługiwany');
		@unlink($php);

		$empty = wp_tempnam('e.csv');
		$this->assertThrows(InvalidValue::class, fn () => FileInspector::inspectPath($empty, 'pusty.csv'), 'pusty');
		@unlink($empty);

		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];
		$this->assertThrows(InvalidValue::class, fn () => $this->import($project, $user, 'no-headers.csv'));
		$this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('imports') . ' WHERE project_id = ?', [$project->id]));
	}

	public function testManualMappingValidation(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];
		$service = $this->make(ImportService::class);

		$copy = wp_tempnam('c.csv');
		copy($this->fixture('campaigns-en-period.csv'), $copy);
		$importId = $service->upload($project, $user, FileInspector::inspectPath($copy, 'c.csv'));
		@unlink($copy);
		$import = $this->make(ImportRepository::class)->findInProject($project->id, $importId);

		// Ta sama kolumna przypisana do dwóch pól + brak kolumny Kampania.
		$e = $this->assertThrows(ValidationFailed::class, fn () => $service->confirm($project, $import, [
			'report_type' => 'campaigns',
			'mapping' => ['impressions' => 7, 'clicks' => 7],
			'date_from' => '2026-09-01',
			'date_to' => '2026-09-30',
		]));

		$this->assertTrue(isset($e->errors['mapping.clicks']));
		$this->assertContains('Kampania', implode(' ', $e->errors));
	}

	public function testOverlappingPeriodImportsAreNotSummedInQueries(): void
	{
		$user = $this->user('owner');
		$project = $this->organizationWithProject($user, 'Firma')['project'];

		// Ten sam raport zaimportowany jako wrzesień i jako krótszy okres – nakładają się.
		$this->import($project, $user, 'campaigns-en-period.csv');
		$this->import($project, $user, 'campaigns-en-period.csv', ['date_from' => '2026-09-10', 'date_to' => '2026-09-20']);

		$metrics = $this->make(MetricsRepository::class);
		$resolved = $metrics->resolve('campaign_metrics', $project->id, new DateRange('2026-09-01', '2026-09-30'));
		$totals = $metrics->campaignTotals($project->id, $resolved);

		$this->assertSame(1_870_380_000, $totals['cost_micros']);
		$this->assertCount(1, $resolved->excludedPeriods);
	}
}
