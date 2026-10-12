<?php

namespace App\PennyPincher\Infrastructure\DataForSeo;

use RuntimeException;

/**
 * Sklasyfikowany błąd DataForSEO. Komunikat nigdy nie zawiera danych logowania.
 */
class DataForSeoException extends RuntimeException
{
	public const CONFIG = 'config';

	public const AUTH = 'auth';

	public const FUNDS = 'funds';

	public const COST_LIMIT = 'cost_limit';

	public const RATE = 'rate';

	public const INVALID = 'invalid';

	public const TRANSIENT = 'transient';

	public const RESPONSE = 'response';

	public const BUDGET = 'budget';

	public const MESSAGES = [
		self::CONFIG => 'DataForSEO nie jest skonfigurowane (brak danych logowania w wp-config.php).',
		self::AUTH => 'DataForSEO odrzuciło dane logowania. Sprawdź PP_DATAFORSEO_LOGIN i PP_DATAFORSEO_PASSWORD (hasło API z panelu DataForSEO, nie hasło do konta).',
		self::FUNDS => 'Brak środków na koncie DataForSEO. Doładuj saldo w panelu DataForSEO.',
		self::COST_LIMIT => 'Przekroczony limit kosztów ustawiony w panelu DataForSEO.',
		self::RATE => 'Przekroczony limit zapytań DataForSEO – zadanie zostanie ponowione później.',
		self::INVALID => 'DataForSEO odrzuciło parametry zapytania.',
		self::TRANSIENT => 'DataForSEO chwilowo nie odpowiada – zadanie zostanie ponowione później.',
		self::RESPONSE => 'Nieprawidłowa odpowiedź DataForSEO.',
		self::BUDGET => 'Zapytanie przekroczyłoby miesięczny limit wydatków na DataForSEO.',
	];

	public function __construct(
		string $message,
		public readonly string $kind,
		public readonly ?int $apiCode = null,
		public readonly ?int $httpStatus = null,
	) {
		parent::__construct($message);
	}

	public static function of(string $kind, ?int $apiCode = null, ?int $httpStatus = null, ?string $detail = null): self
	{
		$message = self::MESSAGES[$kind] ?? self::MESSAGES[self::RESPONSE];

		if ($detail !== null && $detail !== '') {
			$message .= ' (' . mb_substr($detail, 0, 200) . ')';
		}

		return new self($message, $kind, $apiCode, $httpStatus);
	}

	public function retryable(): bool
	{
		return in_array($this->kind, [self::RATE, self::TRANSIENT], true);
	}

	public function code(): string
	{
		return $this->apiCode !== null ? (string) $this->apiCode : ($this->httpStatus !== null ? 'http_' . $this->httpStatus : $this->kind);
	}
}
