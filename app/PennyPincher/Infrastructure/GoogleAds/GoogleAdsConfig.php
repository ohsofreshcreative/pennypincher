<?php

namespace App\PennyPincher\Infrastructure\GoogleAds;

use App\PennyPincher\Domain\GoogleAds\Changes\WriteMode;
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

	/**
	 * Tryb zapisu z konfiguracji serwera (PP_GOOGLE_ADS_WRITE_MODE): read_only | prepare_only (domyślnie) | write_enabled.
	 * Nieznana wartość = tylko odczyt. Interfejs aplikacji nie może tego zmienić.
	 */
	public function writeMode(): WriteMode
	{
		return WriteMode::fromConfig($this->value('PP_GOOGLE_ADS_WRITE_MODE'));
	}

	/**
	 * Czy wolno wysyłać do Google Ads żądania walidacyjne (validate_only = true – bez zapisu) w trybie PREPARE_ONLY.
	 * Domyślnie wyłączone (PP_GOOGLE_ADS_API_VALIDATION = 1, aby włączyć).
	 */
	public function apiValidationEnabled(): bool
	{
		return in_array(strtolower((string) $this->value('PP_GOOGLE_ADS_API_VALIDATION')), ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * Konta zablokowane dla zapisu w kodzie (żadne żądanie googleAds:mutate, także walidacyjne) – obecnie brak.
	 * Blokady konfigurowalne: PP_GOOGLE_ADS_BLOCKED_CUSTOMERS. O zapisie decyduje allowlista PP_GOOGLE_ADS_WRITE_CUSTOMERS.
	 *
	 * @var array<int, string>
	 */
	public const BLOCKED_WRITE_CUSTOMERS = [];

	/**
	 * Allowlista kont, na których wolno WYKONYWAĆ zmiany (PP_GOOGLE_ADS_WRITE_CUSTOMERS, np. "689-970-4535").
	 * Pusta = żadne konto. Sam wpis nie włącza zapisu (potrzebne: write_enabled, zgoda właściciela, wykonawca,
	 * zatwierdzenie i ręczne polecenie wdrożenia). Konta z listy blokad nigdy nie są dozwolone.
	 *
	 * @return array<int, string>
	 */
	public function writeCustomerAllowlist(): array
	{
		return self::customerList($this->value('PP_GOOGLE_ADS_WRITE_CUSTOMERS'));
	}

	/**
	 * @return array<int, string>
	 */
	public function blockedWriteCustomers(): array
	{
		return array_values(array_unique([...self::BLOCKED_WRITE_CUSTOMERS, ...self::customerList($this->value('PP_GOOGLE_ADS_BLOCKED_CUSTOMERS'))]));
	}

	public function isWriteBlockedCustomer(string $customerId): bool
	{
		return in_array(self::normalizeCustomer($customerId), $this->blockedWriteCustomers(), true);
	}

	public function isWriteAllowedCustomer(string $customerId): bool
	{
		$id = self::normalizeCustomer($customerId);

		return $id !== '' && ! $this->isWriteBlockedCustomer($id) && in_array($id, $this->writeCustomerAllowlist(), true);
	}

	/**
	 * Powód, dla którego na koncie nie wolno wykonywać zmian (null – konto dozwolone).
	 */
	public function writeCustomerBlocker(?string $customerId): ?string
	{
		$id = self::normalizeCustomer((string) $customerId);

		return match (true) {
			$id === '' => 'Brak identyfikatora konta Google Ads.',
			$this->isWriteBlockedCustomer($id) => 'Konto ' . self::formatCustomer($id) . ' jest zablokowane dla zapisu (PP_GOOGLE_ADS_BLOCKED_CUSTOMERS) – możliwy jest wyłącznie odczyt.',
			! in_array($id, $this->writeCustomerAllowlist(), true) => 'Konto ' . self::formatCustomer($id) . ' nie jest na allowliście zapisu (PP_GOOGLE_ADS_WRITE_CUSTOMERS w konfiguracji serwera).',
			default => null,
		};
	}

	/**
	 * Blokada emisji reklam (domyślnie WŁĄCZONA): nie wolno włączać kampanii, grup reklam i reklam, aktywować słów
	 * w aktywnych grupach ani zmieniać budżetów aktywnych kampanii. Wyłączenie wymaga PP_GOOGLE_ADS_ALLOW_SERVING = 1.
	 */
	public function servingAllowed(): bool
	{
		return in_array(strtolower((string) $this->value('PP_GOOGLE_ADS_ALLOW_SERVING')), ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * Sama liczba (bez myślników, spacji i zer wiodących) – „06899704535” to to samo konto co „6899704535”.
	 */
	public static function normalizeCustomer(string $customerId): string
	{
		return ltrim((string) preg_replace('/\D/', '', $customerId), '0');
	}

	public static function formatCustomer(string $customerId): string
	{
		$id = self::normalizeCustomer($customerId);

		return strlen($id) === 10 ? substr($id, 0, 3) . '-' . substr($id, 3, 3) . '-' . substr($id, 6) : $id;
	}

	/**
	 * @return array<int, string>
	 */
	private static function customerList(?string $value): array
	{
		$ids = array_map(fn ($part) => self::normalizeCustomer($part), preg_split('/[\s,;]+/', (string) $value) ?: []);

		return array_values(array_unique(array_filter($ids, fn ($id) => strlen($id) >= 6 && strlen($id) <= 10)));
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
