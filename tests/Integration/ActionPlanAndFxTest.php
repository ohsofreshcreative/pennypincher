<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Http\Controllers\ActionPlanController;
use App\PennyPincher\Repositories\ActionItemRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\ActionPlan\ActionPlanService;
use App\PennyPincher\Services\Analysis\AnalysisService;
use App\PennyPincher\Services\DemoProjectService;
use App\PennyPincher\Services\Fx\ExchangeRateService;
use Illuminate\Http\Request;
use Tests\IntegrationTestCase;

/**
 * Action Plan na pełnym potoku analizy projektu demo oraz kursy walut NBP (atrapa HTTP – bez połączeń sieciowych).
 */
class ActionPlanAndFxTest extends IntegrationTestCase
{
	private array $requests = [];

	private ?\Closure $http = null;

	public function setUp(): void
	{
		parent::setUp();
		$this->requests = [];
		$this->http = function ($preempt, $args, $url) {
			$this->requests[] = (string) $url;

			if (preg_match('#^https://api\.nbp\.pl/api/exchangerates/rates/a/(usd|eur)/last/\d+/\?format=json$#', (string) $url, $m)) {
				$mid = $m[1] === 'usd' ? [3.95, 3.98] : [4.28, 4.30];

				return ['headers' => [], 'body' => wp_json_encode(['table' => 'A', 'code' => strtoupper($m[1]), 'Rates' => [
					['no' => '196/A/NBP/2026', 'effectiveDate' => gmdate('Y-m-d', strtotime('-2 days')), 'mid' => $mid[0]],
					['no' => '197/A/NBP/2026', 'effectiveDate' => gmdate('Y-m-d', strtotime('-1 day')), 'mid' => $mid[1]],
				]]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
			}

			if (str_contains((string) $url, 'api.nbp.pl')) {
				return ['headers' => [], 'body' => '404 NotFound', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => [], 'filename' => null];
			}

			return new \WP_Error('pp_test', 'Nieoczekiwane żądanie: ' . $url);
		};
		add_filter('pre_http_request', $this->http, 1, 3);
	}

	public function tearDown(): void
	{
		remove_filter('pre_http_request', $this->http, 1);
		parent::tearDown();
	}

	// --- Action Plan --------------------------------------------------------------------------

	public function testActionPlanIsBuiltFromAnalysisAndKeepsUserDecisions(): void
	{
		[$owner, $project] = $this->demo();
		$analysis = $this->make(AnalysisService::class);
		$period = AnalysisPeriod::fromPreset('last_365', $project->timezone());
		$items = $this->make(ActionItemRepository::class);
		$service = $this->make(ActionPlanService::class);

		$analysis->run($project, $period, 'manual', $owner);
		$all = $items->byFingerprint($project->id);
		$this->assertTrue($all !== [], 'Analiza tworzy plan działań.');
		$recommendations = count($this->make(RecommendationRepository::class)->byFingerprint($project->id));
		$this->assertTrue(count($all) <= $recommendations, 'Działania grupują rekomendacje, nie dublują ich.');

		foreach ($all as $item) {
			$this->assertTrue(in_array($item['status'], ['todo', 'blocked'], true), 'Nowe działania nigdy nie są oznaczane jako wykonane.');
			$this->assertTrue($item['recommendation_ids'] !== [] || $item['category'] === 'landing_page', 'Działanie zachowuje powiązanie z rekomendacjami.');
		}

		$open = array_values(array_filter($all, fn ($i) => $i['status'] === 'todo'))[0];
		$service->updateStatus($project, (int) $open['id'], 'in_progress', $owner, 'Zaczynamy');
		$service->updateNote($project, (int) $open['id'], '<script>x</script>Notatka zespołu', $owner);

		$analysis->run($project, $period, 'manual', $owner);
		$again = $items->byFingerprint($project->id);
		$this->assertSame(count($all), count($again), 'Ponowna analiza nie tworzy duplikatów.');
		$this->assertSame([], array_diff(array_keys($all), array_keys($again)));
		$this->assertSame('in_progress', $again[$open['fingerprint']]['status'], 'Status użytkownika przetrwał ponowną analizę.');
		$this->assertSame('Notatka zespołu', $again[$open['fingerprint']]['note']);
		$this->assertSame(0, count(array_filter($again, fn ($i) => $i['status'] === 'done')), 'System nie oznacza działań jako wykonane.');

		$events = array_column($items->events($project->id, (int) $open['id']), 'event');
		$this->assertTrue(in_array('created', $events, true) && in_array('status', $events, true) && in_array('note', $events, true));

		$this->assertThrows(ValidationFailed::class, fn () => $service->updateStatus($project, (int) $open['id'], 'archived', $owner));
		$this->assertThrows(ValidationFailed::class, fn () => $service->updateNote($project, (int) $open['id'], str_repeat('a', 2001), $owner));
	}

	public function testDependenciesBlockAndUnblockAutomatically(): void
	{
		[$owner, $project] = $this->demo();
		$this->make(AnalysisService::class)->run($project, AnalysisPeriod::fromPreset('last_365', $project->timezone()), 'manual', $owner);
		$service = $this->make(ActionPlanService::class);
		$items = $this->make(ActionItemRepository::class);
		// Problem z pomiarem (CT001) – działania budżetowe i nowe słowa kluczowe muszą na niego poczekać.
		$now = $this->db->now();
		$this->db->insert('recommendations', [
			'project_id' => $project->id, 'fingerprint' => sha1('ct001-test'), 'source' => 'audit', 'type' => 'audit_ct001', 'title' => 'Brak konwersji głównych',
			'description' => 'Opis', 'rationale' => '', 'evidence' => null, 'action' => 'Skonfiguruj konwersje', 'priority' => 'high', 'priority_score' => 90,
			'confidence' => 'high', 'date_from' => '2025-10-12', 'date_to' => '2026-10-11', 'status' => 'new', 'is_obsolete' => 0,
			'first_seen_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now,
		]);
		$service->refresh($project);
		$all = $items->byFingerprint($project->id);
		$blocked = array_values(array_filter($all, fn ($i) => $i['status'] === 'blocked'));

		$this->assertTrue($blocked !== [], 'Dane demo mają działania czekające na pomiar: ' . wp_json_encode(array_column($all, 'status', 'title')));

		$item = $blocked[0];
		$this->assertTrue($item['depends_on'] !== []);

		foreach ($item['depends_on'] as $dep) {
			$service->updateStatus($project, (int) $all[$dep]['id'], 'done', $owner);
		}

		$this->assertSame('todo', $items->find($project->id, (int) $item['id'])['status'], 'Po wykonaniu zależności działanie jest odblokowane.');

		// Status ustawiony ręcznie nie jest nadpisywany przez automatyczną blokadę.
		$service->updateStatus($project, (int) $item['id'], 'blocked', $owner, 'Czekamy na klienta');
		$service->syncBlocked($project);
		$this->assertSame('blocked', $items->find($project->id, (int) $item['id'])['status']);
	}

	public function testActionPlanIsolation(): void
	{
		[$owner, $project] = $this->demo();
		$this->make(AnalysisService::class)->run($project, AnalysisPeriod::fromPreset('last_365', $project->timezone()), 'manual', $owner);
		$id = (int) array_values($this->make(ActionItemRepository::class)->byFingerprint($project->id))[0]['id'];
		[$bob, $other] = $this->demo();

		$this->assertThrows(ValidationFailed::class, fn () => $this->make(ActionPlanService::class)->updateStatus($other, $id, 'done', $bob));
		wp_set_current_user($bob);
		$this->assertThrows(NotFound::class, fn () => $this->make(ActionPlanController::class)->index($project->id, Request::create('/')));
		wp_set_current_user($owner);
		$this->assertSame(200, $this->make(ActionPlanController::class)->index($project->id, Request::create('/', 'GET', ['status' => 'blocked']))->getStatusCode());
	}

	// --- Kursy walut --------------------------------------------------------------------------

	public function testNbpRatesAreParsedStoredAndUsedForConversion(): void
	{
		$fx = $this->make(ExchangeRateService::class);
		$this->assertNull($fx->rate('USD', 'PLN'), 'Bez kursu – brak porównania (nie zgadujemy).');

		$saved = $fx->refresh(['USD', 'EUR', 'PLN', 'GBP']);
		$this->assertSame(4, $saved, 'Dwa notowania USD i EUR; PLN pomijany; GBP – 404 bez błędu.');

		$usd = $fx->convert(2_000_000, 'USD', 'PLN');
		$this->assertSame(7_960_000, $usd['micros'], 'Najnowszy kurs (3,98).');
		$this->assertSame(gmdate('Y-m-d', strtotime('-1 day')), $usd['rate_date']);
		$this->assertContains('NBP', $usd['source']);

		$back = $fx->convert(3_980_000, 'PLN', 'USD');
		$this->assertSame(1_000_000, $back['micros']);
		$cross = $fx->rate('EUR', 'USD');
		$this->assertEqualsWithDelta(4.30 / 3.98, $cross['rate'], 0.0001);
		$this->assertContains('krzyżowy', $cross['source']);
		$this->assertNull($fx->convert(1_000_000, 'GBP', 'PLN'), 'Brak kursu GBP.');
		$this->assertSame(1_000_000, $fx->convert(1_000_000, 'PLN', 'PLN')['micros']);

		// Kurs starszy niż 10 dni nie jest używany.
		$this->assertNull($fx->rate('USD', 'PLN', gmdate('Y-m-d', strtotime('+30 days'))));
		$this->assertFalse(in_array(false, array_map(fn ($u) => str_starts_with($u, 'https://api.nbp.pl/'), $this->requests), true), 'Tylko publiczne API NBP.');
	}

	public function testNbpErrorsAreReportedWithoutBreakingTheWorker(): void
	{
		remove_filter('pre_http_request', $this->http, 1);
		$this->http = fn () => new \WP_Error('http_request_failed', 'cURL error 28');
		add_filter('pre_http_request', $this->http, 1, 3);

		$this->assertThrows(\RuntimeException::class, fn () => $this->make(ExchangeRateService::class)->refresh(['USD']), 'Brak połączenia z API NBP');
		delete_option('pp_fx_last_attempt');
		$this->db->insert('keyword_ideas', $this->idea());
		$this->assertSame(0, $this->make(ExchangeRateService::class)->refreshIfDue(), 'Błąd jest logowany, worker działa dalej.');
	}

	// --- Pomocnicze ---------------------------------------------------------------------------

	/**
	 * @return array{0: int, 1: Project}
	 */
	private function demo(): array
	{
		$owner = $this->user('actions');
		$id = $this->make(DemoProjectService::class)->create($owner);

		return [$owner, $this->make(ProjectRepository::class)->findForUser($owner, $id)];
	}

	private function idea(): array
	{
		$owner = $this->user('fx');
		$project = $this->organizationWithProject($owner, 'FX')['project'];

		return [
			'project_id' => $project->id, 'keyword' => 'sofa', 'normalized' => 'sofa', 'location_code' => 2616, 'language_code' => 'pl',
			'cpc_micros' => 1_000_000, 'cpc_currency' => 'USD', 'source' => 'dataforseo', 'fetched_at' => $this->db->now(),
		];
	}
}
