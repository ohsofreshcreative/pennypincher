<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Http\Controllers\GoogleAdsController;
use App\PennyPincher\Infrastructure\Security\SecretBox;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use App\PennyPincher\Support\Navigation;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Zarządzanie › Przegląd i konfiguracja (pp2_management): dostęp do wszystkich opcji, onboarding z diagnostyką bez
 * resetu autoryzacji, rozdział ustawień projekt / organizacja / serwer, brak zmian danych i sekretów.
 */
class AdministrationOverviewTest extends ChangeCenterTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::MANAGEMENT);
		parent::tearDown();
	}

	private function flag(bool $on): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::MANAGEMENT);
		if ($on) {
			add_filter('pennypincher/feature/' . Feature::MANAGEMENT, '__return_true');
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

	private function counts(): array
	{
		$out = [];
		foreach (['projects', 'organizations', 'organization_users', 'google_ads_connections', 'imports', 'sync_runs', 'campaigns', 'change_sets'] as $table) {
			$out[$table] = (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table($table));
		}

		return $out;
	}

	public function testOverviewReachesEveryOptionWithoutNetworkOrSecrets(): void
	{
		$this->account('adm-hub');
		$this->flag(true);
		$hub = AppUrl::project($this->project->id, 'administration');
		$http = 0;
		$counter = function ($pre) use (&$http) { $http++; return $pre; };
		add_filter('pre_http_request', $counter, 0);
		$before = $this->counts();
		$html = $this->body($hub);
		remove_filter('pre_http_request', $counter, 0);

		$this->assertSame(0, $http, 'Przegląd nie łączy się z Google Ads.');
		$this->assertSame($before, $this->counts(), 'Odczyt nie zmienia danych.');

		// Wszystkie podwidoki Zarządzania osiągalne z przeglądu (i działają).
		foreach (['settings', 'google-ads', 'management', 'imports'] as $path) {
			$url = AppUrl::project($this->project->id, $path);
			$this->assertContains('href="' . $url . '"', $html, $path);
			$this->assertSame(200, $this->get($url)->getStatusCode(), $path);
		}
		$this->assertContains('href="' . AppUrl::to('projects') . '"', $html);
		$this->assertContains('href="' . AppUrl::to('organizations/' . $this->organization . '/members') . '"', $html);

		// Onboarding na podstawie stanu: połączenie, konto i synchronizacja gotowe.
		foreach (['connect', 'account', 'sync'] as $step) {
			$this->assertContains('data-step="' . $step . '" data-done="1"', $html, $step);
		}
		$this->assertContains('data-step="imports" data-done="0"', $html);

		// Rozdział ustawień i brak sekretów w HTML.
		foreach (['project', 'organization', 'server'] as $scope) {
			$this->assertContains('data-scope="' . $scope . '"', $html);
		}
		$stored = $this->make(GoogleAdsConnectionRepository::class)->secrets((int) $this->connection['id']);
		$box = $this->make(SecretBox::class);
		foreach (array_filter([$stored['refresh_token'], $stored['access_token'], $stored['refresh_token'] ? $box->decrypt($stored['refresh_token']) : null]) as $secret) {
			$this->assertFalse(str_contains($html, (string) $secret), 'Sekret nie trafia do HTML.');
		}
		$this->assertFalse(str_contains($html, 'refresh_token'));
	}

	public function testDiagnosticsKeepsAuthorizationAndReturnsToOverview(): void
	{
		$this->account('adm-diag');
		$this->flag(true);
		$id = (int) $this->connection['id'];
		$repo = $this->make(GoogleAdsConnectionRepository::class);
		$box = $this->make(SecretBox::class);
		$refresh = $box->decrypt($repo->secrets($id)['refresh_token']);
		$before = $this->counts();

		wp_set_current_user($this->owner);
		$request = Request::create('/', 'POST', ['return' => 'administration']);
		app()->instance('request', $request);
		$response = $this->make(GoogleAdsController::class)->diagnose($this->project->id);
		$this->assertTrue($response->isRedirect(AppUrl::project($this->project->id, 'administration') . '#pp-diagnostics'));

		$after = $repo->find($id);
		$this->assertSame(GoogleAdsConnectionRepository::STATUS_ACTIVE, $after['status'], 'Połączenie nadal aktywne.');
		$this->assertSame($this->connection['customer_id'], $after['customer_id']);
		$this->assertSame($refresh, $box->decrypt($repo->secrets($id)['refresh_token']), 'Autoryzacja bez zmian (bez resetu tokenu).');
		$this->assertSame($before, $this->counts());

		// Wynik diagnostyki taki sam na przeglądzie i na stronie Google Ads.
		$cut = fn ($h) => preg_replace('/\s+/', ' ', substr($h, strpos($h, 'id="pp-diagnostics"'), strpos($h, '</table>', strpos($h, 'id="pp-diagnostics"')) - strpos($h, 'id="pp-diagnostics"')));
		$hub = $this->body(AppUrl::project($this->project->id, 'administration'));
		$page = $this->body(AppUrl::project($this->project->id, 'google-ads'));
		$this->assertContains('customers:listAccessibleCustomers', $hub);
		$this->assertSame($cut($page), $cut($hub));

		// Bez pola powrotu – jak dotąd strona Google Ads.
		app()->instance('request', Request::create('/', 'POST'));
		$this->assertTrue($this->make(GoogleAdsController::class)->diagnose($this->project->id)->isRedirect(AppUrl::project($this->project->id, 'google-ads') . '#pp-diagnostics'));
	}

	public function testPermissionsFlagAndIsolation(): void
	{
		$this->account('adm-flag');

		$this->flag(false);
		$response = $this->get(AppUrl::project($this->project->id, 'administration', ['saved' => '1']));
		$this->assertTrue($response->isRedirect(AppUrl::project($this->project->id, 'settings', ['saved' => '1'])), 'Bez flagi: Ustawienia jak dotąd.');
		$this->assertSame('settings', Navigation::resolve('administration'));
		$this->assertSame(Navigation::GROUPS['administration'], Navigation::groups()['administration']);

		$this->flag(true);
		$this->assertSame('administration', Navigation::resolve('administration'));
		$this->assertSame('administration', array_key_first(Navigation::groups()['administration']['items']));
		$this->assertSame('administration', Navigation::groupOf('administration'));
		$this->assertTrue($this->get(AppUrl::to('administration'))->isRedirect(AppUrl::project($this->project->id, 'administration')), 'Skrót bez projektu.');

		// Członek: widzi przegląd, bez diagnostyki i zarządzania członkami (uprawnienia jak w modułach).
		[$member] = $this->member('adm-member', Role::Member);
		$html = $this->body(AppUrl::project($this->project->id, 'administration'), $member);
		$this->assertFalse(str_contains($html, 'google-ads/diagnose'));
		$this->assertFalse(str_contains($html, '/members"'));
		$this->assertContains('Ustawienia projektu zmienia właściciel lub administrator organizacji.', $html);

		// Projekt bez połączenia: wskazany pierwszy brakujący krok.
		$fresh = $this->organizationWithProject($this->owner, 'Bez połączenia')['project'];
		$html = $this->body(AppUrl::project($fresh->id, 'administration'));
		$this->assertContains('data-step="connect" data-done="0"', $html);
		$this->assertContains('Następny krok:', $html);

		$other = $this->user('adm-other');
		$foreign = $this->organizationWithProject($other, 'Obcy');
		$this->assertSame(404, $this->get(AppUrl::project($foreign['project']->id, 'administration'))->getStatusCode());
	}
}
