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
	) {
		parent::__construct($message);
	}

	public function isRetryable(): bool
	{
		return in_array($this->kind, [self::QUOTA, self::TRANSIENT], true);
	}

	/**
	 * Komunikat dla użytkownika: co zrobić + skrócony opis błędu z Google (bez tokenów, bez śladu stosu).
	 */
	public function userMessage(): string
	{
		$hint = match ($this->kind) {
			self::AUTH => 'Autoryzacja Google wygasła lub została cofnięta – połącz konto ponownie.',
			self::PERMISSION => 'Brak dostępu do konta lub niewystarczający poziom dostępu do Google Ads API.',
			self::QUOTA => 'Przekroczono limit zapytań Google Ads API – spróbuj później.',
			self::TRANSIENT => 'Chwilowy problem z połączeniem z Google – spróbuj ponownie za kilka minut.',
			self::CONFIG => 'Integracja nie jest poprawnie skonfigurowana na serwerze.',
			default => 'Google Ads API odrzuciło zapytanie.',
		};

		$detail = trim(mb_substr($this->getMessage(), 0, 300));

		return $detail === '' ? $hint : $hint . ' (' . rtrim($detail, '.') . ($this->requestId ? ', request ID: ' . $this->requestId : '') . ')';
	}
}
