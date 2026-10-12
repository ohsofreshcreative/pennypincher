<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\DataForSeoRepository;
use App\PennyPincher\Repositories\KeywordIdeaRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\DataForSeo\DataForSeoService;
use App\PennyPincher\Services\DemoProjectService;
use Tests\IntegrationTestCase;

/**
 * Integracja DataForSEO na atrapie HTTP (`pre_http_request`) – żadne żądanie nie wychodzi do sieci
 * i żadne płatne zapytanie nie jest wykonywane. Testy NIE potwierdzają działania z prawdziwym API.
 */
class DataForSeoTest extends IntegrationTestCase
{
	private const ENV = ['PP_DATAFORSEO_LOGIN', 'PP_DATAFORSEO_PASSWORD', 'PP_DATAFORSEO_SANDBOX', 'PP_DATAFORSEO_ORGANIZATION_MONTHLY_LIMIT'];

	private const PASSWORD = 'tajne-haslo-api-123';

	/** @var array<int, array{url: string, method: string, headers: array, body: mixed}> */
	private array $requests = [];

	/** @var callable(string $url, array $args): array{0: int, 1: mixed} */
	private $responder;

	private ?\Closure $filter = null;

	private array $previousEnv = [];

	public function setUp(): void
	{
		parent::setUp();

		foreach (self::ENV as $name) {
			if (defined($name)) {
				throw new \Tests\SkipTest("Stała {$name} jest zdefiniowana – testy z atrapą wymagają konfiguracji przez zmienne środowiskowe.");
			}

			$this->previousEnv[$name] = getenv($name);
			putenv($name);
		}

		putenv('PP_DATAFORSEO_LOGIN=api@example.test');
		putenv('PP_DATAFORSEO_PASSWORD=' . self::PASSWORD);

		$this->responder = fn (string $url) => [200, self::keywordsResponse()];
		$this->filter = function ($preempt, $args, $url) {
			$this->requests[] = ['url' => (string) $url, 'method' => $args['method'] ?? 'GET', 'headers' => $args['headers'] ?? [], 'body' => $args['body'] ?? null];

			if (! str_contains((string) $url, 'dataforseo.com')) {
				return new \WP_Error('pp_test', 'Nieoczekiwane żądanie: ' . $url);
			}

			[$status, $body] = ($this->responder)((string) $url, (array) $args);

			return ['headers' => [], 'body' => is_string($body) ? $body : wp_json_encode($body), 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => null];
		};
		add_filter('pre_http_request', $this->filter, 1, 3);
	}

	public function tearDown(): void
	{
		remove_filter('pre_http_request', $this->filter, 1);

		foreach ($this->previousEnv as $name => $value) {
			putenv($value === false ? $name : "{$name}={$value}");
		}

		parent::tearDown();
	}

	public function testPaidRequestRequiresConsentAndLimitAndNothingIsSentBeforeWorker(): void
	{
		[$owner, $project] = $this->project(null);
		$service = $this->make(DataForSeoService::class);
		$period = $this->period($project);

		$plan = $service->plan($project, $period);
		$this->assertTrue($plan['seeds'] !== []);
		$this->assertSame(0.09, $plan['estimated_cost']);
		$this->assertTrue(str_contains((string) $plan['blocked'], 'limit'), (string) $plan['blocked']);

		$this->assertThrows(ValidationFailed::class, fn () => $service->request($project, $period, $owner, false));
		$this->assertContains('Ustaw miesięczny limit', $this->validationError(fn () => $service->request($project, $period, $owner, true)));

		[, $project] = $this->project(1.0, $owner, $project);
		$this->assertContains('Szacowany koszt zmienił się', $this->validationError(fn () => $service->request($project, $period, $owner, true, '0.01')));
		$task = $service->request($project, $period, $owner, true, '0.09');
		$again = $service->request($project, $period, $owner, true);

		$this->assertSame('queued', $task['status']);
		$this->assertSame((int) $task['id'], (int) $again['id'], 'Identyczne zlecenie nie tworzy drugiego płatnego zadania.');
		$this->assertSame($owner, (int) $task['requested_by']);
		$this->assertTrue($task['approved_at'] !== null);
		$this->assertSame([], $this->requests, 'Plan i zlecenie nie wysyłają zapytań – robi to dopiero worker.');
	}

