<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Infrastructure\GoogleAds\Sync\ApiSyncer;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use App\PennyPincher\Services\GoogleAds\SyncService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\GoogleAdsMock;

require_once __DIR__ . '/GoogleAdsTestCase.php';

/**
 * Synchronizacja na atrapie API: backfill, idempotencja, wznowienie, ponowienia, blokada, pierwszeństwo źródeł.
 */
class GoogleAdsSyncTest extends GoogleAdsTestCase
{
	public function testBackfillImportsTwelveMonthsAndIsIdempotent(): void
	{
		[$project, $connection] = $this->connected('backfill');
		$sync = $this->make(SyncService::class);

		$run = $sync->enqueue($connection, 'incremental');
		$this->assertSame('backfill', $run['type'], 'Pierwsza synchronizacja zawsze pobiera historię.');
		$this->assertSame(5 + 4 * count(SyncService::monthWindows($run['date_from'], $run['date_to'])), (int) $run['steps_total']);
		$this->assertSame($run['id'], $sync->enqueue($connection, 'backfill')['id'], 'Otwarty przebieg nie jest dublowany.');

		$yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
		$this->assertSame($yesterday, $run['date_to']);
		$this->assertSame((new DateTimeImmutable($yesterday))->modify('-12 months')->modify('+1 day')->format('Y-m-d'), $run['date_from']);

		$run = $sync->process($run, time() + 300);
		$this->assertSame('completed', $run['status'], (string) $run['last_error']);
		$this->assertSame((int) $run['steps_total'], (int) $run['step_index']);
		$days = (new DateRange($run['date_from'], $run['date_to']))->days();

		$this->assertSame($days, $this->count('campaign_metrics', $project->id), 'Jeden wiersz dziennie na kampanię.');
		$this->assertSame($days * 20_000_000, $this->sum('campaign_metrics', 'cost_micros', $project->id));
		$this->assertSame(2, $this->count('search_terms', $project->id), 'Dwa unikalne hasła.');
		$this->assertSame($days * 2, $this->count('search_term_metrics', $project->id), 'Segmenty tego samego hasła i dnia zsumowane.');
		$this->assertSame($days * (10_000_000 + 6_000_000), $this->sum('search_term_metrics', 'cost_micros', $project->id));
		$this->assertSame(1, $this->count('campaigns', $project->id));
		$this->assertSame(2, $this->count('conversion_actions', $project->id));

		$campaign = $this->db->first('SELECT * FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ?', [$project->id]);
		$settings = json_decode($campaign['settings'], true);
		$this->assertSame('111', $campaign['external_id']);
		$this->assertSame(50_000_000, (int) $campaign['budget_micros']);
		$this->assertTrue($settings['networks']['search_partners'] && $settings['networks']['display']);
		$this->assertSame('PRESENCE_OR_INTEREST', $settings['geo_target_type']);
		$this->assertSame('Poland', $settings['locations'][0]['name']);

		$action = $this->db->first('SELECT * FROM ' . $this->db->table('conversion_actions') . " WHERE project_id = ? AND external_id = '901'", [$project->id]);
		$this->assertSame('PAGE_VIEW', $action['category_code']);
		$this->assertSame(1, (int) $action['primary_for_goal']);

		$this->assertTrue($this->make(AuditRepository::class)->latestRun($project->id) !== null, 'Audyt uruchamia się po synchronizacji.');

		// Ponowna pełna synchronizacja nie tworzy duplikatów.
		$second = $sync->process($sync->enqueue($connection, 'backfill'), time() + 300);
		$this->assertSame('completed', $second['status']);
		$this->assertSame($days, $this->count('campaign_metrics', $project->id));
		$this->assertSame($days * 20_000_000, $this->sum('campaign_metrics', 'cost_micros', $project->id));
		$this->assertSame($days * 2, $this->count('search_term_metrics', $project->id));
		$this->assertSame(1, $this->count('campaigns', $project->id));
		$this->assertSame(2, $this->count('conversion_actions', $project->id));

		// Żądania nie zawierają tokenów w adresach i nie trafiają poza Google.
		foreach ($this->mock->requests as $request) {
			$this->assertFalse(str_contains($request['url'], 'mock-access') || str_contains($request['url'], 'mock-refresh'));
			$this->assertTrue(in_array(parse_url($request['url'], PHP_URL_HOST), ['oauth2.googleapis.com', 'googleads.googleapis.com'], true));
		}
	}

