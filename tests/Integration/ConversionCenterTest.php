<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Http\Controllers\ConversionsController;
use App\PennyPincher\Services\Analysis\ConversionBreakdown;
use App\PennyPincher\Services\Changes\ConversionManagementService;
use App\PennyPincher\Services\Conversions\ConversionCenterService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use App\PennyPincher\Support\Navigation;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Centrum konwersji (pp2_conversions): Konwersje + Conversion Management w jednym widoku – te same liczby, te same
 * operacje i uprawnienia, stare adresy bez zmian.
 */
class ConversionCenterTest extends ChangeCenterTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::CONVERSIONS);
		parent::tearDown();
	}

	private function flag(bool $on): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::CONVERSIONS);
		if ($on) {
			add_filter('pennypincher/feature/' . Feature::CONVERSIONS, '__return_true');
		}
	}

	private function get(string $url, ?int $user = null): \Symfony\Component\HttpFoundation\Response
	{
		wp_set_current_user($user ?? $this->owner);
		$_SERVER['REQUEST_METHOD'] = 'GET';

		return Application::getInstance()->make(Kernel::class)->handle(Request::create($url, 'GET'));
	}

	private function body(string $url, ?int $user = null): string
	{
		$response = $this->get($url, $user);
		$this->assertSame(200, $response->getStatusCode(), $url);

		return (string) $response->getContent();
	}

	/** Fragment HTML od znacznika do końca najbliższej sekcji/formularza (bez nonce i pola powrotu). */
	private function fragment(string $html, string $marker, string $end = '</section>'): string
	{
		$start = strpos($html, $marker);
		$this->assertTrue($start !== false, 'Brak fragmentu: ' . $marker);
		$part = substr($html, $start, strpos($html, $end, $start) - $start);
		$part = preg_replace('/<input type="hidden" name="(_wpnonce|pp_nonce|return)"[^>]*>|<input type="hidden" name="_wp_http_referer"[^>]*>/', '', $part);

		return preg_replace('/\s+/', ' ', $part);
	}

	private function addMetrics(): void
	{
		$now = $this->db->now();
		$import = $this->db->insert('imports', [
			'project_id' => $this->project->id, 'user_id' => $this->owner, 'report_type' => 'conversions', 'status' => 'completed', 'original_filename' => 'konwersje.csv',
			'file_format' => 'csv', 'file_hash' => hash('sha256', microtime()), 'granularity' => 'day', 'date_from' => '2026-05-10', 'date_to' => '2026-05-10', 'created_at' => $now, 'updated_at' => $now,
		]);
		$campaign = $this->campaignId();

		foreach (['901' => [4.0, 3.0], '902' => [2.0, 2.0]] as $external => [$account, $inCampaign]) {
			$action = $this->conversionId($external);
			foreach ([0 => $account, $campaign => $inCampaign] as $scope => $value) {
				$this->db->insert('conversion_metrics', [
					'project_id' => $this->project->id, 'conversion_action_id' => $action, 'campaign_scope' => $scope, 'granularity' => 'day',
					'date_from' => '2026-05-10', 'date_to' => '2026-05-10', 'conversions' => $value, 'all_conversions' => $value, 'import_id' => $import,
					'source' => 'import', 'created_at' => $now, 'updated_at' => $now,
				]);
			}
		}
	}

	public function testResultsAndEventsMatchExistingModules(): void
	{
		$this->account('cc-parity');
		$this->addMetrics();
		$this->make(ConversionManagementService::class)->readFromGoogle($this->project);
		$this->flag(true);
		$query = ['period' => 'custom', 'from' => '2026-05-01', 'to' => '2026-05-31'];

		// Wyniki: te same liczby co ConversionBreakdown i stara strona Konwersje; kampanie osobno, bez sumowania z kontem.
		$period = AnalysisPeriod::custom('2026-05-01', '2026-05-31', $this->project->timezone());
		$breakdown = $this->make(ConversionBreakdown::class)->forPeriod($this->project->id, $period->range);
		$results = $this->make(ConversionCenterService::class)->results($this->project, $period);
		$this->assertSame($breakdown['account'], $results['breakdown']['account']);
		$this->assertTrue($breakdown['account']['google'] !== null, 'Dane konta w okresie.');
		$this->assertSame('api', $results['resolved']->source(), 'Dni z synchronizacji API mają pierwszeństwo przed importem.');
		$this->assertCount(1, $results['campaigns']);
		$this->assertSame($breakdown['campaigns'][$this->campaignId()]['google'], $results['campaigns'][0]['google']);

		$old = $this->body(AppUrl::project($this->project->id, 'conversions', $query));
		$new = $this->body(AppUrl::project($this->project->id, 'conversions/center', ['tab' => 'results'] + $query));
		$this->assertSame($this->fragment($old, 'aria-labelledby="pp-conv-summary"'), $this->fragment($new, 'aria-labelledby="pp-conv-summary"'), 'Podsumowanie identyczne.');
		$this->assertContains('Pokrycie zakresu', $new);
		$this->assertContains('title="Źródło danych">Google Ads API</span>', $new, 'Oznaczone źródło danych.');
		$this->assertContains('data-campaign="' . $this->campaignId() . '"', $new);

		// Cele i zdarzenia: klasyfikacja i tabela działań jak w Konwersjach i Conversion Management.
		$events = $this->body(AppUrl::project($this->project->id, 'conversions/center', ['tab' => 'events'] + $query));
		$cm = $this->body(AppUrl::project($this->project->id, 'conversion-management'));
		$this->assertSame($this->fragment($cm, 'aria-label="Działania konwersji"'), $this->fragment($events, 'aria-label="Działania konwersji"'), 'Tabela działań identyczna.');
		$this->assertSame($this->fragment($old, '<table class="pp-table">', '</table>'), $this->fragment($events, '<table class="pp-table">', '</table>'), 'Klasyfikacja identyczna.');
		$this->assertContains('name="return" value="center:events"', $events);
		$this->assertContains('Modele atrybucji w Google Ads', $events);

		// Diagnostyka: Health Check i cele jak w Conversion Management.
		$diag = $this->body(AppUrl::project($this->project->id, 'conversions/center', ['tab' => 'diagnostics']));
		$this->assertSame($this->fragment($cm, 'aria-labelledby="health"'), $this->fragment($diag, 'aria-labelledby="health"'));
		$this->assertSame($this->fragment($cm, 'aria-labelledby="goals"'), $this->fragment($diag, 'aria-labelledby="goals"'));
	}

	public function testOperationsPermissionsHistoryAndRedirects(): void
	{
		$this->account('cc-ops');
		$this->flag(true);
		$action = $this->conversionId('902');

		// Klasyfikacja z Centrum: ta sama akcja, powrót do zakładki.
		$controller = $this->make(ConversionsController::class);
		wp_set_current_user($this->owner);
		$request = Request::create('/', 'POST', ['class' => [$action => 'lead'], 'goal' => [$action => '1'], 'return' => 'center:events']);
		app()->instance('request', $request);
		$response = $controller->update($this->project->id, $request);
		$this->assertTrue($response->isRedirect(AppUrl::project($this->project->id, 'conversions/center', ['tab' => 'events'])));

		// Bez pola powrotu albo z obcym adresem – dotychczasowa strona.
		$request = Request::create('/', 'POST', ['class' => [$action => 'lead'], 'return' => 'https://evil.test']);
		app()->instance('request', $request);
		$this->assertTrue($controller->update($this->project->id, $request)->isRedirect(AppUrl::project($this->project->id, 'conversions')));

		// Zmiana konfiguracji Google Ads = szkic w Change Center (nic nie wysłano) → widoczna w Historii.
		$this->make(ConversionManagementService::class)->readFromGoogle($this->project);
		$setId = $this->proposals()->conversionUpdate($this->project, $this->owner, $action, ['counting_type' => 'ONE_PER_CLICK']);
		$this->assertSame([], array_filter($this->fake->mutations, fn ($m) => ! $m['validate_only']));
		$history = $this->make(ConversionCenterService::class)->history($this->project);
		$this->assertSame([$setId], array_map('intval', array_column($history['sets'], 'id')));
		$this->assertSame([$action], array_map('intval', array_column($history['classified'], 'id')));
		$page = $this->body(AppUrl::project($this->project->id, 'conversions/center', ['tab' => 'history']));
		$this->assertContains('data-history-set="' . $setId . '"', $page);
		$this->assertContains('data-history-class="' . $action . '"', $page);

		// Uprawnienia bez zmian: członek nie zmienia klasyfikacji (UpdateProject), szkice zmian – jak w Conversion Management.
		[$viewer, $viewerProject] = $this->member('cc-member', Role::Member);
		$events = $this->body(AppUrl::project($this->project->id, 'conversions/center', ['tab' => 'events']), $viewer);
		$cm = $this->body(AppUrl::project($this->project->id, 'conversion-management'), $viewer);
		$this->assertContains('Klasyfikację może zmieniać właściciel lub administrator organizacji.', $events);
		$diag = $this->body(AppUrl::project($this->project->id, 'conversions/center', ['tab' => 'diagnostics']), $viewer);
		$this->assertSame(substr_count($cm, 'Przygotuj zmianę'), substr_count($events, 'Przygotuj zmianę') + substr_count($diag, 'Przygotuj zmianę'), 'Te same akcje przygotowania zmian co w Conversion Management (zdarzenia + diagnostyka).');
		$this->assertSame(substr_count($cm, '/goal"'), substr_count($diag, '/goal"'), 'Przełączniki celów jak w Conversion Management.');
		wp_set_current_user($viewer);
		$this->assertThrows(AccessDenied::class, fn () => $controller->update($viewerProject->id, Request::create('/', 'POST', ['class' => [$action => 'micro']])));
	}

	public function testFlagNavigationAndIsolation(): void
	{
		$this->account('cc-flag');

		$this->flag(false);
		$response = $this->get(AppUrl::project($this->project->id, 'conversions/center', ['tab' => 'events', 'period' => 'last_7']));
		$this->assertTrue($response->isRedirect(AppUrl::project($this->project->id, 'conversions', ['period' => 'last_7'])), 'Bez flagi: stara strona z zachowanym okresem.');
		$this->assertSame(Navigation::GROUPS['conversions'], Navigation::groups()['conversions']);
		$request = Request::create('/', 'POST', ['return' => 'center:events']);
		app()->instance('request', $request);
		$this->assertSame(AppUrl::project($this->project->id, 'conversion-management'), ConversionCenterService::back($this->project->id, 'conversion-management'), 'Bez flagi powrót do starej strony.');

		$this->flag(true);
		$this->assertSame('conversion-center', array_key_first(Navigation::groups()['conversions']['items']));
		$this->assertSame('conversions', Navigation::groupOf('conversion-center'));

		foreach (array_keys(ConversionCenterService::TABS) as $tab) {
			$html = $this->body(AppUrl::project($this->project->id, 'conversions/center', ['tab' => $tab]));
			$this->assertContains('data-tab="' . $tab . '"', $html);
		}

		$this->assertSame(200, $this->get(AppUrl::project($this->project->id, 'conversions'))->getStatusCode(), 'Stare adresy działają z flagą.');
		$this->assertSame(200, $this->get(AppUrl::project($this->project->id, 'conversion-management'))->getStatusCode());

		$other = $this->user('cc-other');
		$foreign = $this->organizationWithProject($other, 'Obcy');
		$this->assertSame(404, $this->get(AppUrl::project($foreign['project']->id, 'conversions/center'))->getStatusCode());
	}
}
