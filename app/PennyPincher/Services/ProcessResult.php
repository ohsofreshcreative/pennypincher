<?php

namespace App\PennyPincher\Services;

final class ProcessResult
{
	/**
	 * @param array<int, string> $errors
	 */
	public function __construct(
		public readonly bool $success,
		public readonly array $errors = [],
		public readonly int $errorCount = 0,
		public readonly int $imported = 0,
		public readonly int $skipped = 0,
	) {
	}
}
