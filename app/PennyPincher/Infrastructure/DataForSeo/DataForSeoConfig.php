<?php

namespace App\PennyPincher\Infrastructure\DataForSeo;

/**
 * Konfiguracja DataForSEO – wyłącznie ze stałych w wp-config.php lub zmiennych środowiskowych (nigdy z repozytorium
 * ani z bazy danych). Hasło API nie jest nigdzie wyświetlane ani logowane.
 */
class DataForSeoConfig
{
	public const API_BASE = 'https://api.dataforseo.com/v3/';

	public const SANDBOX_BASE = 'https://sandbox.dataforseo.com/v3/';

	public const HOSTS = ['api.dataforseo.com', 'sandbox.dataforseo.com'];

	/** Domyślny czas ważności zapisanych odpowiedzi (dni) – dane o wolumenie aktualizują się raz w miesiącu. */
	public const DEFAULT_CACHE_DAYS = 30;

	public function login(): ?string
	{
		return $this->value('PP_DATAFORSEO_LOGIN');
	}

	public function password(): ?string
	{
		return $this->value('PP_DATAFORSEO_PASSWORD');
	}

	/**
	 * Sandbox DataForSEO: darmowy, zwraca przykładowe (nieprawdziwe) dane – do testów integracji.
	 */
	public function sandbox(): bool
	{
		return in_array(strtolower((string) $this->value('PP_DATAFORSEO_SANDBOX')), ['1', 'true', 'yes', 'on'], true);
	}

	public function baseUrl(): string
	{
		return $this->sandbox() ? self::SANDBOX_BASE : self::API_BASE;
	}

	/**
	 * Miesięczny limit wydatków na organizację (USD). Brak = tylko limity projektów.
	 */
	public function organizationMonthlyLimit(): ?float
	{
		$value = $this->value('PP_DATAFORSEO_ORGANIZATION_MONTHLY_LIMIT');

		return $value !== null && is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
	}

	public function cacheDays(): int
	{
		$value = $this->value('PP_DATAFORSEO_CACHE_DAYS');

		return $value !== null && ctype_digit($value) && (int) $value > 0 ? min(365, (int) $value) : self::DEFAULT_CACHE_DAYS;
	}

	/**
	 * @return array<int, string>
	 */
	public function missing(): array
	{
		return array_values(array_filter([
			$this->login() === null ? 'PP_DATAFORSEO_LOGIN' : null,
			$this->password() === null ? 'PP_DATAFORSEO_PASSWORD' : null,
		]));
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