	public function testWorkerFetchesStoresIdeasRecordsCostAndUsesCache(): void
	{
		[$owner, $project] = $this->project(1.0);
		$service = $this->make(DataForSeoService::class);
		$tasks = $this->make(DataForSeoRepository::class);
		$period = $this->period($project);

		$task = $service->request($project, $period, $owner, true);
		$processed = $service->process($tasks->claimNext());

		$this->assertSame('completed', $processed['status']);
		$this->assertEqualsWithDelta(0.075, (float) $processed['cost'], 0.00001, 'Koszt z odpowiedzi API, nie z szacunku.');
		$this->assertSame(2, (int) $processed['result_count']);
		$this->assertCount(1, $this->requests);

		$request = $this->requests[0];
		$this->assertSame('https://api.dataforseo.com/v3/keywords_data/google_ads/keywords_for_keywords/live', $request['url']);
		$this->assertSame('POST', $request['method']);
		$this->assertSame('Basic ' . base64_encode('api@example.test:' . self::PASSWORD), $request['headers']['Authorization']);
		$body = json_decode($request['body'], true);
		$this->assertCount(1, $body, 'Endpoint live: dokładnie jedno zadanie.');
		$this->assertSame(2616, $body[0]['location_code']);
		$this->assertSame('pl', $body[0]['language_code']);

		$ideas = $this->make(KeywordIdeaRepository::class)->forProject($project->id, 2616, 'pl');
		$this->assertSame(['sofa na wymiar', 'sofa tapicerowana'], array_column($ideas, 'keyword'));
		$this->assertSame([1_250_000, null], array_column($ideas, 'cpc_micros'), 'Brak CPC zostaje brakiem danych.');

		// Ten sam zestaw fraz: wynik z pamięci podręcznej, bez zapytania i bez kosztu.
		$plan = $service->plan($project, $period);
		$this->assertTrue($plan['cached']);
		$this->assertSame(0.0, $plan['estimated_cost']);
		$cached = $service->process($tasks->find((int) $service->request($project, $period, $owner, true)['id']));
		$this->assertSame(1, (int) $cached['from_cache']);
		$this->assertSame(0.0, (float) $cached['cost']);
		$this->assertCount(1, $this->requests);

		$usage = $service->usage($project);
		$this->assertEqualsWithDelta(0.075, $usage['project_spent'], 0.00001);

		// Hasło API nigdzie nie jest zapisywane.
		foreach (['dataforseo_tasks', 'dataforseo_cache', 'keyword_ideas', 'projects'] as $table) {
			$dump = wp_json_encode($this->db->select('SELECT * FROM ' . $this->db->table($table)));
			$this->assertFalse(str_contains($dump, self::PASSWORD), "Sekret w tabeli {$table}.");
		}
	}

	public function testInsufficientFundsFailsWithoutRetryAndRateLimitIsRetried(): void
	{
		[$owner, $project] = $this->project(5.0);
		$service = $this->make(DataForSeoService::class);
		$tasks = $this->make(DataForSeoRepository::class);

		$this->responder = fn () => [402, ['status_code' => 40210, 'status_message' => 'You don\'t have enough funds.', 'tasks' => []]];
		$service->request($project, $this->period($project), $owner, true);
		$failed = $service->process($tasks->claimNext());

		$this->assertSame('failed', $failed['status']);
		$this->assertSame('40210', $failed['error_code']);
		$this->assertTrue(str_contains($failed['last_error'], 'Brak środków'));
		$this->assertFalse(str_contains($failed['last_error'], self::PASSWORD));
		$this->assertEqualsWithDelta(0.0, (float) $failed['cost'], 0.0001);

		$this->responder = fn () => [200, ['status_code' => 40202, 'status_message' => 'Rate limit', 'tasks' => []]];
		$this->db->statement('DELETE FROM ' . $this->db->table('dataforseo_tasks') . ' WHERE project_id = ?', [$project->id]);
		$service->request($project, $this->period($project), $owner, true);
		$retry = $service->process($tasks->claimNext());
		$this->assertSame('queued', $retry['status']);
		$this->assertTrue($retry['next_attempt_at'] !== null);
		$this->assertSame(1, $retry['attempts']);
		$this->assertNull($tasks->claimNext(), 'Ponowienie dopiero po czasie oczekiwania.');
	}

