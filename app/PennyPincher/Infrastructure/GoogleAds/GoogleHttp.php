<?php

namespace App\PennyPincher\Infrastructure\GoogleAds;

/**
 * Wywołania HTTP do oficjalnych endpointów Google przez WordPress HTTP API (wp_remote_request).
 * W testach odpowiedzi są podstawiane filtrem `pre_http_request`.
 *
 * Tylko hosty Google – żaden sekret nie jest wysyłany gdzie indziej.
 */
class GoogleHttp
{
	private const ALLOWED_HOSTS = ['oauth2.googleapis.com', 'googleads.googleapis.com', 'accounts.google.com'];

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, body: mixed, headers: array<string, string>}
	 * @throws GoogleAdsApiException
	 */
	public function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 60): array
	{
		$host = (string) wp_parse_url($url, PHP_URL_HOST);

		if (! in_array($host, self::ALLOWED_HOSTS, true) || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
			throw new GoogleAdsApiException('Niedozwolony adres żądania do Google.', GoogleAdsApiException::CONFIG);
		}

		$response = wp_remote_request($url, [
			'method' => $method,
			'headers' => $headers,
			'body' => $body,
			'timeout' => $timeout,
			'redirection' => 0,
			'sslverify' => true,
			'user-agent' => 'PennyPincher/1.0 (+WordPress)',
		]);

		if (is_wp_error($response)) {
			throw new GoogleAdsApiException('Brak połączenia z Google: ' . $response->get_error_message(), GoogleAdsApiException::TRANSIENT);
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$raw = (string) wp_remote_retrieve_body($response);
		$decoded = json_decode($raw, true);

		$headersOut = [];

		foreach ((array) wp_remote_retrieve_headers($response) as $name => $value) {
			$headersOut[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
		}

		return ['status' => $status, 'body' => $decoded ?? $raw, 'headers' => $headersOut];
	}

	/**
	 * Zamienia odpowiedź z błędem (OAuth lub Google Ads API) na sklasyfikowany wyjątek.
	 */
	public static function errorFromResponse(array $response, string $context): GoogleAdsApiException
	{
		$status = $response['status'];
		$body = $response['body'];

		// searchStream zwraca błędy jako tablicę obiektów.
		if (is_array($body) && array_is_list($body) && isset($body[0]['error'])) {
			$body = $body[0];
		}

		$error = is_array($body) ? ($body['error'] ?? null) : null;
		$requestId = $response['headers']['request-id'] ?? null;

		// Błędy OAuth: {"error": "invalid_grant", "error_description": "..."}
		if (is_string($error)) {
			$kind = match ($error) {
				'invalid_grant', 'unauthorized_client', 'invalid_token' => GoogleAdsApiException::AUTH,
				'invalid_client' => GoogleAdsApiException::CONFIG,
				'temporarily_unavailable', 'server_error' => GoogleAdsApiException::TRANSIENT,
				default => GoogleAdsApiException::INVALID,
			};

			$description = is_array($body) ? (string) ($body['error_description'] ?? '') : '';

			return new GoogleAdsApiException(trim("{$context}: {$error}. {$description}"), $kind, $status, $requestId, null, $error);
		}

		$apiStatus = is_array($error) ? (string) ($error['status'] ?? '') : '';
		$message = is_array($error) ? (string) ($error['message'] ?? '') : '';
		$details = [];
		$retryAfter = null;
		$googleCode = null;

		foreach ((array) ($error['details'] ?? []) as $detail) {
			if (! empty($detail['requestId'])) {
				$requestId = (string) $detail['requestId'];
			}

			foreach ((array) ($detail['errors'] ?? []) as $item) {
				$code = is_array($item['errorCode'] ?? null) ? implode('.', array_map(fn ($k, $v) => "{$k}:{$v}", array_keys($item['errorCode']), $item['errorCode'])) : '';
				$details[] = trim($code . ' ' . ($item['message'] ?? ''));

				if ($googleCode === null && is_array($item['errorCode'] ?? null) && $item['errorCode'] !== []) {
					$googleCode = (string) array_values($item['errorCode'])[0];
				}

				if (isset($item['details']['quotaErrorDetails']['retryDelay'])) {
					$retryAfter = (int) rtrim((string) $item['details']['quotaErrorDetails']['retryDelay'], 's');
				}
			}
		}

		if ($retryAfter === null && isset($response['headers']['retry-after']) && is_numeric($response['headers']['retry-after'])) {
			$retryAfter = (int) $response['headers']['retry-after'];
		}

		$kind = match (true) {
			in_array($googleCode, GoogleAdsApiException::PERMISSION_CODES, true) => GoogleAdsApiException::PERMISSION,
			$status === 401 || $apiStatus === 'UNAUTHENTICATED' => GoogleAdsApiException::AUTH,
			$status === 429 || $apiStatus === 'RESOURCE_EXHAUSTED' => GoogleAdsApiException::QUOTA,
			$status === 403 || $apiStatus === 'PERMISSION_DENIED' => GoogleAdsApiException::PERMISSION,
			$status >= 500 || in_array($apiStatus, ['UNAVAILABLE', 'INTERNAL', 'DEADLINE_EXCEEDED', 'ABORTED'], true) => GoogleAdsApiException::TRANSIENT,
			default => GoogleAdsApiException::INVALID,
		};

		$text = trim($context . ': ' . ($apiStatus !== '' ? "{$apiStatus} – " : '') . ($message !== '' ? $message : "HTTP {$status}"));

		if ($details !== []) {
			$text .= ' (' . implode('; ', array_slice($details, 0, 3)) . ')';
		}

		return new GoogleAdsApiException($text, $kind, $status, $requestId, $retryAfter, $googleCode);
	}
}
