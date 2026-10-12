<?php

namespace App\PennyPincher\Domain\Import;

final class FieldDefinition
{
	/** @var array<int, string> */
	public readonly array $aliases;

	/**
	 * @param array<int, string> $aliases nagłówki PL/EN spotykane w eksportach Google Ads
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly FieldKind $kind,
		array $aliases,
		public readonly bool $required = false,
	) {
		$this->aliases = array_values(array_unique(array_map([HeaderNormalizer::class, 'key'], $aliases)));
	}
}
