<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Http\Controllers\GoogleAdsController;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleHttp;
use App\PennyPincher\Infrastructure\GoogleAds\OAuthClient;
use App\PennyPincher\Infrastructure\Security\SecretBox;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use Illuminate\Http\Request;
use Tests\Support\GoogleAdsMock;

require_once __DIR__ . '/GoogleAdsTestCase.php';

/**
 * OAuth 2.0, state, uprawnienia, szyfrowanie tokenów, odświeżanie i wybór konta – na atrapie HTTP.
 */
class GoogleAdsOAuthTest extends GoogleAdsTestCase
{
	public function testAuthorizationUrlUsesOfflineAccessPkceAndReadOnlyScope(): void
	{
		$owner = $this->user('oauth-url');
		$project = $this->organizationWithProject($owner, 'OAuth URL')['project'];

		$url = $this->make(ConnectionService::class)->begin($project, $owner);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		$this->assertTrue(str_starts_with($url, 'https://accounts.google.com/o/oauth2/v2/auth?'), 'Adres zgody Google.');
		$this->assertSame('code', $query['response_type']);
		$this->assertSame('offline', $query['access_type'], 'Potrzebny refresh token do synchronizacji w tle.');
		$this->assertSame('consent', $query['prompt']);
		$this->assertSame('S256', $query['code_challenge_method']);
		$this->assertTrue(strlen($query['code_challenge']) === 43, 'PKCE: SHA-256 w base64url.');
		$this->assertTrue(str_contains($query['scope'], 'https://www.googleapis.com/auth/adwords'));
		$this->assertTrue((bool) preg_match('/^[a-f0-9]{64}$/', $query['state']), 'State: 256 bitów losowości.');
		$this->assertSame((new GoogleAdsConfig())->redirectUri(), $query['redirect_uri']);
		$this->assertFalse(str_contains($url, 'test-secret-value'), 'Client secret nie trafia do przeglądarki.');
		$this->assertSame(0, count($this->mock->requests), 'Rozpoczęcie autoryzacji nie wysyła żądań do Google.');
	}

	public function testStateIsSingleUseBoundToUserAndExpires(): void
	{
		$owner = $this->user('state-owner');
		$other = $this->user('state-other');
		$project = $this->organizationWithProject($owner, 'State')['project'];
		$service = $this->make(ConnectionService::class);

		$state = $this->stateFrom($service->begin($project, $owner));
		$this->assertThrows(ValidationFailed::class, fn () => $service->consumeState($state, $other));
		// Próba innego użytkownika unieważnia state – właściciel musi zacząć od nowa (ochrona przed przechwyceniem).
		$this->assertThrows(ValidationFailed::class, fn () => $service->consumeState($state, $owner));

		$state = $this->stateFrom($service->begin($project, $owner));
		$data = $service->consumeState($state, $owner);
		$this->assertSame($project->id, $data['project_id']);
		$this->assertThrows(ValidationFailed::class, fn () => $service->consumeState($state, $owner));

		$state = $this->stateFrom($service->begin($project, $owner));
		$key = 'pp_oauth_' . hash('sha256', $state);
		$stored = get_transient($key);
		$stored['created_at'] = time() - 11 * MINUTE_IN_SECONDS;
		set_transient($key, $stored, 600);
		$this->assertThrows(ValidationFailed::class, fn () => $service->consumeState($state, $owner));

		$this->assertThrows(ValidationFailed::class, fn () => $service->consumeState('../../etc/passwd', $owner));
	}