	public function testMonthlyLimitAndTimeoutHandling(): void
	{
		[$owner, $project] = $this->project(0.1);
		$service = $this->make(DataForSeoService::class);
		$tasks = $this->make(DataForSeoRepository::class);
		$this->db->insert('dataforseo_tasks', [
			'project_id' => $project->id, 'organization_id' => $project->organizationId, 'endpoint' => 'x', 'payload' => '[]', 'payload_hash' => sha1('old'),
			'status' => 'completed', 'cost' => 0.05, 'created_at' => $this->db->now(), 'updated_at' => $this->db->now(),
		]);

		$this->assertContains('limit projektu', $this->validationError(fn () => $service->request($project, $this->period($project), $owner, true)));

		[, $project] = $this->project(1.0, $owner, $project);
		$service->request($project, $this->period($project), $owner, true);
		$this->responder = fn () => throw new \RuntimeException('nie powinno dojść do HTTP');
		$this->filterTimeout();
		$timeout = $service->process($tasks->claimNext());
		$this->assertSame('queued', $timeout['status'], 'Przekroczenie czasu: ponowienie później.');
		$this->assertSame('transient', $timeout['error_code']);
	}

	public function testConnectionTestUsesFreeEndpointAndHidesSecrets(): void
	{
		$this->responder = fn () => [200, ['status_code' => 20000, 'cost' => 0, 'tasks' => [['status_code' => 20000, 'result' => [['login' => 'api@example.test', 'money' => ['balance' => 12.5]]]]]]];
		$result = $this->make(DataForSeoService::class)->testConnection();
		$this->assertTrue($result['ok']);
		$this->assertSame(12.5, $result['balance']);
		$this->assertSame('GET', $this->requests[0]['method']);
		$this->assertTrue(str_ends_with($this->requests[0]['url'], '/v3/appendix/user_data'));

		$this->responder = fn () => [401, ['status_code' => 40100, 'status_message' => 'Not authorized', 'tasks' => []]];
		$failed = $this->make(DataForSeoService::class)->testConnection();
		$this->assertFalse($failed['ok']);
		$this->assertFalse(str_contains($failed['message'], self::PASSWORD));
		$this->assertTrue(str_contains($failed['message'], 'odrzuciło dane logowania'));
	}

	public function testWithoutCredentialsNothingIsSent(): void
	{
		putenv('PP_DATAFORSEO_PASSWORD');
		[$owner, $project] = $this->project(5.0);
		$service = $this->make(DataForSeoService::class);

		$this->assertFalse($service->isConfigured());
		$this->assertThrows(ValidationFailed::class, fn () => $service->request($project, $this->period($project), $owner, true));
		$this->assertSame(['processed' => 0, 'requeued' => 0], $service->work(1));
		$this->assertFalse($service->testConnection()['ok']);
		$this->assertSame([], $this->requests);
	}

	public function testSeedEditingCustomSetLimitWarningsAndLastUsed(): void
	{
		[$owner, $project] = $this->project(1.0);
		$service = $this->make(DataForSeoService::class);
		$period = $this->period($project);
		$auto = $service->plan($project, $period);

		$this->assertFalse($auto['custom']);
		$this->assertTrue(count($auto['seeds']) <= 20);
		$this->assertSame($auto['auto_seeds'], array_map(fn ($s) => array_diff_key($s, ['warning' => 1]), $auto['seeds']));

		$tooMany = array_map(fn ($i) => "sofa model {$i}", range(1, 21));
		$this->assertContains('Maksymalnie 20 fraz', $this->validationError(fn () => $service->saveSeeds($project, $tooMany, [], $owner)));
		$this->assertContains('co najmniej jedną', $this->validationError(fn () => $service->saveSeeds($project, ['', '  '], [], $owner)));

		$autoPhrase = $auto['seeds'][0]['phrase'];
		$saved = $service->saveSeeds($project, [$autoPhrase, '"Online Seller"', 'online seller', '<b>narożnik</b>'], array_column($auto['seeds'], 'phrase'), $owner);
		$this->assertSame([$autoPhrase, 'online seller', 'narożnik'], array_column($saved, 'phrase'), 'Bez duplikatów i znaczników.');
		$this->assertSame(['auto', 'manual', 'manual'], array_column($saved, 'source'));

		$plan = $service->plan($project, $period);
		$this->assertTrue($plan['custom']);
		$this->assertSame(3, count($plan['seeds']));
		$manual = array_column($plan['seeds'], 'warning', 'phrase');
		$this->assertContains('Możliwa niska trafność', (string) $manual['online seller'], 'Ręczna fraza nie jest odrzucana – dostaje ostrzeżenie.');
		$this->assertSame(0.09, $plan['estimated_cost']);

		$service->request($project, $period, $owner, true);
		$service->resetSeeds($project);
		$reset = $service->plan($project, $period);
		$this->assertFalse($reset['custom']);
		$this->assertSame(['online seller', 'narożnik'], array_values(array_diff(array_column($reset['last_used'], 'phrase'), [$autoPhrase])), 'Ostatnio użyty zestaw jest zapamiętany.');

		$service->restoreLastUsed($project, $owner);
		$this->assertSame(array_column($saved, 'phrase'), array_column($service->plan($project, $period)['seeds'], 'phrase'));
		$this->assertSame([], $this->requests, 'Edycja fraz nie wysyła zapytań.');
	}

