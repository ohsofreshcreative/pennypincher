<?php

namespace App\PennyPincher\Domain\Support;

use RuntimeException;

class ValidationFailed extends RuntimeException
{
	/**
	 * @param array<string, string> $errors pole => komunikat
	 */
	public function __construct(public readonly array $errors, string $message = 'Formularz zawiera błędy.')
	{
		parent::__construct($message);
	}
}
