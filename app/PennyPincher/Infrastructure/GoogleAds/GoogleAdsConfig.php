<?php

namespace App\PennyPincher\Infrastructure\GoogleAds;

use App\PennyPincher\Infrastructure\Security\SecretBox;
use App\PennyPincher\Support\AppUrl;

/**
 * Konfiguracja integracji z Google Ads API – wyłącznie ze stałych w wp-config.php lub zmiennych środowiskowych.
 * Żadna z tych wartości nie trafia do repozytorium ani do bazy danych.
 */
class GoogleAdsConfig
{
	public const DEFAULT_API_VERSION = 'v25';

	public const SCOPES = ['https://www.googleapis.com/auth/adwords', 'openid', 'email'];

	public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	public const API_HOST = 'https://googleads.googleapis.com';

	public function clientId(): ?string
	{
		return $this->value('PP_GOOGLE_ADS_CLIENT_ID');
	}

	public function clientSecret(): ?string
	{
		return $this->value('PP_GOOGLE_ADS_CLIENT_SECRET');
	}

	/**
	 * Od 9.09.2026 Google nie wydaje nowych developer tokenów (dostęp wynika z poziomu dostępu projektu Cloud),
	 * a serwery API ignorują nagłówek. Wysyłamy go tylko, jeśli jest skonfigurowany (starsze konfiguracje).
	 */
	public function developerToken(): ?string
	{
		return $this->value('PP_GOOGLE_ADS_DEVELOPER_TOKEN');
	}

	/**
	 * Domyślne konto menedżera (MCC) dla nagłówka login-customer-id – opcjonalne.
	 */
	public function defaultLoginCustomerId(): ?string
	{
		$value = $this->value('PP_GOOGLE_ADS_LOGIN_CUSTOMER_ID');

		return $value === null ? null : preg_replace('/\D/', '', $value);
	}

	public function apiVersion(): string
	{
		$version = $this->value('PP_GOOGLE_ADS_API_VERSION') ?? self::DEFAULT_API_VERSION;

		return preg_match('/^v\d{2}$/', $version) ? $version : self::DEFAULT_API_VERSION;
	}

	public function redirectUri(): string
	{
		return AppUrl::to('google-ads/callback');
	}

	/**
	 * @return array<int, string> brakujące elementy konfiguracji (puste = gotowe)
	 */
	public function missing(): array
	{
		$missing = [];

		if ($this->clientId() === null) {
			$missing[] = 'PP_GOOGLE_ADS_CLIENT_ID';
		}

		if ($this->clientSecret() === null) {
			$missing[] = 'PP_GOOGLE_ADS_CLIENT_SECRET';
		}

		try {
			if (! SecretBox::isConfigured()) {
				$missing[] = 'PP_ENCRYPTION_KEY';
			}
		} catch (\RuntimeException) {
			$missing[] = 'PP_ENCRYPTION_KEY (nieprawidłowy format)';
		}

		return $missing;
	}

	public function isConfigured(): bool
	{
		return $this->missing() === [];
	}

	private function value(string $name): ?string
	{
		$value = defined($name) ? (string) constant($name) : (string) getenv($name);
		$value = trim($value);

		return $value === '' ? null : $value;
	}
}
