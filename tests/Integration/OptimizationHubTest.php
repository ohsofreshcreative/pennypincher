<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Audit\RuleRegistry;
use App\PennyPincher\Http\Middleware\VerifyNonce;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\AnalysisService;
use App\PennyPincher\Services\DemoProjectService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use App\PennyPincher\Support\Navigation;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;
use Tests\IntegrationTestCase;

/**
 * Optymalizacja – wspólny punkt wejścia (pp2_optimization): okres, pełna analiza, wyniki zgodne z modułami źródłowymi.
 */
class OptimizationHubTest extends IntegrationTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::OPTIMIZATION);
		remove_all_filters('pennypincher/feature/' . Feature::NAVIGATION);
		parent::tearDown();
	}

	private function flag(bool $on): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::OPTIMIZATION);
		if ($on) {
			add_filter('pennypincher/feature/' . Feature::OPTIMIZATION, '__return_true');
		}
	}

	private function request(string $url, string $method = 'GET', array $data = []): \Symfony\Component\HttpFoundation\Response
	{
		$_SERVER['REQUEST_METHOD'] = $method;

		return Application::getInstance()->make(Kernel::class)->handle(Request::create($url, $method, $data));
	}

	private function body(string $url): string
	{
		$response = $this->request($url);
		$this->assertSame(200, $response->getStatusCode(), $url);

		return (string) $response->getContent();
	}

	private function demo(bool $analysed): array
	{
		$owner = $this->user('opt_owner');
		$id = $this->make(DemoProjectService::class)->create($owner);
		$project = $this->make(ProjectRepository::class)->findForUser($owner, $id);
		wp_set_current_user($owner);
		$this->make(AccessService::class)->rememberProject($project);
		$period = AnalysisPeriod::fromPreset('last_365', $project->timezone());
		$result = $analysed ? $this->make(AnalysisService::class)->run($project, $period, 'manual', $owner) : null;

		return [$project, $period, $result];
	}

	public function testWithoutFlagRedirectsLikeTheOldAlias(): void
	{
		[$project] = $this->demo(false);
		$this->flag(false);

		$response = $this->request(AppUrl::project($project->id, 'optimization', ['period' => 'last_90', 'campaign' => 5]));
		$this->assertTrue($response->isRedirect(AppUrl::project($project->id, 'audits', ['period' => 'last_90', 'campaign' => 5])));
		$this->assertSame('audits', Navigation::resolve('optimization'));
		$this->assertNull(Navigation::groupOf('optimization'));
	}

	public function testEmptyPeriodShowsSingleStep(): void
	{
		[$project] = $this->demo(false);
		$this->flag(true);

		$body = $this->body(AppUrl::project($project->id, 'optimization', ['period' => 'last_365']));
		$this->assertContains('Brak analizy dla okresu', $body);
		$this->assertSame(1, substr_count($body, 'Brak analizy'), 'Jeden komunikat zamiast osobnego w każdym module.');
		$this->assertFalse(str_contains($body, 'data-module='));
		$this->assertContains('name="return" value="optimization"', $body);
	}

	public function testResultsMatchSourceModules(): void
	{
		[$project, $period, $result] = $this->demo(true);
		$this->assertSame([], $result['errors']);
		$this->flag(true);
		$from = $period->range->fromString();
		$to = $period->range->toString();

		$body = $this->body(AppUrl::project($project->id, 'optimization', ['period' => 'last_365']));

		foreach (['audit', 'budget-leaks', 'keywords', 'recommendations'] as $module) {
			$this->assertContains('data-module="' . $module . '"', $body);
		}

		$audit = $this->make(AuditRepository::class)->latestRunForPeriod($project->id, $from, $to);
		$this->assertSame((int) $result['audit_run_id'], (int) $audit['id']);
		$this->assertContains(esc_url(AppUrl::project($project->id, 'audits/' . $audit['id'])), $body);
		$findings = array_filter($this->make(AuditRepository::class)->findings((int) $audit['id']), fn ($f) => $f['status'] !== 'dismissed');
		$leaks = array_filter($this->make(AnalysisRepository::class)->leaks((int) $result['leak_run_id']), fn ($l) => $l['status'] !== 'dismissed');
		$recs = $this->make(RecommendationRepository::class)->paginate($project->id, ['from' => $from, 'to' => $to], 1, 10);

		$this->assertSame(min(5, count($findings)), substr_count($body, 'data-fact="finding"'));
		$this->assertSame(min(5, count($leaks)), substr_count($body, 'data-fact="leak"'));
		$this->assertSame(count($recs->items), substr_count($body, 'data-rec'));
		$this->assertTrue(count($recs->items) > 0, 'Demo powinno mieć rekomendacje.');
		$this->assertContains(count(RuleRegistry::RULES) . ' reguł', $body);

		// Zawężenie do kampanii: tylko wycieki i rekomendacje tej kampanii.
		$campaigns = array_unique(array_filter(array_map(fn ($l) => $l['campaign_id'] !== null ? (int) $l['campaign_id'] : null, $leaks)));
		$this->assertTrue($campaigns !== [], 'Demo powinno mieć wycieki przypisane do kampanii.');
		$campaign = reset($campaigns);
		$scoped = $this->body(AppUrl::project($project->id, 'optimization', ['period' => 'last_365', 'campaign' => $campaign]));
		$own = array_filter($leaks, fn ($l) => (int) $l['campaign_id'] === $campaign);
		$ownRecs = $this->make(RecommendationRepository::class)->paginate($project->id, ['from' => $from, 'to' => $to, 'campaign_id' => $campaign], 1, 10);
		$this->assertSame(min(5, count($own)), substr_count($scoped, 'data-fact="leak"'));
		$this->assertSame(count($ownRecs->items), substr_count($scoped, 'data-rec'));
		$this->assertContains('<option value="' . $campaign . '" selected', $scoped);

		// Kampania spoza projektu jest ignorowana.
		$this->assertContains('<option value="" >Całe konto', str_replace('<option value="">', '<option value="" >', $this->body(AppUrl::project($project->id, 'optimization', ['period' => 'last_365', 'campaign' => 999999]))));
	}

	public function testRunFromHubReturnsToHubWithPeriod(): void
	{
		[$project] = $this->demo(false);
		$this->flag(true);

		$response = $this->request(AppUrl::project($project->id, 'analysis'), 'POST', ['period' => 'last_365', 'return' => 'optimization', VerifyNonce::FIELD => wp_create_nonce(VerifyNonce::ACTION)]);
		$this->assertTrue($response->isRedirect(AppUrl::project($project->id, 'optimization', ['period' => 'last_365'])));
		$this->assertContains('data-module="audit"', $this->body(AppUrl::project($project->id, 'optimization', ['period' => 'last_365'])));
	}

	public function testNavigationAndIsolation(): void
	{
		[$project] = $this->demo(false);
		$this->flag(true);
		add_filter('pennypincher/feature/' . Feature::NAVIGATION, '__return_true');

		$this->assertSame('optimization', Navigation::groupOf('optimization'));
		$this->assertSame('optimization', Navigation::resolve('optimization'));
		$this->assertSame('optimization', array_key_first(Navigation::groups()['optimization']['items']));
		$this->assertSame(Navigation::GROUPS['campaigns'], Navigation::groups()['campaigns']);
		$body = $this->body(AppUrl::project($project->id, 'audits'));
		$this->assertContains('href="' . esc_url(AppUrl::project($project->id, 'optimization')) . '"', $body);

		$other = $this->user('opt_other');
		$this->organizationWithProject($other, 'Inny');
		wp_set_current_user($other);
		$this->assertSame(404, $this->request(AppUrl::project($project->id, 'optimization'))->getStatusCode());
	}
}
