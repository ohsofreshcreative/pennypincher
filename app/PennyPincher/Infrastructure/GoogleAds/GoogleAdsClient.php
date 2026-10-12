<?php

namespace App\PennyPincher\Infrastructure\GoogleAds;

/**
 * Klient REST Google Ads API (tylko odczyt: listAccessibleCustomers i googleAds:searchStream z GAQL).
 * Aplikacja nie wykonuje żadnych operacji zmieniających konto reklamowe.
 */
class GoogleAdsClient
{
	public function __construct(private GoogleAdsConfig $config, private GoogleHttp $http)
	{
	}

	public function apiVersion(): string
	{
		return $this->config->apiVersion();
	}

	/**
	 * @return array<int, string> identyfikatory kont dostępnych dla zalogowanego użytkownika Google
	 */
	public function listAccessibleCustomers(string $accessToken): array
	{
		$response = $this->http->request(
			'GET',
			sprintf('%s/%s/customers:listAccessibleCustomers', GoogleAdsConfig::API_HOST, $this->config->apiVersion()),
			$this->headers($accessToken, null, false),
			null,
			30
		);

		if ($response['status'] !== 200 || ! is_array($response['body'])) {
			throw GoogleHttp::errorFromResponse($response, 'Lista kont Google Ads');
		}

		return array_values(array_filter(array_map(
			fn ($name) => preg_replace('/^customers\//', '', (string) $name),
			(array) ($response['body']['resourceNames'] ?? [])
		), fn ($id) => preg_match('/^\d+$/', $id)));
	}

	/**
	 * Wykonuje zapytanie GAQL i zwraca wszystkie wiersze (scalone partie searchStream).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function search(string $accessToken, string $customerId, string $query, ?string $loginCustomerId = null): array
	{
		$customerId = preg_replace('/\D/', '', $customerId);

		$response = $this->http->request(
			'POST',
			sprintf('%s/%s/customers/%s/googleAds:searchStream', GoogleAdsConfig::API_HOST, $this->config->apiVersion(), $customerId),
			$this->headers($accessToken, $loginCustomerId) + ['Content-Type' => 'application/json'],
			wp_json_encode(['query' => $query]),
			120
		);

		if ($response['status'] !== 200 || ! is_array($response['body'])) {
			throw GoogleHttp::errorFromResponse($response, 'Zapytanie Google Ads');
		}

		$rows = [];

		foreach ($response['body'] as $batch) {
			if (isset($batch['error'])) {
				throw GoogleHttp::errorFromResponse(['status' => 500, 'body' => [$batch], 'headers' => $response['headers']], 'Zapytanie Google Ads');
			}

			foreach ((array) ($batch['results'] ?? []) as $row) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(string $accessToken, ?string $loginCustomerId, bool $useDefaultLogin = true): array
	{
		$headers = ['Authorization' => 'Bearer ' . $accessToken];

		if ($this->config->developerToken() !== null) {
			$headers['developer-token'] = $this->config->developerToken();
		}

		$login = $loginCustomerId ?: ($useDefaultLogin ? $this->config->defaultLoginCustomerId() : null);

		if ($login) {
			$headers['login-customer-id'] = preg_replace('/\D/', '', $login);
		}

		return $headers;
	}
}
