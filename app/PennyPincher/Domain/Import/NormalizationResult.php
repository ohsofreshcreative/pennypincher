<?php

namespace App\PennyPincher\Domain\Import;

final class NormalizationResult
{
	/** @var array<int, array<string, mixed>> */
	public array $rows = [];

	/** @var array<int, string> */
	public array $errors = [];

	public int $errorCount = 0;

	public int $skipped = 0;

	public int $totalRows = 0;

	public ?string $minDate = null;

	public ?string $maxDate = null;

	/** @var array<string, true> */
	public array $currencies = [];

	public function addError(string $message, int $limit): void
	{
		$this->errorCount++;

		if (count($this->errors) < $limit) {
			$this->errors[] = $message;
		}
	}

	public function hasErrors(): bool
	{
		return $this->errorCount > 0;
	}
}
