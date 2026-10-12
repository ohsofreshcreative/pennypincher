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
	 * @return array<int, array{customer_id: string, name: string, currency: ?string, time_zone: ?string, login_customer_id: ?string, test: bool}>
	 */
	public function accessibleAccounts(array $connection, bool $refresh = false): array
	{
		$cacheKey = 'pp_gads_accounts_' . (int) $connection['id'];
		$cached = $refresh ? false : get_transient($cacheKey);

		if (is_array($cached)) {
			return $cached;
		}

		$token = $this->accessToken($connection);
		$accounts = [];

		foreach ($this->client->listAccessibleCustomers($token) as $customerId) {
			try {
				$rows = $this->client->search($token, $customerId, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.manager, customer.test_account FROM customer', $customerId);
			} catch (GoogleAdsApiException $e) {
				if ($e->kind === GoogleAdsApiException::AUTH) {
					throw $e;
				}

				continue; // np. konto zawieszone lub bez uprawnień – pomijamy
			}

			$customer = $rows[0]['customer'] ?? null;

			if (! $customer) {
				continue;
			}

			if (! empty($customer['manager'])) {
				// Konto menedżera: pokazujemy konta klientów (bez dalszych menedżerów).
				try {
					$clients = $this->client->search($token, $customerId, 'SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.time_zone, customer_client.manager, customer_client.test_account, customer_client.status FROM customer_client WHERE customer_client.level <= 1 AND customer_client.manager = FALSE', $customerId);
				} catch (GoogleAdsApiException $e) {
					if ($e->kind === GoogleAdsApiException::AUTH) {
						throw $e;
					}

					continue;
				}

				foreach ($clients as $row) {
					$client = $row['customerClient'] ?? [];

					if (($client['status'] ?? 'ENABLED') !== 'ENABLED' || empty($client['id'])) {
						continue;
					}

					$accounts[(string) $client['id']] ??= [
						'customer_id' => (string) $client['id'],
						'name' => (string) ($client['descriptiveName'] ?? 'Konto ' . $client['id']),
						'currency' => $client['currencyCode'] ?? null,
						'time_zone' => $client['timeZone'] ?? null,
						'login_customer_id' => $customerId,
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
		set_transient($cacheKey, $accounts, 10 * MINUTE_IN_SECONDS);

		return $accounts;
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
		]);

		delete_transient('pp_gads_accounts_' . (int) $connection['id']);
	}

	private function stateKey(string $state): string
	{
		return 'pp_oauth_' . hash('sha256', $state);
	}
}
