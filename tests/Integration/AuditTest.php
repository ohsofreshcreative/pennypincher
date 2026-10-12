<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Http\Controllers\AuditsController;
use App\PennyPincher\Infrastructure\Security\Hardening;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Services\AuditService;
use App\PennyPincher\Services\DashboardService;
use Tests\IntegrationTestCase;

class AuditTest extends IntegrationTestCase
{
	public function testAuditOnImportedDataStoresScoreFindingsAndDataQuality(): void
	{
		$owner = $this->user('audit-run');
		$project = $this->organizationWithProject($owner, 'Audyt')['project'];
		$runId = $this->make(AuditService::class)->run($project, 'manual', $owner);
		$audits = $this->make(AuditRepository::class);
		$run = $audits->findRun($project->id, $runId);
		$rules = array_column($audits->findings($runId), 'rule_id');

		$this->assertNull($run['score'], 'Pusty projekt: brak wyniku zamiast fikcyjnej liczby.');
		$this->assertTrue(str_contains($run['score_breakdown']['reason'], 'Brak danych'));
		$this->assertTrue(in_array('DQ001', $rules, true));
		$this->assertTrue(in_array('DQ002', $rules, true));
		$this->assertSame(\App\PennyPincher\Domain\Audit\RuleRegistry::VERSION, $run['rules_version']);
		$this->assertSame(30, (int) $run['data_quality']['total_days']);
	}

	public function testStatusesCarryOverAndDismissedFindingsDoNotLowerScore(): void
	{
		$owner = $this->user('audit-status');
		$project = $this->organizationWithProject($owner, 'Statusy')['project'];
		$this->import($project, $owner, 'campaigns-pl-bez-znakow.csv');
		$service = $this->make(AuditService::class);
		$audits = $this->make(AuditRepository::class);

		$first = $service->run($project, 'manual', $owner);
		$finding = $audits->findings($first)[0];
		$scoreBefore = $audits->findRun($project->id, $first)['score_breakdown'];

		$updated = $service->updateStatus($project, (int) $finding['id'], 'dismissed', $owner);
		$this->assertSame('dismissed', $updated['status']);
		$this->assertSame($owner, (int) $updated['status_changed_by']);
		$scoreAfter = $audits->findRun($project->id, $first)['score_breakdown'];
		$dimension = $finding['score_dimension'];
		$this->assertTrue($scoreAfter['dimensions'][$dimension]['penalty'] <= $scoreBefore['dimensions'][$dimension]['penalty'] - min($finding['penalty'], $scoreBefore['dimensions'][$dimension]['penalty']) + 0.001, 'Kara pominiętego problemu zdjęta.');

		$second = $service->run($project, 'manual', $owner);
		$carried = array_values(array_filter($audits->findings($second), fn ($f) => $f['fingerprint'] === $finding['fingerprint']));
		$this->assertSame('dismissed', $carried[0]['status'], 'Status przechodzi na kolejny audyt.');

		$this->assertThrows(ValidationFailed::class, fn () => $service->updateStatus($project, (int) $finding['id'], 'deleted', $owner));
	}

	public function testAuditsAreIsolatedBetweenOrganizations(): void
	{
		$alice = $this->user('audit-alice');
		$bob = $this->user('audit-bob');
		$member = $this->user('audit-member');
		$a = $this->organizationWithProject($alice, 'Audyt A');
		$b = $this->organizationWithProject($bob, 'Audyt B')['project'];
		$this->addMember($a['organization'], $member, Role::Member);
		$service = $this->make(AuditService::class);
		$runId = $service->run($a['project'], 'manual', $alice);
		$findingId = (int) $this->make(AuditRepository::class)->findings($runId)[0]['id'];

		$this->assertNull($this->make(AuditRepository::class)->findRun($b->id, $runId));
		$this->assertThrows(ValidationFailed::class, fn () => $service->updateStatus($b, $findingId, 'reviewed', $bob));

		$controller = $this->make(AuditsController::class);
		wp_set_current_user($bob);
		$this->assertThrows(NotFound::class, fn () => $controller->show($a['project']->id, $runId));
		$this->assertThrows(NotFound::class, fn () => $controller->store($a['project']->id, \Illuminate\Http\Request::create('/')));

		wp_set_current_user($member);
		$this->assertSame(200, $controller->show($a['project']->id, $runId)->getStatusCode(), 'Członek organizacji widzi audyt.');
	}

