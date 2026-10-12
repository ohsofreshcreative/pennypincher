<?php

namespace App\PennyPincher\Domain\Audit;

/**
 * Wykryty problem: co, dlaczego (dowody), co zrobić i ile kosztuje w Audit Score.
 */
final class Finding
{
	/**
	 * @param array<int, array{0: string, 1: string}> $evidence pary [etykieta, wartość]
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $category,
		public readonly Severity $severity,
		public readonly string $title,
		public readonly string $description,
		public readonly array $evidence,
		public readonly string $recommendation,
		public readonly Dimension $dimension,
		public readonly float $penalty,
		public readonly ?string $entityType = null,
		public readonly ?int $entityId = null,
		public readonly string $variant = '',
	) {
	}

	/**
	 * Stały identyfikator problemu między audytami (do przenoszenia statusu „przejrzany / pominięty”).
	 */
	public function fingerprint(): string
	{
		return sha1(implode('|', [$this->ruleId, $this->entityType ?? '', $this->entityId ?? '', $this->variant]));
	}
}
