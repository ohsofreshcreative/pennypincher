<?php

namespace Tests\Integration;

use App\PennyPincher\Http\Controllers\GoogleAdsController;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use Tests\Support\GoogleAdsMock;

require_once __DIR__ . '/GoogleAdsTestCase.php';

/**
 * Lista kont reklamowych po autoryzacji – regresja: błędy Google przy odczycie kont nie mogą
 * zamieniać się w komunikat „konto Google nie ma dostępu do żadnego konta reklamowego”.
 */
class GoogleAdsAccountsTest extends GoogleAdsTestCase
{
	private const NOT_APPROVED = [403, [['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission', 'details' => [[
		'@type' => 'type.googleapis.com/google.ads.googleads.v25.errors.GoogleAdsFailure',
		'errors' => [['errorCode' => ['authorizationError' => 'CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION'], 'message' => 'The Google Cloud project is not approved to access production accounts.']],
		'requestId' => 'req-403',
	]]]]]];

	public function testAccountReadErrorsAreReportedInsteadOfEmptyList(): void
	{
		[$project, $connection] = $this->pending('not-approved');
		$this->mock->on('customer', self::NOT_APPROVED);
		$service = $this->make(ConnectionService::class);

		$e = $this->assertThrows(GoogleAdsApiException::class, fn () => $service->accessibleAccounts($connection));
		$this->assertSame(GoogleAdsApiException::PERMISSION, $e->kind);
		$this->assertSame('CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION', $e->googleCode);
		$this->assertSame(403, $e->httpStatus);
		$this->assertContains('poziom „Test”', $e->userMessage());
		$this->assertContains('req-403', $e->userMessage());

		$discovery = $service->discoverAccounts($connection, true);
		$this->assertSame([GoogleAdsMock::CUSTOMER], $discovery['accessible_ids'], 'listAccessibleCustomers zwrócił konto.');
		$this->assertSame([], $discovery['accounts']);
		$this->assertCount(1, $discovery['errors']);
	}

	public function testEmptyResultIsNotCachedAndReconnectClearsCache(): void
	{
		[$project, $connection] = $this->pending('cache');
		$service = $this->make(ConnectionService::class);
		$this->mock->on('customer', self::NOT_APPROVED);
		$this->assertThrows(GoogleAdsApiException::class, fn () => $service->accessibleAccounts($connection));

		// Po przyznaniu dostępu w Google Cloud kolejne wejście na stronę widzi konto (pusty wynik nie był zapamiętany).
		$this->mock->withDefaultAccount();
		$this->assertSame(GoogleAdsMock::CUSTOMER, $service->accessibleAccounts($connection)[0]['customer_id']);

		// Ponowna autoryzacja usuwa zapamiętaną listę.
		$this->assertTrue(get_transient(ConnectionService::accountsCacheKey((int) $connection['id'])) !== false);
		$state = $this->stateFor($project, $connection);
		$service->complete($project, (int) $connection['connected_by'], 'code-2', $state);
		$this->assertFalse(get_transient(ConnectionService::accountsCacheKey((int) $connection['id'])));
	}

	public function testListAccessibleCustomersErrorsAreNeverAnEmptyList(): void
	{
		[$project, $connection] = $this->pending('list-errors');
		$service = $this->make(ConnectionService::class);
		$cases = [
			[400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Bad request']], GoogleAdsApiException::INVALID],
			[401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED', 'message' => 'Invalid credentials']], GoogleAdsApiException::AUTH],
			[403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'Google Ads API has not been used in project']], GoogleAdsApiException::PERMISSION],
			[429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota']], GoogleAdsApiException::QUOTA],
			[200, '<html>not json</html>', GoogleAdsApiException::INVALID],
		];

		foreach ($cases as [$status, $body, $kind]) {
			$this->mock->on('listAccessibleCustomers', [$status, $body]);
			$e = $this->assertThrows(GoogleAdsApiException::class, fn () => $service->accessibleAccounts($connection, true));
			$this->assertSame($kind, $e->kind, "HTTP {$status}");
		}

		// Prawdziwie pusta lista (200, brak resourceNames) to jedyny przypadek „brak kont”.
		$this->mock->on('listAccessibleCustomers', [200, (object) []]);
		$this->assertSame([], $service->accessibleAccounts($connection, true));
	}

	public function testResourceNamesAreParsed(): void
	{
		[$project, $connection] = $this->pending('parse');
		$this->mock->on('listAccessibleCustomers', [200, ['resourceNames' => ['customers/1234567890', 'customers/abc', 'invalid', 'customers/5555555555']]]);
		$this->mock->on('customer', fn ($request) => [200, GoogleAdsMock::stream([['customer' => ['id' => preg_match('#customers/(\d+)/#', $request['url'], $m) ? $m[1] : '0', 'descriptiveName' => 'Konto ' . $m[1], 'currencyCode' => 'PLN', 'manager' => false]]])]);

		$ids = array_column($this->make(ConnectionService::class)->accessibleAccounts($connection, true), 'customer_id');
		sort($ids);
		$this->assertSame(['1234567890', '5555555555'], $ids);
	}

	public function testDirectManagerAndNestedClientAccountsWithCorrectHeaders(): void
	{
		putenv('PP_GOOGLE_ADS_LOGIN_CUSTOMER_ID=8888888888');
		[$project, $connection] = $this->pending('mcc');
		$this->mock->on('listAccessibleCustomers', [200, ['resourceNames' => ['customers/' . GoogleAdsMock::CUSTOMER, 'customers/' . GoogleAdsMock::MANAGER, 'customers/7777777777']]]);
		$this->mock->on('customer', function ($request) {
			preg_match('#customers/(\d+)/#', $request['url'], $m);

			return match ($m[1]) {
				GoogleAdsMock::MANAGER => [200, GoogleAdsMock::stream([['customer' => ['id' => GoogleAdsMock::MANAGER, 'descriptiveName' => 'MCC OhSoFresh', 'manager' => true]]])],
				'7777777777' => [403, [['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'Customer is not enabled', 'details' => [['errors' => [['errorCode' => ['authorizationError' => 'CUSTOMER_NOT_ENABLED'], 'message' => 'The customer account is not enabled.']]]]]]]],
				default => [200, GoogleAdsMock::stream([['customer' => ['id' => GoogleAdsMock::CUSTOMER, 'descriptiveName' => 'Bezpośrednie', 'currencyCode' => 'PLN', 'manager' => false]]])],
			};
		});
		$this->mock->on('customer_client', [200, GoogleAdsMock::stream([
			['customerClient' => ['id' => GoogleAdsMock::MANAGER, 'manager' => true, 'level' => 0, 'status' => 'ENABLED']],
			['customerClient' => ['id' => '5555555555', 'descriptiveName' => 'Klient', 'currencyCode' => 'PLN', 'manager' => false, 'level' => 1, 'status' => 'ENABLED']],
			['customerClient' => ['id' => '6666666666', 'descriptiveName' => 'Klient pod sub-MCC', 'currencyCode' => 'PLN', 'manager' => false, 'level' => 2, 'status' => 'ENABLED']],
			['customerClient' => ['id' => '4444444444', 'descriptiveName' => 'Anulowane', 'manager' => false, 'level' => 1, 'status' => 'CANCELED']],
		])]);

		$discovery = $this->make(ConnectionService::class)->discoverAccounts($connection, true);
		$byId = array_column($discovery['accounts'], null, 'customer_id');
		ksort($byId);

		$this->assertSame(['1234567890', '5555555555', '6666666666'], array_map('strval', array_keys($byId)));
		$this->assertNull($byId[GoogleAdsMock::CUSTOMER]['login_customer_id'], 'Konto bezpośrednie bez konta menedżera.');
		$this->assertSame(GoogleAdsMock::MANAGER, $byId['6666666666']['login_customer_id']);
		$this->assertCount(1, $discovery['errors'], 'Nieaktywne konto pominięte i zgłoszone, reszta działa.');
		$this->assertSame('CUSTOMER_NOT_ENABLED', $discovery['errors'][0]['exception']->googleCode);
		$this->assertFalse(str_contains($this->mock->requests[array_key_last(array_filter($this->mock->requests, fn ($r) => $r['key'] === 'customer_client'))]['gaql'], 'level <= 1'), 'Konta na wszystkich poziomach MCC.');

		$list = array_values(array_filter($this->mock->requests, fn ($r) => $r['key'] === 'listAccessibleCustomers'))[0];
		$this->assertFalse(isset($list['headers']['login-customer-id']), 'listAccessibleCustomers bez login-customer-id, nawet gdy skonfigurowano domyślne MCC.');
		$this->assertFalse(isset($list['headers']['developer-token']));
		$this->assertSame('GET', $list['method']);
		$this->assertSame('https://googleads.googleapis.com/v25/customers:listAccessibleCustomers', $list['url']);
		$this->assertTrue(str_starts_with($list['headers']['Authorization'], 'Bearer '));

		foreach (array_filter($this->mock->requests, fn ($r) => in_array($r['key'], ['customer', 'customer_client'], true)) as $request) {
			preg_match('#customers/(\d+)/#', $request['url'], $m);
			$this->assertSame($m[1], $request['headers']['login-customer-id'], 'Szczegóły konta pobierane w kontekście tego samego konta (nie domyślnego MCC).');
		}
	}

	public function testDiagnosticsShowStatusesAndCodesWithoutSecrets(): void
	{
		[$project, $connection] = $this->pending('diagnose');
		$this->mock->on('customer', self::NOT_APPROVED);

		$rows = $this->make(ConnectionService::class)->diagnose($connection);
		$this->assertSame('GET /v25/customers:listAccessibleCustomers', $rows[1]['endpoint']);
		$this->assertSame(1, $rows[1]['count']);
		$this->assertSame(403, $rows[2]['status']);
		$this->assertSame('CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION', $rows[2]['code']);

		$dump = wp_json_encode($rows);
		foreach (['mock-access', 'mock-refresh', 'test-secret-value', getenv('PP_ENCRYPTION_KEY')] as $secret) {
			$this->assertFalse(str_contains($dump, $secret), 'Diagnostyka nie zawiera sekretów.');
		}
	}

	public function testPageShowsSpecificMessages(): void
	{
		[$project, $connection] = $this->pending('page');
		$controller = $this->make(GoogleAdsController::class);

		$this->mock->on('customer', self::NOT_APPROVED);
		$html = (string) $controller->show($project->id)->getContent();
		$this->assertContains('Brak uprawnień do Google Ads API', $html);
		$this->assertFalse(str_contains($html, 'nie ma dostępu do żadnego konta reklamowego'));

		$this->mock->on('listAccessibleCustomers', [200, (object) []]);
		$html = (string) $controller->show($project->id)->getContent();
		$this->assertContains('Brak kont reklamowych', $html);

		$this->mock->on('listAccessibleCustomers', [401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED', 'message' => 'Invalid']]]);
		$html = (string) $controller->show($project->id)->getContent();
		$this->assertContains('Błąd autoryzacji Google', $html);

		$this->mock->on('listAccessibleCustomers', [500, ['error' => ['code' => 500, 'status' => 'INTERNAL', 'message' => 'Internal error']]]);
		$html = (string) $controller->show($project->id)->getContent();
		$this->assertContains('Błąd Google Ads API', $html);

		$controller->diagnose($project->id);
		$html = (string) $controller->show($project->id)->getContent();
		$this->assertContains('Diagnostyka połączenia', $html);
		$this->assertContains('customers:listAccessibleCustomers', $html);
	}

	/**
	 * Połączenie po autoryzacji, przed wyborem konta.
	 *
	 * @return array{0: \App\PennyPincher\Domain\Projects\Project, 1: array}
	 */
	private function pending(string $name): array
	{
		$owner = $this->user($name);
		$project = $this->organizationWithProject($owner, 'Konta ' . $name)['project'];
		wp_set_current_user($owner);
		$this->mock->withDefaultAccount();
		$service = $this->make(ConnectionService::class);
		parse_str((string) parse_url($service->begin($project, $owner), PHP_URL_QUERY), $query);
		$state = $service->consumeState($query['state'], $owner);
		$service->complete($project, $owner, 'code', $state['verifier']);
		$this->mock->requests = [];

		return [$project, $this->make(GoogleAdsConnectionRepository::class)->currentForProject($project->id)];
	}

	private function stateFor($project, array $connection): string
	{
		$service = $this->make(ConnectionService::class);
		parse_str((string) parse_url($service->begin($project, (int) $connection['connected_by']), PHP_URL_QUERY), $query);

		return $service->consumeState($query['state'], (int) $connection['connected_by'])['verifier'];
	}
}
