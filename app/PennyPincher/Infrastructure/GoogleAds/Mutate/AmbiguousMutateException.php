<?php

namespace App\PennyPincher\Infrastructure\GoogleAds\Mutate;

use RuntimeException;

/**
 * Zmiany zostały wysłane, ale nie wiadomo, czy Google Ads je przyjął (brak odpowiedzi, przekroczenie czasu,
 * błąd serwera). Operacje dostają status „wymaga uzgodnienia” – ponowienie dopiero po odczycie rzeczywistego stanu.
 */
class AmbiguousMutateException extends RuntimeException
{
	public function __construct(string $message, public readonly ?string $requestId = null)
	{
		parent::__construct($message);
	}
}
