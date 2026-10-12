<?php

namespace App\PennyPincher\Infrastructure\GoogleAds;

/**
 * OAuth 2.0 (Authorization Code + PKCE) dla aplikacji webowej Google.
 */
class OAuthClient
{
	public function __construct(private GoogleAdsConfig $config, private GoogleHttp $http)
	{
	}

	public function authorizationUrl(string $state, string $codeVerifier): string
	{
		$this->assertConfigured();

		return GoogleAdsConfig::AUTH_URL . '?' . http_build_query([
			'client_id' => $this->config->clientId(),
			'redirect_uri' => $this->config->redirectUri(),
			'response_type' => 'code',
			'scope' => implode(' ', GoogleAdsConfig::SCOPES),
			'access_type' => 'offline',
			'prompt' => 'consent',
			'include_granted_scopes' => 'true',
			'state' => $state,
			'code_challenge' => self::codeChallenge($codeVerifier),
			'code_challenge_method' => 'S256',
		], '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string, email: ?string}
	 */
	public function exchangeCode(string $code, string $codeVerifier): array
	{
		$this->assertConfigured();

		$response = $this->http->request('POST', GoogleAdsConfig::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], [
			'code' => $code,
			'client_id' => $this->config->clientId(),
			'client_secret' => $this->config->clientSecret(),
			'redirect_uri' => $this->config->redirectUri(),
			'grant_type' => 'authorization_code',
			'code_verifier' => $codeVerifier,
		]);

		if ($response['status'] !== 200 || ! is_array($response['body']) || empty($response['body']['access_token'])) {
			throw GoogleHttp::errorFromResponse($response, 'Wymiana kodu autoryzacji');
		}

		$body = $response['body'];

		return [
			'access_token' => (string) $body['access_token'],
			'refresh_token' => isset($body['refresh_token']) ? (string) $body['refresh_token'] : null,
			'expires_in' => (int) ($body['expires_in'] ?? 3600),
			'scope' => (string) ($body['scope'] ?? ''),
			'email' => self::emailFromIdToken($body['id_token'] ?? null),
		];
	}

	/**
	 * @return array{access_token: string, expires_in: int}
	 */
	public function refresh(string $refreshToken): array
	{
		$this->assertConfigured();

		$response = $this->http->request('POST', GoogleAdsConfig::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], [
			'client_id' => $this->config->clientId(),
			'client_secret' => $this->config->clientSecret(),
			'refresh_token' => $refreshToken,
			'grant_type' => 'refresh_token',
		]);

		if ($response['status'] !== 200 || ! is_array($response['body']) || empty($response['body']['access_token'])) {
			throw GoogleHttp::errorFromResponse($response, 'Odświeżenie tokenu');
		}

		return ['access_token' => (string) $response['body']['access_token'], 'expires_in' => (int) ($response['body']['expires_in'] ?? 3600)];
	}

	/**
	 * Unieważnia token po stronie Google (przy rozłączaniu). Błąd nie blokuje rozłączenia.
	 */
	public function revoke(string $token): bool
	{
		try {
			$response = $this->http->request('POST', GoogleAdsConfig::REVOKE_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], ['token' => $token], 15);

			return $response['status'] === 200;
		} catch (GoogleAdsApiException) {
			return false;
		}
	}

	public static function codeChallenge(string $verifier): string
	{
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	/**
	 * Adres e-mail konta Google z id_token. Token przyszedł bezpośrednio z endpointu tokenów Google przez TLS,
	 * więc zgodnie ze specyfikacją OpenID Connect nie weryfikujemy podpisu; używamy go tylko do wyświetlenia.
	 */
	public static function emailFromIdToken(mixed $idToken): ?string
	{
		if (! is_string($idToken) || substr_count($idToken, '.') !== 2) {
			return null;
		}

		$payload = json_decode((string) base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/')), true);
		$email = is_array($payload) ? ($payload['email'] ?? null) : null;

		return is_string($email) && is_email($email) ? $email : null;
	}

	private function assertConfigured(): void
	{
		if ($this->config->clientId() === null || $this->config->clientSecret() === null) {
			throw new GoogleAdsApiException('Integracja Google Ads nie jest skonfigurowana na serwerze (brak PP_GOOGLE_ADS_CLIENT_ID / PP_GOOGLE_ADS_CLIENT_SECRET).', GoogleAdsApiException::CONFIG);
		}
	}
}
