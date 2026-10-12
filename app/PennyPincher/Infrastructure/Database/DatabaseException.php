<?php

namespace App\PennyPincher\Infrastructure\Database;

use RuntimeException;

class DatabaseException extends RuntimeException
{
	public function __construct(string $message, private ?string $query = null)
	{
		parent::__construct('Błąd bazy danych: ' . $message);
	}

	/**
	 * Zapytanie trafia tylko do logów, nigdy do interfejsu.
	 */
	public function query(): ?string
	{
		return $this->query;
	}
}