	public function testCallbackRechecksPermissionsAndRejectsForeignState(): void
	{
		$owner = $this->user('cb-owner');
		$admin = $this->user('cb-admin');
		$outsider = $this->user('cb-outsider');
		$setup = $this->organizationWithProject($owner, 'Callback');
		$this->addMember($setup['organization'], $admin, Role::Admin);
		$service = $this->make(ConnectionService::class);
		$controller = $this->make(GoogleAdsController::class);

		// Administrator zaczyna łączenie, a w trakcie autoryzacji traci uprawnienia (zostaje członkiem).
		$state = $this->stateFrom($service->begin($setup['project'], $admin));
		$this->db->update('organization_users', ['role' => Role::Member->value], ['organization_id' => $setup['organization'], 'user_id' => $admin]);
		wp_set_current_user($admin);
		$this->assertThrows(AccessDenied::class, fn () => $controller->callback(Request::create('/app/google-ads/callback/', 'GET', ['state' => $state, 'code' => 'x'])));
		$this->assertSame(0, $this->mock->count('token'), 'Bez uprawnień kod nie jest wymieniany na tokeny.');

		// State innego użytkownika nie działa, nawet jeśli ktoś przechwycił adres powrotu.
		$state = $this->stateFrom($service->begin($setup['project'], $owner));
		wp_set_current_user($outsider);
		$response = $controller->callback(Request::create('/app/google-ads/callback/', 'GET', ['state' => $state, 'code' => 'x']));
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame(0, $this->mock->count('token'));
		$this->assertNull($this->make(GoogleAdsConnectionRepository::class)->currentForProject($setup['project']->id));

		// Właściciel z poprawnym state: kod wymieniony, połączenie czeka na wybór konta.
		$this->mock->withDefaultAccount();
		$state = $this->stateFrom($service->begin($setup['project'], $owner));
		wp_set_current_user($owner);
		$response = $controller->callback(Request::create('/app/google-ads/callback/', 'GET', ['state' => $state, 'code' => 'kod-z-google']));
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame(1, $this->mock->count('token'));
		$this->assertSame('pending_account', $this->make(GoogleAdsConnectionRepository::class)->currentForProject($setup['project']->id)['status']);

		$token = $this->mock->requests[0];
		$this->assertSame('authorization_code', $token['body']['grant_type']);
		$this->assertSame('kod-z-google', $token['body']['code']);
		$this->assertTrue(strlen($token['body']['code_verifier']) >= 43, 'Wymiana kodu wysyła code_verifier (PKCE).');

		// Odmowa zgody po stronie Google.
		$state = $this->stateFrom($service->begin($setup['project'], $owner));
		$response = $controller->callback(Request::create('/app/google-ads/callback/', 'GET', ['state' => $state, 'error' => 'access_denied']));
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame(1, $this->mock->count('token'));
	}

	public function testMemberCannotStartConnectionAndOutsiderCannotSeeIt(): void
	{
		$owner = $this->user('perm-owner');
		$member = $this->user('perm-member');
		$outsider = $this->user('perm-outsider');
		$setup = $this->organizationWithProject($owner, 'Uprawnienia');
		$this->addMember($setup['organization'], $member, Role::Member);
		$controller = $this->make(GoogleAdsController::class);

		wp_set_current_user($member);
		$this->assertThrows(AccessDenied::class, fn () => $controller->connect($setup['project']->id));
		$this->assertThrows(AccessDenied::class, fn () => $controller->disconnect($setup['project']->id));
		$this->assertThrows(AccessDenied::class, fn () => $controller->syncNow($setup['project']->id));

		wp_set_current_user($outsider);
		$this->assertThrows(NotFound::class, fn () => $controller->show($setup['project']->id));
		$this->assertThrows(NotFound::class, fn () => $controller->connect($setup['project']->id));
	}

