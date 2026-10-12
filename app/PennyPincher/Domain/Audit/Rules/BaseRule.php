<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Finding;
use App\PennyPincher\Domain\Audit\Rule;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

abstract class BaseRule implements Rule
{
	/**
	 * Kara punktowa wg ważności – ta sama skala dla wszystkich reguł (patrz docs/ARCHITECTURE.md).
	 */
	public const PENALTIES = ['critical' => 10.0, 'warning' => 4.0, 'info' => 1.0];

	/**
	 * Nowe reguły mogą zamiast metod zadeklarować stałe ID, NAME, DESCRIPTION, CATEGORY i DIMENSION.
	 */
	public function id(): string
	{
		return static::ID;
	}

	public function name(): string
	{
		return static::NAME;
	}

	public function description(): string
	{
		return static::DESCRIPTION;
	}

	public function category(): string
	{
		return static::CATEGORY;
	}

	protected function dimension(): Dimension
	{
		return static::DIMENSION;
	}

	protected function finding(
		Severity $severity,
		string $title,
		string $description,
		array $evidence,
		string $recommendation,
		?string $entityType = null,
		?int $entityId = null,
		string $variant = '',
		?float $penalty = null,
	): Finding {
		return new Finding(
			ruleId: $this->id(),
			category: $this->category(),
			severity: $severity,
			title: $title,
			description: $description,
			evidence: $evidence,
			recommendation: $recommendation,
			dimension: $this->dimension(),
			penalty: $penalty ?? self::PENALTIES[$severity->value],
			entityType: $entityType,
			entityId: $entityId,
			variant: $variant,
		);
	}

	protected static function money(?int $micros, string $currency): string
	{
		return Format::money($micros, $currency);
	}

	protected static function percent(?float $value): string
	{
		return $value === null ? '—' : number_format($value, 1, ',', ' ') . ' %';
	}

	protected static function change(float $previous, float $current): ?float
	{
		return $previous > 0 ? ($current - $previous) / $previous * 100 : null;
	}
}
