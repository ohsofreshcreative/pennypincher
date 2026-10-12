<?php

namespace App\PennyPincher\Infrastructure\Import\Writers;

final class ImportContext
{
	/**
	 * @param bool $daily true = raport z kolumną Dzień (każdy wiersz to jeden dzień)
	 * @param string|null $dateFrom zakres raportu (dla raportów okresowych wymagany)
	 */
	public function __construct(
		public readonly int $projectId,
		public readonly int $importId,
		public readonly bool $daily,
		public readonly ?string $dateFrom,
		public readonly ?string $dateTo,
		public readonly string $currency,
	) {
	}

	public function granularityFor(string $from, string $to): string
	{
		return $this->daily ? 'day' : 'period';
	}
}
