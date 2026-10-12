<?php

namespace App\PennyPincher\Infrastructure\GoogleAds;

use RuntimeException;

/**
 * Błąd Google Ads API / OAuth sklasyfikowany pod kątem dalszego postępowania.
 * Komunikat nigdy nie zawiera tokenów.
 */
class GoogleAdsApiException extends RuntimeException
{
	/** Brak lub unieważniona autoryzacja – użytkownik musi połączyć konto ponownie. */
	public const AUTH = 'auth';

	/** Brak dostępu do konta lub poziom dostępu projektu Cloud nie pozwala na zapytanie. */
	public const PERMISSION = 'permission';

	/** Limit zapytań – ponów później. */
	public const QUOTA = 'quota';

	/** Chwilowy błąd sieci lub serwera – ponów z opóźnieniem. */
	public const TRANSIENT = 'transient';

	/** Błędne zapytanie / niezgodność wersji – ponawianie nic nie da. */
	public const INVALID = 'invalid';

	/** Brak konfiguracji po stronie serwera. */
	public const CONFIG = 'config';

	public function __construct(
		string $message,
		public readonly string $kind,
		public readonly ?int $httpStatus = null,
		public readonly ?string $requestId = null,
		public readonly ?int $retryAfterSeconds = null,
		public readonly ?string $googleCode = null,
	) {
		parent::__construct($message);
	}

	public function isRetryable(): bool
	{
		return in_array($this->kind, [self::QUOTA, self::TRANSIENT], true);
	}

	/**
	 * Kody błędów Google Ads, które oznaczają problem z uprawnieniami, niezależnie od statusu HTTP.
	 */
	public const PERMISSION_CODES = [
		'CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION',
		'USER_PERMISSION_DENIED',
		'CUSTOMER_NOT_ENABLED',
		'DEVELOPER_TOKEN_NOT_APPROVED',
		'DEVELOPER_TOKEN_PROHIBITED',
		'PROJECT_DISABLED',
		'ACTION_NOT_PERMITTED',
		'NOT_ALLOWLISTED',
	];

	/**
	 * Komunikat dla użytkownika: co zrobić + skrócony opis błędu z Google (bez tokenów, bez śladu stosu).
	 */
	public function userMessage(): string
	{
		$hint = match (true) {
			$this->googleCode === 'CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION', $this->googleCode === 'ACTION_NOT_PERMITTED' => 'Projekt Google Cloud ma dostęp tylko do kont testowych (poziom „Test”). W Google Cloud Console → Google Ads API sprawdź, czy poziom Explorer/Basic jest przyznany temu samemu projektowi, w którym utworzono klienta OAuth.',
			$this->googleCode === 'USER_PERMISSION_DENIED' => 'Konto Google nie ma uprawnień do tego konta reklamowego (albo dostęp idzie przez konto menedżera, którego nie wybrano).',
			$this->googleCode === 'CUSTOMER_NOT_ENABLED' => 'Konto reklamowe jest nieaktywne, zawieszone albo anulowane.',
			$this->googleCode !== null && str_starts_with($this->googleCode, 'DEVELOPER_TOKEN') => 'Google odrzucił developer token – usuń stałą PP_GOOGLE_ADS_DEVELOPER_TOKEN (nie jest już potrzebna).',
			$this->kind === self::AUTH => 'Błąd autoryzacji Google – autoryzacja wygasła lub została cofnięta. Połącz konto ponownie.',
			$this->kind === self::PERMISSION => 'Brak uprawnień do Google Ads API dla tego konta lub projektu Google Cloud.',
			$this->kind === self::QUOTA => 'Przekroczono limit zapytań Google Ads API – spróbuj później.',
			$this->kind === self::TRANSIENT => 'Chwilowy problem z połączeniem z Google – spróbuj ponownie za kilka minut.',
			$this->kind === self::CONFIG => 'Integracja nie jest poprawnie skonfigurowana na serwerze.',
			default => 'Błąd Google Ads API – zapytanie zostało odrzucone.',
		};

		$detail = trim(mb_substr($this->getMessage(), 0, 300));

		return $detail === '' ? $hint : $hint . ' (' . rtrim($detail, '.') . ($this->requestId ? ', request ID: ' . $this->requestId : '') . ')';
	}
}
