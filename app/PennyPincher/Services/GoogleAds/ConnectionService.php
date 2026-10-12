<?php

namespace App\PennyPincher\Services\GoogleAds;

use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsClient;
use App\PennyPincher\Infrastructure\GoogleAds\OAuthClient;
use App\PennyPincher\Infrastructure\Security\SecretBox;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;

/**
 * Cykl życia połączenia projektu z Google Ads: OAuth (state + PKCE), wybór konta, tokeny, rozłączenie.
 *
 * State jest jednorazowy, ważny 10 minut i powiązany z użytkownikiem oraz projektem; uprawnienia
 * sprawdza kontroler przy rozpoczęciu i przy powrocie z Google.
 */
class ConnectionService
{
	private const STATE_TTL = 10 * MINUTE_IN_SECONDS;

	public function __construct(
		private OAuthClient $oauth,
		private GoogleAdsClient $client,
		private SecretBox $secrets,
		private GoogleAdsConnectionRepository $connections,
	) {
	}

	/**
	 * Tworzy state i zwraca adres zgody Google.
	 */
	public function begin(Project $project, int $userId): string
	{
		$state = bin2hex(random_bytes(32));
		$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

		set_transient($this->stateKey($state), [
			'user_id' => $userId,
			'project_id' => $project->id,
			'verifier' => $verifier,
			'created_at' => time(),
		], self::STATE_TTL);

		return $this->oauth->authorizationUrl($state, $verifier);
	}

	/**
	 * Odczytuje i unieważnia state (jednorazowy). Zwraca dane tylko dla tego samego użytkownika.
	 *
	 * @return array{user_id: int, project_id: int, verifier: string}
	 */
	public function consumeState(string $state, int $userId): array
	{
		if (! preg_match('/^[a-f0-9]{64}$/', $state)) {
			throw new ValidationFailed(['state' => 'Nieprawidłowy parametr state. Rozpocznij łączenie ponownie.']);
		}

		$key = $this->stateKey($state);
		$data = get_transient($key);
		delete_transient($key);

		if (! is_array($data) || (int) $data['user_id'] !== $userId || time() - (int) $data['created_at'] > self::STATE_TTL) {
			throw new ValidationFailed(['state' => 'Sesja łączenia z Google wygasła albo należy do innego użytkownika. Rozpocznij łączenie ponownie.']);
		}

		return ['user_id' => (int) $data['user_id'], 'project_id' => (int) $data['project_id'], 'verifier' => (string) $data['verifier']];
	}

	/**
	 * Wymienia kod na tokeny i zapisuje (zaszyfrowane) połączenie oczekujące na wybór konta.
	 * Ponowna autoryzacja istniejącego połączenia zachowuje wybrane konto.
	 */
	public function complete(Project $project, int $userId, string $code, string $verifier): int
	{
		$tokens = $this->oauth->exchangeCode($code, $verifier);
		$current = $this->connections->currentForProject($project->id);

		if ($current !== null) {
			delete_transient(self::accountsCacheKey((int) $current['id']));
		}

		if ($tokens['refresh_token'] === null) {
			throw new GoogleAdsApiException('Google nie zwrócił tokenu odświeżania. Usuń dostęp aplikacji na koncie Google (myaccount.google.com/permissions) i połącz ponownie.', GoogleAdsApiException::AUTH);
		}

		$values = [
			'refresh_token' => $this->secrets->encrypt($tokens['refresh_token']),
			'access_token' => $this->secrets->encrypt($tokens['access_token']),
			'access_token_expires_at' => gmdate('Y-m-d H:i:s', time() + $tokens['expires_in'] - 60),
			'scopes' => mb_substr($tokens['scope'], 0, 500),
			'google_email' => $tokens['email'],
			'last_error' => null,
			'connected_by' => $userId,
		];

		if ($current !== null && $current['customer_id'] !== null) {
			$this->connections->update((int) $current['id'], $values + ['status' => GoogleAdsConnectionRepository::STATUS_ACTIVE]);

			return (int) $current['id'];
		}

		if ($current !== null) {
			$this->connections->update((int) $current['id'], $values + ['status' => GoogleAdsConnectionRepository::STATUS_PENDING]);

			return (int) $current['id'];
		}

		return $this->connections->create($values + [
			'project_id' => $project->id,
			'status' => GoogleAdsConnectionRepository::STATUS_PENDING,
		]);
	}

