<?php

namespace App\PennyPincher\Domain\Audit;

enum Severity: string
{
	case Critical = 'critical';
	case Warning = 'warning';
	case Info = 'info';

	public function label(): string
	{
		return match ($this) {
			self::Critical => 'Krytyczny',
			self::Warning => 'Ostrzeżenie',
			self::Info => 'Informacja',
		};
	}

	public function rank(): int
	{
		return match ($this) {
			self::Critical => 3,
			self::Warning => 2,
			self::Info => 1,
		};
	}
}