	public function testApiDataTakesPrecedenceOverImportsWithoutDoubleCounting(): void
	{
		[$project, $connection] = $this->connected('precedence');
		$sync = $this->make(SyncService::class);
		$run = $sync->process($sync->enqueue($connection, 'backfill'), time() + 300);
		$campaignId = (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ?', [$project->id]);
		$apiFrom = $run['date_from'];
		$before = (new DateTimeImmutable($apiFrom))->modify('-1 day')->format('Y-m-d');

		// Import CSV pokrywający 3 dni z API (inne kwoty) + 1 dzień sprzed zakresu API + raport okresowy nachodzący na API.
		$rows = [];
		foreach ([$before, $apiFrom, $run['date_to']] as $date) {
			$rows[] = $this->importRow($project->id, $campaignId, 'day', $date, $date, 999_000_000);
		}
		$rows[] = $this->importRow($project->id, $campaignId, 'period', (new DateTimeImmutable($run['date_to']))->modify('-6 days')->format('Y-m-d'), $run['date_to'], 5_000_000_000);
		$this->db->insertMany('campaign_metrics', $rows);

		$metrics = $this->make(MetricsRepository::class);
		$range = new DateRange($before, $run['date_to']);
		$resolved = $metrics->resolve('campaign_metrics', $project->id, $range);
		$totals = $metrics->campaignTotals($project->id, $resolved);
		$apiDays = (new DateRange($apiFrom, $run['date_to']))->days();

		$this->assertSame($apiDays * 20_000_000 + 999_000_000, $totals['cost_micros'], 'Dni z API z API; dzień spoza API z importu; nic podwójnie.');
		$this->assertSame(3, $resolved->overriddenByApi, 'Dwa dni i raport okresowy zastąpione przez API.');
		$this->assertSame('api_mixed', $resolved->source());
		$this->assertTrue($resolved->isComplete());
		$this->assertSame(4, (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('campaign_metrics') . " WHERE project_id = ? AND source = 'import'", [$project->id]), 'Dane importu pozostają w bazie (historia).');
	}

	public function testDaysWithoutApiRowsCountAsZeroInsideCoverage(): void
	{
		[$project, $connection] = $this->connected('zero-days');
		// API nie zwraca wierszy dla dni bez aktywności – pokrycie okna mówi, że to zera, nie brak danych.
		$this->mock->on('campaign_metrics', fn ($request) => [200, GoogleAdsMock::stream(array_map(fn ($date) => ['campaign' => ['id' => '111'], 'segments' => ['date' => $date], 'metrics' => ['costMicros' => '1000000', 'clicks' => '1', 'impressions' => '1']], array_slice(GoogleAdsMock::dates($request['gaql']), 0, 1)))]);
		$sync = $this->make(SyncService::class);
		$run = $sync->process($sync->enqueue($connection, 'backfill'), time() + 300);

		$metrics = $this->make(MetricsRepository::class);
		$range = new DateRange((new DateTimeImmutable($run['date_to']))->modify('-29 days'), $run['date_to']);
		$resolved = $metrics->resolve('campaign_metrics', $project->id, $range);

		$this->assertTrue($resolved->isComplete(), 'Okres w całości pokryty przez API.');
		$series = $metrics->dailySeries($project->id, $resolved, $range);
		$this->assertSame(0, $series[count($series) - 1]['cost_micros'] ?? null, 'Dzień bez wierszy w API = 0, nie null.');
	}

	public function testTransientErrorIsRetriedWithBackoffAndResumesFromFailedStep(): void
	{
		[$project, $connection] = $this->connected('resume');
		$sync = $this->make(SyncService::class);
		$failures = 1;
		$this->mock->on('keyword_view', function ($request) use (&$failures) {
			if ($failures-- > 0) {
				return [503, ['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'Backend error']]];
			}

			return [200, GoogleAdsMock::stream([])];
		});

		$run = $sync->process($sync->enqueue($connection, 'backfill'), time() + 300);
		$this->assertSame('queued', $run['status'], 'Błąd chwilowy → ponowienie, nie porażka.');
		$this->assertSame(1, (int) $run['attempts']);
		$this->assertSame(6, (int) $run['step_index'], 'Zatrzymane na kroku metryk słów kluczowych (po 5 krokach struktury i metrykach kampanii).');
		$this->assertTrue(strtotime($run['next_attempt_at'] . ' UTC') >= time() + 55, 'Odczekanie 60 s przed ponowieniem.');
		$this->assertSame([], $this->make(SyncRunRepository::class)->due(), 'Przed upływem opóźnienia przebieg nie jest wykonywany.');

		$campaignQueries = $this->mock->count('campaign');
		$this->make(SyncRunRepository::class)->update((int) $run['id'], ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
		$result = $sync->work(300);
		$run = $this->make(SyncRunRepository::class)->find((int) $run['id']);

		$this->assertSame(1, $result['processed']);
		$this->assertSame('completed', $run['status']);
		$this->assertSame($campaignQueries, $this->mock->count('campaign'), 'Wznowienie nie powtarza ukończonych kroków.');
	}

	public function testPermanentErrorsFailAndAuthErrorRequiresReauthorization(): void
	{
		[$project, $connection] = $this->connected('auth-fail');
		$sync = $this->make(SyncService::class);
		$this->mock->on('campaign', [401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED', 'message' => 'Request had invalid authentication credentials.']]]);

		$run = $sync->process($sync->enqueue($connection, 'backfill'), time() + 300);
		$this->assertSame('failed', $run['status']);
		$this->assertSame('reauth_required', $this->make(GoogleAdsConnectionRepository::class)->find((int) $connection['id'])['status']);
		$this->assertTrue(str_contains($run['last_error'], 'UNAUTHENTICATED'));
		$this->assertFalse(str_contains($run['last_error'], 'mock-access'), 'Komunikat błędu nie zawiera tokenu.');

		// Ponowienie po ponownej autoryzacji wznawia ten sam przebieg.
		$this->make(GoogleAdsConnectionRepository::class)->update((int) $connection['id'], ['status' => 'active']);
		$this->mock->withDefaultAccount();
		$sync->retry($run);
		$run = $sync->process($this->make(SyncRunRepository::class)->find((int) $run['id']), time() + 300);
		$this->assertSame('completed', $run['status']);
	}

	public function testRetryLimitAndBackoffSchedule(): void
	{
		$this->assertSame(60, SyncService::backoffSeconds(1));
		$this->assertSame(120, SyncService::backoffSeconds(2));
		$this->assertSame(480, SyncService::backoffSeconds(4));
		$this->assertSame(3600, SyncService::backoffSeconds(10), 'Maksymalnie godzina.');
		$this->assertSame(120, SyncService::backoffSeconds(1, 120), 'Opóźnienie podane przez Google ma pierwszeństwo.');

		[$project, $connection] = $this->connected('retry-limit');
		$sync = $this->make(SyncService::class);
		$runs = $this->make(SyncRunRepository::class);
		$this->mock->on('campaign', [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota']]]);
		$run = $sync->enqueue($connection, 'backfill');

		for ($i = 0; $i < 10 && $run['status'] === 'queued'; $i++) {
			$runs->update((int) $run['id'], ['next_attempt_at' => null]);
			$run = $sync->process($runs->find((int) $run['id']), time() + 60);
		}

		$this->assertSame('failed', $run['status'], 'Po wyczerpaniu prób przebieg kończy się błędem widocznym w UI.');
		$this->assertSame(6, (int) $run['attempts']);
	}

	public function testLockPreventsParallelProcessingOfTheSameConnection(): void
	{
		[$project, $connection] = $this->connected('lock');
		$sync = $this->make(SyncService::class);
		$run = $sync->enqueue($connection, 'backfill');

		global $wpdb;
		$other = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
		$lock = $wpdb->prefix . 'pp_sync_' . (int) $connection['id'];
		$this->assertSame('1', (string) $other->query("SELECT GET_LOCK('{$lock}', 0)")->fetch_row()[0]);

		try {
			$result = $sync->process($run, time() + 60);
			$this->assertSame('queued', $result['status'], 'Drugi proces nie wykonuje przebiegu, który jest w toku.');
			$this->assertSame(0, $this->mock->count('campaign'));
		} finally {
			$other->query("SELECT RELEASE_LOCK('{$lock}')");
			$other->close();
		}
	}

	public function testSchedulePlansBackfillThenDailyIncremental(): void
	{
		[$project, $connection] = $this->connected('schedule');
		$sync = $this->make(SyncService::class);
		$runs = $this->make(SyncRunRepository::class);

		$this->assertTrue($sync->schedule() >= 1);
		$run = $runs->openForConnection((int) $connection['id']);
		$this->assertSame('backfill', $run['type']);
		$sync->process($run, time() + 300);

		$this->assertSame(null, $runs->openForConnection((int) $connection['id']));
		$sync->schedule();
		$this->assertNull($runs->openForConnection((int) $connection['id']), 'Świeżo zsynchronizowane konto nie jest planowane ponownie.');

		$runs->update((int) $run['id'], ['finished_at' => gmdate('Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS)]);
		$sync->schedule();
		$next = $runs->openForConnection((int) $connection['id']);
		$this->assertSame('incremental', $next['type']);
		$this->assertSame(30, (new DateRange($next['date_from'], $next['date_to']))->days());
	}

	public function testCurrencyMismatchStopsSync(): void
	{
		[$project, $connection] = $this->connected('currency');
		$this->db->update('projects', ['currency' => 'EUR'], ['id' => $project->id]);

		$run = $this->make(SyncService::class)->process($this->make(SyncService::class)->enqueue($connection, 'backfill'), time() + 60);
		$this->assertSame('failed', $run['status']);
		$this->assertTrue(str_contains($run['last_error'], 'EUR'));
		$this->assertSame(0, $this->count('campaign_metrics', $project->id));
	}

	public function testSyncRunsAreIsolatedPerProject(): void
	{
		[$projectA, $connectionA] = $this->connected('iso-a');
		$owner = $this->user('iso-b');
		$projectB = $this->organizationWithProject($owner, 'Izolacja B')['project'];
		$run = $this->make(SyncService::class)->enqueue($connectionA, 'backfill');

		$this->assertNull($this->make(SyncRunRepository::class)->findInProject($projectB->id, (int) $run['id']));
		$this->assertNull($this->make(GoogleAdsConnectionRepository::class)->currentForProject($projectB->id));
	}

	public function testAggregateSumsDuplicateRowsPerEntityAndDay(): void
	{
		$rows = ApiSyncer::aggregate([
			['search_term_id' => 1, 'date_from' => '2026-10-01', 'clicks' => 2, 'cost_micros' => 100, 'conversions' => '0.5000'],
			['search_term_id' => 1, 'date_from' => '2026-10-01', 'clicks' => 3, 'cost_micros' => 50, 'conversions' => '1.0000'],
			['search_term_id' => 1, 'date_from' => '2026-10-02', 'clicks' => 1, 'cost_micros' => 10, 'conversions' => null],
		], 'search_term_id');

		$this->assertCount(2, $rows);
		$this->assertSame(5, $rows[0]['clicks']);
		$this->assertSame(150, $rows[0]['cost_micros']);
		$this->assertEqualsWithDelta(1.5, (float) $rows[0]['conversions'], 0.0001);
	}

	/**
	 * @return array{0: \App\PennyPincher\Domain\Projects\Project, 1: array}
	 */
	private function connected(string $name): array
	{
		$owner = $this->user($name);
		$project = $this->organizationWithProject($owner, 'Sync ' . $name)['project'];
		$this->mock->withDefaultAccount();

		return [$project, $this->connect($project, $owner)];
	}

	private function importRow(int $projectId, int $campaignId, string $granularity, string $from, string $to, int $cost): array
	{
		return [
			'project_id' => $projectId, 'campaign_id' => $campaignId, 'granularity' => $granularity, 'date_from' => $from, 'date_to' => $to,
			'source' => 'import', 'impressions' => 1, 'clicks' => 1, 'cost_micros' => $cost, 'conversions' => '0', 'import_id' => null,
			'created_at' => $this->db->now(), 'updated_at' => $this->db->now(),
		];
	}
}
