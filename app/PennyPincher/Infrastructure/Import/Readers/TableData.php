<?php

namespace App\PennyPincher\Infrastructure\Import\Readers;

/**
 * Surowa tabela odczytana z pliku: wiersze komórek (string|null) bez interpretacji.
 */
final class TableData
{
	/**
	 * @param array<int, array<int, string|null>> $rows
	 */
	public function __construct(
		public readonly array $rows,
		public readonly string $encoding,
		public readonly ?string $delimiter,
		public readonly string $format,
	) {
	}
}