	public function testTokensAreEncryptedAndNeverExposed(): void
	{
		$owner = $this->user('enc');
		$project = $this->organizationWithProject($owner, 'Szyfrowanie')['project'];
		$this->mock->withDefaultAccount();
		$connection = $this->connect($project, $owner);

		$raw = $this->db->first('SELECT refresh_token, access_token FROM ' . $this->db->table('google_ads_connections') . ' WHERE id = ?', [$connection['id']]);
		$this->assertTrue(str_starts_with($raw['refresh_token'], 'v1:') && ! str_contains($raw['refresh_token'], 'mock-refresh'), 'Refresh token zaszyfrowany.');
		$this->assertTrue(str_starts_with($raw['access_token'], 'v1:') && ! str_contains($raw['access_token'], 'mock-access'), 'Access token zaszyfrowany.');
		$this->assertFalse(array_key_exists('refresh_token', $connection), 'Publiczne dane połączenia nie zawierają tokenów.');
		$this->assertSame('ads@example.test', $connection['google_email']);
		$this->assertSame('active', $connection['status']);
		$this->assertSame('PLN', $connection['currency']);

		// Bez klucza tokenów nie da się odczytać – klucz jest poza bazą i repozytorium.
		$key = getenv('PP_ENCRYPTION_KEY');
		putenv('PP_ENCRYPTION_KEY=' . SecretBox::generateKey());
		$this->assertThrows(\RuntimeException::class, fn () => (new SecretBox())->decrypt($raw['refresh_token']));
		putenv("PP_ENCRYPTION_KEY={$key}");
	}

	public function testCompleteRequiresRefreshToken(): void
	{
		$owner = $this->user('norefresh');
		$project = $this->organizationWithProject($owner, 'Bez refresh')['project'];
		$this->mock->on('token', [200, ['access_token' => 'x', 'expires_in' => 3600]]);

		$this->assertThrows(GoogleAdsApiException::class, fn () => $this->make(ConnectionService::class)->complete($project, $owner, 'code', str_repeat('a', 64)), 'tokenu odświeżania');
		$this->assertNull($this->make(GoogleAdsConnectionRepository::class)->currentForProject($project->id));
	}

	public function testAccountSelectionOnlyAcceptsAccessibleAccountsIncludingManagerClients(): void
	{
		$owner = $this->user('accounts');
		$project = $this->organizationWithProject($owner, 'Konta')['project'];
		$service = $this->make(ConnectionService::class);
		$this->mock->withDefaultAccount()
			->on('listAccessibleCustomers', [200, ['resourceNames' => ['customers/' . GoogleAdsMock::CUSTOMER, 'customers/' . GoogleAdsMock::MANAGER]]])
			->on('customer', fn ($request) => [200, GoogleAdsMock::stream([['customer' => str_contains($request['url'], GoogleAdsMock::MANAGER)
				? ['id' => GoogleAdsMock::MANAGER, 'descriptiveName' => 'MCC', 'manager' => true]
				: ['id' => GoogleAdsMock::CUSTOMER, 'descriptiveName' => 'Konto testowe', 'currencyCode' => 'PLN', 'timeZone' => 'Europe/Warsaw', 'manager' => false]]])])
			->on('customer_client', [200, GoogleAdsMock::stream([['customerClient' => ['id' => '5555555555', 'descriptiveName' => 'Klient MCC', 'currencyCode' => 'PLN', 'timeZone' => 'Europe/Warsaw', 'manager' => false, 'status' => 'ENABLED']]])]);

		$state = $service->consumeState($this->stateFrom($service->begin($project, $owner)), $owner);
		$service->complete($project, $owner, 'code', $state['verifier']);
		$connection = $this->make(GoogleAdsConnectionRepository::class)->currentForProject($project->id);

		$accounts = $service->accessibleAccounts($connection);
		$ids = array_column($accounts, 'customer_id');
		sort($ids);
		$this->assertSame(['1234567890', '5555555555'], $ids, 'Konto bezpośrednie i konto klienta MCC (bez samego MCC).');

		$this->assertThrows(ValidationFailed::class, fn () => $service->selectAccount($project, $connection, '7777777777'));

		$selected = $service->selectAccount($project, $connection, '555-555-5555');
		$this->assertSame(GoogleAdsMock::MANAGER, $selected['login_customer_id']);
		$saved = $this->make(GoogleAdsConnectionRepository::class)->currentForProject($project->id);
		$this->assertSame('5555555555', $saved['customer_id']);
		$this->assertSame(GoogleAdsMock::MANAGER, $saved['login_customer_id']);

		// Zapytania o konto klienta MCC idą z nagłówkiem login-customer-id, bez developer tokenu (nie jest wymagany od 2026-09).
		$clientQuery = array_values(array_filter($this->mock->requests, fn ($r) => $r['key'] === 'customer_client'))[0];
		$this->assertSame(GoogleAdsMock::MANAGER, $clientQuery['headers']['login-customer-id']);
		$this->assertFalse(isset($clientQuery['headers']['developer-token']));
		$this->assertTrue(str_starts_with($clientQuery['url'], 'https://googleads.googleapis.com/v25/'), 'Domyślna wersja API v25.');
	}

