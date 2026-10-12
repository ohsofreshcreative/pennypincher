<?php

namespace App\PennyPincher\Domain\Analysis;

/**
 * Ocena niepewności „braku konwersji”: jeśli typowy współczynnik konwersji wynosi p, to prawdopodobieństwo
 * zera konwersji przy n kliknięciach (przy założeniu niezależnych kliknięć) wynosi (1 − p)^n.
 * Im mniejsze, tym bardziej brak konwersji wynika z problemu, a nie z przypadku.
 */
final class Statistics
{
	public const HIGH = 'high';

	public const MEDIUM = 'medium';

	public const LOW = 'low';

	public const CONFIDENCE_LABELS = [self::HIGH => 'Wysoka', self::MEDIUM => 'Średnia', self::LOW => 'Niska'];

	public static function zeroConversionProbability(int $clicks, ?float $rate): ?float
	{
		if ($rate === null || $rate <= 0 || $clicks <= 0) {
			return null;
		}

		return (1 - min(0.99, $rate)) ** $clicks;
	}

	/**
	 * Pewność, że brak konwersji nie jest przypadkiem. Bez znanego współczynnika – najwyżej niska.
	 */
	public static function confidenceForZero(int $clicks, ?float $rate): string
	{
		$p = self::zeroConversionProbability($clicks, $rate);

		return match (true) {
			$p === null => self::LOW,
			$p < 0.05 => self::HIGH,
			$p < 0.2 => self::MEDIUM,
			default => self::LOW,
		};
	}

	/**
	 * Oczekiwana liczba konwersji przy danym współczynniku.
	 */
	public static function expected(int $clicks, ?float $rate): ?float
	{
		return $rate === null ? null : round($clicks * $rate, 2);
	}

	public static function rank(string $confidence): int
	{
		return match ($confidence) {
			self::HIGH => 3,
			self::MEDIUM => 2,
			default => 1,
		};
	}

	/**
	 * Średni miesięczny koszt (szacunek „run-rate”): koszt w okresie / dni × 30,4.
	 */
	public static function monthlyRunRate(int $costMicros, int $days): int
	{
		return $days > 0 ? (int) round($costMicros / $days * 30.4) : $costMicros;
	}
}
