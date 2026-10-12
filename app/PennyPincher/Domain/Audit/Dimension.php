<?php

namespace App\PennyPincher\Domain\Audit;

/**
 * Obszary Audit Score i ich maksymalna liczba punktów (suma = 100).
 */
enum Dimension: string
{
	case Configuration = 'configuration';
	case Measurement = 'measurement';
	case Risk = 'risk';
	case Data = 'data';

	public function label(): string
	{
		return match ($this) {
			self::Configuration => 'Poprawność konfiguracji',
			self::Measurement => 'Jakość pomiaru konwersji',
			self::Risk => 'Ryzyka i efektywność wydatków',
			self::Data => 'Kompletność i aktualność danych',
		};
	}

	public function maxPoints(): int
	{
		return match ($this) {
			self::Configuration => 25,
			self::Measurement => 30,
			self::Risk => 30,
			self::Data => 15,
		};
	}
}