	public function testExpiredAccessTokenIsRefreshedAndRevokedGrantRequiresReauth(): void
	{
		$owner = $this->user('refresh');
		$project = $this->organizationWithProject($owner, 'Odświeżanie')['project'];
		$this->mock->withDefaultAccount();
		$connection = $this->connect($project, $owner);
		$service = $this->make(ConnectionService::class);
		$repository = $this->make(GoogleAdsConnectionRepository::class);

		$tokensBefore = $this->mock->count('token');
		$this->assertSame('ya29.mock-access', $service->accessToken($connection), 'Ważny token z bazy (odszyfrowany).');
		$this->assertSame($tokensBefore, $this->mock->count('token'), 'Bez zbędnego odświeżania.');

		$repository->update((int) $connection['id'], ['access_token_expires_at' => gmdate('Y-m-d H:i:s', time() - 10)]);
		$this->mock->on('token', [200, ['access_token' => 'ya29.fresh', 'expires_in' => 3599]]);
		$this->assertSame('ya29.fresh', $service->accessToken($connection));
		$refresh = $this->mock->requests[count($this->mock->requests) - 1];
		$this->assertSame('refresh_token', $refresh['body']['grant_type']);
		$this->assertSame('1//mock-refresh', $refresh['body']['refresh_token']);

		$repository->update((int) $connection['id'], ['access_token_expires_at' => gmdate('Y-m-d H:i:s', time() - 10)]);
		$this->mock->on('token', [400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']]);
		$e = $this->assertThrows(GoogleAdsApiException::class, fn () => $service->accessToken($connection));
		$this->assertSame(GoogleAdsApiException::AUTH, $e->kind);
		$this->assertSame('reauth_required', $repository->find((int) $connection['id'])['status']);
	}

	public function testEncryptionKeyRotationReencryptsTokensOnRefresh(): void
	{
		$owner = $this->user('rotation');
		$project = $this->organizationWithProject($owner, 'Rotacja')['project'];
		$this->mock->withDefaultAccount();
		$connection = $this->connect($project, $owner);
		$repository = $this->make(GoogleAdsConnectionRepository::class);

		putenv('PP_ENCRYPTION_KEY_PREVIOUS=' . getenv('PP_ENCRYPTION_KEY'));
		putenv('PP_ENCRYPTION_KEY=' . SecretBox::generateKey());
		$repository->update((int) $connection['id'], ['access_token_expires_at' => gmdate('Y-m-d H:i:s', time() - 10)]);

		$this->assertSame('ya29.mock-access', $this->make(ConnectionService::class)->accessToken($connection), 'Stary klucz nadal odszyfrowuje refresh token.');

		putenv('PP_ENCRYPTION_KEY_PREVIOUS');
		$secrets = $repository->secrets((int) $connection['id']);
		$this->assertSame('1//mock-refresh', (new SecretBox())->decrypt($secrets['refresh_token']), 'Po odświeżeniu refresh token jest zaszyfrowany nowym kluczem.');
		$this->assertSame('ya29.mock-access', (new SecretBox())->decrypt($secrets['access_token']));
	}

	public function testDisconnectRevokesAndDeletesTokensButKeepsData(): void
	{
		$owner = $this->user('disconnect');
		$project = $this->organizationWithProject($owner, 'Rozłączenie')['project'];
		$this->mock->withDefaultAccount();
		$connection = $this->connect($project, $owner);
		$this->db->insert('campaigns', ['project_id' => $project->id, 'natural_key' => sha1('x'), 'name' => 'Kampania', 'created_at' => $this->db->now(), 'updated_at' => $this->db->now()]);

		$this->make(ConnectionService::class)->disconnect($connection);

		$this->assertSame(1, $this->mock->count('revoke'));
		$raw = $this->db->first('SELECT status, refresh_token, access_token FROM ' . $this->db->table('google_ads_connections') . ' WHERE id = ?', [$connection['id']]);
		$this->assertSame('disconnected', $raw['status']);
		$this->assertNull($raw['refresh_token']);
		$this->assertNull($raw['access_token']);
		$this->assertNull($this->make(GoogleAdsConnectionRepository::class)->currentForProject($project->id));
		$this->assertSame(1, $this->count('campaigns', $project->id), 'Dane projektu zostają po rozłączeniu.');
	}

	public function testApiErrorsAreClassified(): void
	{
		$cases = [
			[401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED', 'message' => 'Invalid credentials']], GoogleAdsApiException::AUTH],
			[403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'User does not have permission']], GoogleAdsApiException::PERMISSION],
			[429, [['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Too many requests', 'details' => [['errors' => [['errorCode' => ['quotaError' => 'RESOURCE_EXHAUSTED'], 'message' => 'quota', 'details' => ['quotaErrorDetails' => ['retryDelay' => '120s']]]], 'requestId' => 'req-1']]]]], GoogleAdsApiException::QUOTA],
			[503, ['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'Backend unavailable']], GoogleAdsApiException::TRANSIENT],
			[400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Bad GAQL']], GoogleAdsApiException::INVALID],
			[400, ['error' => 'invalid_grant', 'error_description' => 'Bad Request'], GoogleAdsApiException::AUTH],
			[401, ['error' => 'invalid_client'], GoogleAdsApiException::CONFIG],
		];

		foreach ($cases as [$status, $body, $kind]) {
			$e = GoogleHttp::errorFromResponse(['status' => $status, 'body' => $body, 'headers' => []], 'Test');
			$this->assertSame($kind, $e->kind, "HTTP {$status}");
		}

		$quota = GoogleHttp::errorFromResponse(['status' => 429, 'body' => $cases[2][1], 'headers' => []], 'Test');
		$this->assertSame(120, $quota->retryAfterSeconds);
		$this->assertSame('req-1', $quota->requestId);
		$this->assertTrue($quota->isRetryable());
		$this->assertFalse(GoogleHttp::errorFromResponse(['status' => 401, 'body' => $cases[0][1], 'headers' => []], 'Test')->isRetryable());
	}

	public function testHttpClientOnlyTalksToGoogleOverHttps(): void
	{
		$http = new GoogleHttp();

		foreach (['https://evil.example/token', 'http://oauth2.googleapis.com/token', 'https://oauth2.googleapis.com.evil.example/token'] as $url) {
			$e = $this->assertThrows(GoogleAdsApiException::class, fn () => $http->request('POST', $url, [], ['secret' => 'x']));
			$this->assertSame(GoogleAdsApiException::CONFIG, $e->kind);
		}

		$this->assertSame(0, count($this->mock->requests), 'Żadne żądanie nie zostało wysłane.');
	}

	public function testMissingConfigurationBlocksOAuth(): void
	{
		putenv('PP_GOOGLE_ADS_CLIENT_SECRET');
		$config = new GoogleAdsConfig();

		$this->assertSame(['PP_GOOGLE_ADS_CLIENT_SECRET'], $config->missing());
		$this->assertThrows(GoogleAdsApiException::class, fn () => $this->make(OAuthClient::class)->authorizationUrl(str_repeat('a', 64), str_repeat('b', 64)));
	}

	private function stateFrom(string $url): string
	{
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		return (string) $query['state'];
	}
}