	public function testOrganizationLimitCountsReservationsOfOtherProjects(): void
	{
		putenv('PP_DATAFORSEO_ORGANIZATION_MONTHLY_LIMIT=0.1');
		[$owner, $first] = $this->project(1.0);
		$secondId = $this->make(ProjectRepository::class)->create($first->organizationId, [
			'name' => 'Drugi projekt', 'platform' => 'google_ads', 'currency' => 'PLN', 'timezone' => 'Europe/Warsaw', 'status' => 'active',
		]);
		[, $second] = $this->project(1.0, $owner, $this->make(ProjectRepository::class)->findForUser($owner, $secondId));
		$service = $this->make(DataForSeoService::class);

		$service->request($first, $this->period($first), $owner, true);
		$usage = $service->usage($second);
		$this->assertEqualsWithDelta(0.09, $usage['organization_reserved'], 0.00001, 'Rezerwacja innego projektu liczy się do limitu organizacji.');
		$this->assertContains('limit organizacji', $this->validationError(fn () => $service->request($second, $this->period($second), $owner, true)));
		$this->assertSame([], $this->requests);
	}

	// --- Pomocnicze ---------------------------------------------------------------------------

	/**
	 * @return array{0: int, 1: Project}
	 */
	private function project(?float $limit, ?int $owner = null, ?Project $project = null): array
	{
		$owner ??= $this->user('dfs');
		$projectId = $project?->id ?? $this->make(DemoProjectService::class)->create($owner);
		$this->make(ProjectRepository::class)->update($projectId, [
			'dataforseo_monthly_limit' => $limit,
			'business_context' => wp_json_encode(['priority_services' => "sofy na wymiar\nnarożniki"]),
		]);

		return [$owner, $this->make(ProjectRepository::class)->findForUser($owner, $projectId)];
	}

	private function validationError(callable $callback): string
	{
		try {
			$callback();
		} catch (ValidationFailed $e) {
			return (string) (array_values($e->errors)[0] ?? '');
		}

		throw new \Tests\AssertionFailed('Oczekiwano ValidationFailed.');
	}

	private function period(Project $project): AnalysisPeriod
	{
		return AnalysisPeriod::fromPreset('last_365', $project->timezone());
	}

	private function filterTimeout(): void
	{
		remove_filter('pre_http_request', $this->filter, 1);
		$this->filter = function ($preempt, $args, $url) {
			$this->requests[] = ['url' => (string) $url, 'method' => $args['method'] ?? 'GET', 'headers' => [], 'body' => null];

			return new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 120000 milliseconds');
		};
		add_filter('pre_http_request', $this->filter, 1, 3);
	}

	private static function keywordsResponse(): array
	{
		return [
			'version' => '0.1', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.075, 'tasks_count' => 1, 'tasks_error' => 0,
			'tasks' => [[
				'id' => 'x', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.075, 'result_count' => 2,
				'result' => [
					['keyword' => 'sofa na wymiar', 'search_volume' => 880, 'cpc' => 1.25, 'competition' => 'MEDIUM', 'competition_index' => 50, 'low_top_of_page_bid' => 0.4, 'high_top_of_page_bid' => 2.0],
					['keyword' => 'sofa tapicerowana', 'search_volume' => 320, 'cpc' => null, 'competition' => null],
				],
			]],
		];
	}
}