	/**
	 * Konta reklamowe dostępne dla połączonego konta Google (bezpośrednio i przez konta menedżera).
	 *
	 * Gdy Google zwraca identyfikatory kont, ale żadnego nie da się odczytać, rzucamy błąd z Google
	 * (np. brak poziomu dostępu projektu Cloud) – nie udajemy, że kont nie ma.
	 *
	 * @return array<int, array{customer_id: string, name: string, currency: ?string, time_zone: ?string, login_customer_id: ?string, test: bool}>
	 */
	public function accessibleAccounts(array $connection, bool $refresh = false): array
	{
		$result = $this->discoverAccounts($connection, $refresh);

		if ($result['accounts'] === [] && $result['errors'] !== []) {
			throw self::mostRelevantError(array_column($result['errors'], 'exception'));
		}

		return $result['accounts'];
	}

	/**
	 * Pełny wynik wyszukiwania kont: konta, identyfikatory z listAccessibleCustomers i błędy poszczególnych kont.
	 * Pusta lista kont nie jest zapisywana w pamięci podręcznej.
	 *
	 * @return array{accounts: array<int, array>, accessible_ids: array<int, string>, errors: array<int, array{customer_id: string, step: string, exception: GoogleAdsApiException}>}
	 */
	public function discoverAccounts(array $connection, bool $refresh = false): array
	{
		$cacheKey = self::accountsCacheKey((int) $connection['id']);
		$cached = $refresh ? false : get_transient($cacheKey);

		if (is_array($cached) && ($cached['accounts'] ?? []) !== []) {
			return ['accounts' => $cached['accounts'], 'accessible_ids' => $cached['accessible_ids'] ?? [], 'errors' => []];
		}

		$token = $this->accessToken($connection);
		$accessibleIds = $this->client->listAccessibleCustomers($token);
		$accounts = [];
		$errors = [];

		foreach ($accessibleIds as $customerId) {
			try {
				// login-customer-id = to samo konto: dostęp bezpośredni (pomija ewentualne domyślne konto menedżera z konfiguracji).
				$rows = $this->client->search($token, $customerId, self::CUSTOMER_QUERY, $customerId);
			} catch (GoogleAdsApiException $e) {
				if ($e->kind === GoogleAdsApiException::AUTH) {
					throw $e;
				}

				$errors[] = ['customer_id' => $customerId, 'step' => 'customer', 'exception' => $e];
				continue;
			}

			$customer = $rows[0]['customer'] ?? null;

			if (! $customer) {
				continue;
			}

			if (! empty($customer['manager'])) {
				// Konto menedżera: konta klientów na wszystkich poziomach hierarchii (bez kolejnych menedżerów).
				try {
					$clients = $this->client->search($token, $customerId, self::CLIENTS_QUERY, $customerId);
				} catch (GoogleAdsApiException $e) {
					if ($e->kind === GoogleAdsApiException::AUTH) {
						throw $e;
					}

					$errors[] = ['customer_id' => $customerId, 'step' => 'customer_client', 'exception' => $e];
					continue;
				}

				foreach ($clients as $row) {
					$client = $row['customerClient'] ?? [];

					if (($client['status'] ?? 'ENABLED') !== 'ENABLED' || empty($client['id']) || ! empty($client['manager'])) {
						continue;
					}

					$accounts[(string) $client['id']] ??= [
						'customer_id' => (string) $client['id'],
						'name' => (string) ($client['descriptiveName'] ?? 'Konto ' . $client['id']),
						'currency' => $client['currencyCode'] ?? null,
						'time_zone' => $client['timeZone'] ?? null,
						'login_customer_id' => (string) $customerId,
						'test' => ! empty($client['testAccount']),
					];
				}

				continue;
			}

			$accounts[(string) $customer['id']] = [
				'customer_id' => (string) $customer['id'],
				'name' => (string) ($customer['descriptiveName'] ?? 'Konto ' . $customer['id']),
				'currency' => $customer['currencyCode'] ?? null,
				'time_zone' => $customer['timeZone'] ?? null,
				'login_customer_id' => null,
				'test' => ! empty($customer['testAccount']),
			];
		}

		$accounts = array_values($accounts);
		usort($accounts, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

		if ($accounts !== []) {
			set_transient($cacheKey, ['accounts' => $accounts, 'accessible_ids' => $accessibleIds], 10 * MINUTE_IN_SECONDS);
		} else {
			delete_transient($cacheKey);
		}

		return ['accounts' => $accounts, 'accessible_ids' => $accessibleIds, 'errors' => $errors];
	}

	/**
	 * Diagnostyka połączenia: kolejne zapytania i ich wyniki, bez tokenów i sekretów.
	 *
	 * @return array<int, array{endpoint: string, status: ?int, code: ?string, message: ?string, count: ?int}>
	 */
	public function diagnose(array $connection): array
	{
		$version = $this->client->apiVersion();
		$rows = [];

		try {
			$token = $this->accessToken($connection);
			$rows[] = ['endpoint' => 'POST oauth2.googleapis.com/token (odświeżenie)', 'status' => 200, 'code' => null, 'message' => 'token dostępu ważny', 'count' => null];
		} catch (GoogleAdsApiException $e) {
			return [self::diagnosticRow('POST oauth2.googleapis.com/token (odświeżenie)', $e)];
		}

		$endpoint = "GET /{$version}/customers:listAccessibleCustomers";

		try {
			$ids = $this->client->listAccessibleCustomers($token);
			$rows[] = ['endpoint' => $endpoint, 'status' => 200, 'code' => null, 'message' => $ids === [] ? 'Google nie zwrócił żadnych kont dla tego konta Google' : 'konta: ' . implode(', ', $ids), 'count' => count($ids)];
		} catch (GoogleAdsApiException $e) {
			$rows[] = self::diagnosticRow($endpoint, $e);

			return $rows;
		}

		foreach ($ids as $customerId) {
			$endpoint = "POST /{$version}/customers/{$customerId}/googleAds:searchStream (customer)";

			try {
				$customer = $this->client->search($token, $customerId, self::CUSTOMER_QUERY, $customerId)[0]['customer'] ?? [];
				$rows[] = ['endpoint' => $endpoint, 'status' => 200, 'code' => null, 'message' => sprintf('%s, %s%s%s', $customer['descriptiveName'] ?? 'bez nazwy', $customer['currencyCode'] ?? '—', ! empty($customer['manager']) ? ', konto menedżera' : '', ! empty($customer['testAccount']) ? ', konto testowe' : ''), 'count' => 1];
			} catch (GoogleAdsApiException $e) {
				$rows[] = self::diagnosticRow($endpoint, $e);
				continue;
			}

			if (! empty($customer['manager'])) {
				$endpoint = "POST /{$version}/customers/{$customerId}/googleAds:searchStream (customer_client)";

				try {
					$clients = array_filter($this->client->search($token, $customerId, self::CLIENTS_QUERY, $customerId), fn ($row) => empty($row['customerClient']['manager']));
					$rows[] = ['endpoint' => $endpoint, 'status' => 200, 'code' => null, 'message' => 'konta klientów pod MCC', 'count' => count($clients)];
				} catch (GoogleAdsApiException $e) {
					$rows[] = self::diagnosticRow($endpoint, $e);
				}
			}
		}

		return $rows;
	}

	public const CUSTOMER_QUERY = 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.manager, customer.test_account FROM customer';

	public const CLIENTS_QUERY = 'SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.time_zone, customer_client.manager, customer_client.test_account, customer_client.status, customer_client.level FROM customer_client WHERE customer_client.manager = FALSE';

	public static function accountsCacheKey(int $connectionId): string
	{
		return 'pp_gads_accounts_' . $connectionId;
	}

	/**
	 * Najbardziej przydatny błąd do pokazania: uprawnienia projektu/użytkownika > inne > nieaktywne konto.
	 *
	 * @param array<int, GoogleAdsApiException> $errors
	 */
	public static function mostRelevantError(array $errors): GoogleAdsApiException
	{
		usort($errors, fn (GoogleAdsApiException $a, GoogleAdsApiException $b) => self::errorWeight($b) <=> self::errorWeight($a));

		return $errors[0];
	}

	private static function errorWeight(GoogleAdsApiException $e): int
	{
		return match (true) {
			in_array($e->googleCode, ['CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION', 'ACTION_NOT_PERMITTED'], true) => 5,
			$e->googleCode !== null && str_starts_with($e->googleCode, 'DEVELOPER_TOKEN') => 4,
			$e->kind === GoogleAdsApiException::PERMISSION && $e->googleCode !== 'CUSTOMER_NOT_ENABLED' => 3,
			$e->googleCode === 'CUSTOMER_NOT_ENABLED' => 1,
			default => 2,
		};
	}

	private static function diagnosticRow(string $endpoint, GoogleAdsApiException $e): array
	{
		return ['endpoint' => $endpoint, 'status' => $e->httpStatus, 'code' => $e->googleCode ?? strtoupper($e->kind), 'message' => mb_substr($e->getMessage(), 0, 400), 'count' => null];
	}

	/**
	 * Zapisuje wybrane konto. Identyfikator musi pochodzić z listy kont dostępnych dla tej autoryzacji.
	 */
	public function selectAccount(Project $project, array $connection, string $customerId): array
	{
		$customerId = preg_replace('/\D/', '', $customerId);
		$account = null;

		foreach ($this->accessibleAccounts($connection, true) as $candidate) {
			if ($candidate['customer_id'] === $customerId) {
				$account = $candidate;
				break;
			}
		}

		if ($account === null) {
			throw new ValidationFailed(['customer_id' => 'To konto nie jest dostępne dla połączonego konta Google.']);
		}

		$this->connections->update((int) $connection['id'], [
			'customer_id' => $account['customer_id'],
			'customer_name' => mb_substr($account['name'], 0, 255),
			'login_customer_id' => $account['login_customer_id'],
			'currency' => $account['currency'],
			'time_zone' => $account['time_zone'],
			'status' => GoogleAdsConnectionRepository::STATUS_ACTIVE,
			'connected_at' => gmdate('Y-m-d H:i:s'),
			'last_error' => null,
			// Zmiana konta = zgoda na zapis musi zostać udzielona ponownie (dotyczyła poprzedniego konta).
			'write_enabled' => 0,
			'write_enabled_by' => null,
			'write_enabled_at' => null,
		]);

		return $account;
	}

	/**
	 * Ważny access token (odświeżany automatycznie). Unieważniona autoryzacja zmienia status połączenia.
	 */
	public function accessToken(array $connection): string
	{
		$stored = $this->connections->secrets((int) $connection['id']);

		if ($stored === null || $stored['refresh_token'] === null) {
			throw new GoogleAdsApiException('Połączenie nie ma zapisanej autoryzacji. Połącz konto ponownie.', GoogleAdsApiException::AUTH);
		}

		if ($stored['access_token'] !== null && $stored['access_token_expires_at'] !== null && strtotime($stored['access_token_expires_at'] . ' UTC') > time() + 60) {
			return $this->secrets->decrypt($stored['access_token']);
		}

		$refreshToken = $this->secrets->decrypt($stored['refresh_token']);

		try {
			$fresh = $this->oauth->refresh($refreshToken);
		} catch (GoogleAdsApiException $e) {
			if ($e->kind === GoogleAdsApiException::AUTH) {
				$this->connections->update((int) $connection['id'], [
					'status' => GoogleAdsConnectionRepository::STATUS_REAUTH,
					'access_token' => null,
					'last_error' => 'Autoryzacja Google wygasła lub została cofnięta. Połącz konto ponownie.',
				]);
			}

			throw $e;
		}

		$this->connections->update((int) $connection['id'], [
			// Ponowne zaszyfrowanie refresh tokenu bieżącym kluczem – po rotacji PP_ENCRYPTION_KEY stary klucz przestaje być potrzebny.
			'refresh_token' => $this->secrets->encrypt($refreshToken),
			'access_token' => $this->secrets->encrypt($fresh['access_token']),
			'access_token_expires_at' => gmdate('Y-m-d H:i:s', time() + $fresh['expires_in'] - 60),
		]);

		return $fresh['access_token'];
	}

	/**
	 * Rozłącza konto: unieważnia token w Google i usuwa zapisane tokeny. Zsynchronizowane dane zostają.
	 */
	public function disconnect(array $connection): void
	{
		$stored = $this->connections->secrets((int) $connection['id']);

		if ($stored !== null && $stored['refresh_token'] !== null) {
			try {
				$this->oauth->revoke($this->secrets->decrypt($stored['refresh_token']));
			} catch (\RuntimeException) {
				// Brak klucza lub uszkodzona wartość – i tak usuwamy tokeny lokalnie.
			}
		}

		$this->connections->update((int) $connection['id'], [
			'status' => GoogleAdsConnectionRepository::STATUS_DISCONNECTED,
			'refresh_token' => null,
			'access_token' => null,
			'access_token_expires_at' => null,
			'disconnected_at' => gmdate('Y-m-d H:i:s'),
			'write_enabled' => 0,
		]);

		delete_transient(self::accountsCacheKey((int) $connection['id']));
	}

	private function stateKey(string $state): string
	{
		return 'pp_oauth_' . hash('sha256', $state);
	}
}