	public function testDashboardHealthShowsConnectionAuditAndPacing(): void
	{
		$owner = $this->user('audit-dash');
		$project = $this->organizationWithProject($owner, 'Dashboard')['project'];
		$this->make(AuditService::class)->run($project, 'manual', $owner);

		$health = $this->make(DashboardService::class)->health($project);

		$this->assertNull($health['connection']);
		$this->assertTrue($health['audit'] !== null);
		$this->assertTrue($health['auditCritical'] >= 1, 'DQ001 (brak danych) jest krytyczny.');
		$this->assertSame(3_000_000_000, $health['pacing']['budget']);
		$this->assertTrue(array_key_exists('forecast', $health['pacing']));
		$this->assertSame('DQ001', $health['dataQuality'][0]['rule_id']);
	}

	public function testAppRoutesAreRegisteredBehindAuthentication(): void
	{
		$routes = app('router')->getRoutes();
		$routes->refreshNameLookups();

		foreach (['pp.google-ads', 'pp.google-ads.callback', 'pp.audits'] as $name) {
			$route = $routes->getByName($name);
			$this->assertTrue($route !== null, "Trasa {$name}");
			$this->assertTrue(in_array(\App\PennyPincher\Http\Middleware\Authenticate::class, $route->gatherMiddleware(), true), "{$name} wymaga logowania.");
			$this->assertTrue(in_array(\App\PennyPincher\Http\Middleware\VerifyNonce::class, $route->gatherMiddleware(), true), "{$name} chroniona przed CSRF.");
		}

		$this->assertSame('app/google-ads/callback', $routes->getByName('pp.google-ads.callback')->uri());
	}

	public function testWordPressIsHardened(): void
	{
		update_option('users_can_register', 1);
		$this->assertSame('0', (string) get_option('users_can_register'), 'Rejestracja zawsze wyłączona.');
		$this->assertFalse((bool) apply_filters('xmlrpc_enabled', true));
		$this->assertTrue(Hardening::isUsersRoute('/wp/v2/users'));
		$this->assertTrue(Hardening::isUsersRoute('/wp-json/wp/v2/users/1'));
		$this->assertFalse(Hardening::isUsersRoute('/wp/v2/posts'));

		wp_set_current_user(0);
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users';
		$result = apply_filters('rest_authentication_errors', null);
		unset($GLOBALS['wp']->query_vars['rest_route']);
		$this->assertTrue(is_wp_error($result) && $result->get_error_data()['status'] === 401, 'Lista użytkowników niedostępna anonimowo.');

		$headers = \App\PennyPincher\Http\Responses::securityHeaders();
		$this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
		$this->assertSame('no-store, private', $headers['Cache-Control']);
	}

	public function testMemberCanRunAuditButOutsiderCannot(): void
	{
		$owner = $this->user('audit-perm');
		$outsider = $this->user('audit-perm-out');
		$setup = $this->organizationWithProject($owner, 'Uprawnienia audytu');
		$controller = $this->make(AuditsController::class);

		wp_set_current_user($outsider);
		$this->assertThrows(NotFound::class, fn () => $controller->index($setup['project']->id, \Illuminate\Http\Request::create('/')));

		wp_set_current_user($owner);
		$response = $controller->store($setup['project']->id, \Illuminate\Http\Request::create('/'));
		$this->assertSame(302, $response->getStatusCode());
		$this->assertTrue($this->make(AuditRepository::class)->latestRun($setup['project']->id) !== null);
	}
}
