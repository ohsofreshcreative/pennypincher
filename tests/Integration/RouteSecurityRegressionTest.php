<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Http\Middleware\VerifyNonce;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;
use Tests\IntegrationTestCase;

/**
 * Regresja bezpieczeństwa wszystkich tras (z włączonymi wszystkimi flagami 2.0): CSRF na każdym POST, izolacja
 * projektów na każdej trasie projektu, role przy operacjach wymagających wyższych uprawnień, brak ruchu sieciowego
 * i brak zmian w danych przy odrzuconych żądaniach.
 */
class RouteSecurityRegressionTest extends IntegrationTestCase
{
	private const TABLES = ['projects', 'organizations', 'organization_users', 'imports', 'campaigns', 'recommendations', 'action_items', 'change_sets', 'change_operations', 'alerts', 'conversion_actions', 'campaign_plans', 'google_ads_connections', 'sync_runs', 'dataforseo_tasks'];

	public function setUp(): void
	{
		parent::setUp();
		foreach ((new \ReflectionClass(Feature::class))->getConstants() as $flag) {
			add_filter('pennypincher/feature/' . $flag, '__return_true');
		}
	}

	public function tearDown(): void
	{
		foreach ((new \ReflectionClass(Feature::class))->getConstants() as $flag) {
			remove_all_filters('pennypincher/feature/' . $flag);
		}
		parent::tearDown();
	}

	private function request(string $method, string $url, int $user, array $data = []): int
	{
		wp_set_current_user($user);
		$_SERVER['REQUEST_METHOD'] = $method;
		$request = Request::create($url, $method, $data);
		app()->instance('request', $request);

		return Application::getInstance()->make(Kernel::class)->handle($request)->getStatusCode();
	}

	/** @return array<string, int> */
	private function state(): array
	{
		$out = [];
		foreach (self::TABLES as $table) {
			$out[$table] = (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table($table));
		}

		return $out;
	}

	/** @return array<int, array{0: string, 1: string}> metoda i URI tras aplikacji */
	private function routes(): array
	{
		$out = [];
		foreach (app('router')->getRoutes() as $route) {
			if (! str_starts_with($route->uri(), 'app/') || str_contains($route->uri(), '{any}')) {
				continue;
			}
			foreach (array_intersect($route->methods(), ['GET', 'POST']) as $method) {
				$out[] = [$method, $route->uri()];
			}
		}

		return $out;
	}

	private static function url(string $uri, int $projectId): string
	{
		return '/' . preg_replace(['/\{project\}/', '/\{format\}/', '/\{group\}(?!\w)/', '/\{\w+\}/'], [(string) $projectId, 'csv', 'optimization', '999999'], $uri);
	}

	public function testEveryPostRequiresNonceAndForeignProjectsAre404OnEveryRoute(): void
	{
		$owner = $this->user('sec-owner');
		$outsider = $this->user('sec-outsider');
		$project = $this->organizationWithProject($owner, 'Chroniony')['project'];
		$this->organizationWithProject($outsider, 'Obca organizacja');
		$http = 0;
		$counter = function ($pre) use (&$http) { $http++; return new \WP_Error('blocked', 'sieć zablokowana w teście'); };
		add_filter('pre_http_request', $counter, 0);
		$before = $this->state();
		$csrf = [];
		$leaks = [];
		$posts = 0;

		foreach ($this->routes() as [$method, $uri]) {
			$url = self::url($uri, $project->id);

			if ($method === 'POST') {
				$posts++;
				$status = $this->request('POST', $url, $owner);
				if ($status !== 419) {
					$csrf[] = "$uri: $status";
				}
			}

			if (str_starts_with($uri, 'app/projects/{project}')) {
				wp_set_current_user($outsider);
				$data = $method === 'POST' ? [VerifyNonce::FIELD => wp_create_nonce(VerifyNonce::ACTION)] : [];
				$status = $this->request($method, $url, $outsider, $data);
				if ($status !== 404) {
					$leaks[] = "$method $uri: $status";
				}
			}
		}

		remove_filter('pre_http_request', $counter, 0);
		$this->assertTrue($posts > 60, 'Sprawdzono wszystkie trasy POST (' . $posts . ').');
		$this->assertSame([], $csrf, 'POST bez nonce = 419.');
		$this->assertSame([], $leaks, 'Cudzy projekt = 404 na każdej trasie.');
		$this->assertSame($before, $this->state(), 'Odrzucone żądania nie zmieniają danych.');
		$this->assertSame(0, $http, 'Odrzucone żądania nie łączą się z zewnętrznymi usługami.');
	}

	public function testMemberCannotRunOperationsReservedForHigherRoles(): void
	{
		$owner = $this->user('sec-role-owner');
		$created = $this->organizationWithProject($owner, 'Role');
		$project = $created['project'];
		$member = $this->user('sec-role-member');
		$this->addMember($created['organization'], $member, Role::Member);
		$before = $this->state();
		$denied = [];

		$operations = [
			'settings' => ['name' => 'Przejęty'],
			'settings/context' => [],
			'google-ads/connect' => [],
			'google-ads/disconnect' => [],
			'google-ads/diagnose' => ['return' => 'administration'],
			'google-ads/backfill' => [],
			'conversions' => ['class' => [1 => 'lead']],
			'keywords/dataforseo' => ['consent' => '1'],
		];

		foreach ($operations as $path => $data) {
			wp_set_current_user($member);
			$status = $this->request('POST', AppUrl::project($project->id, $path), $member, $data + [VerifyNonce::FIELD => wp_create_nonce(VerifyNonce::ACTION)]);
			if ($status !== 403) {
				$denied[] = "$path: $status";
			}
		}

		wp_set_current_user($member);
		$status = $this->request('POST', AppUrl::to('organizations/' . $created['organization'] . '/members'), $member, ['identifier' => 'x@example.test', 'role' => 'admin', VerifyNonce::FIELD => wp_create_nonce(VerifyNonce::ACTION)]);
		if ($status !== 403) {
			$denied[] = "organizations/members: $status";
		}

		$this->assertSame([], $denied, 'Członek dostaje 403 dla operacji właściciela/administratora.');
		$this->assertSame($before, $this->state());
		$this->assertSame($project->name, $this->make(\App\PennyPincher\Repositories\ProjectRepository::class)->findForUser($owner, $project->id)->name, 'Nazwa projektu bez zmian.');
	}
}
