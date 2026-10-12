<?php

namespace App\PennyPincher\Infrastructure\Import\Writers;

final class WriteResult
{
	public function __construct(
		public readonly int $metricRows,
		public readonly int $entities,
		public readonly int $mergedRows,
	) {
	}
}
