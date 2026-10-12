<?php

namespace App\PennyPincher\Domain\Metrics;

/**
 * Metryki pochodne. Zwracają null, gdy brakuje danych lub mianownik wynosi 0 –
 * interfejs pokazuje wtedy „—”, a nie fałszywe zero.
 */
final class MetricCalculator
{
	/**
	 * CTR w procentach: clicks / impressions × 100.
	 */
	public static function ctr(?int $clicks, ?int $impressions): ?float
	{
		if ($clicks === null || $impressions === null || $impressions === 0) {
			return null;
		}

		return $clicks / $impressions * 100;
	}

	/**
	 * Średni CPC w mikrojednostkach: cost / clicks.
	 */
	public static function averageCpc(?int $costMicros, ?int $clicks): ?int
	{
		if ($costMicros === null || $clicks === null || $clicks === 0) {
			return null;
		}

		return self::divideRounded($costMicros, $clicks);
	}

	/**
	 * Koszt konwersji w mikrojednostkach: cost / conversions.
	 * Konwersje mogą być ułamkowe (atrybucja oparta na danych).
	 */
	public static function costPerConversion(?int $costMicros, int|float|string|null $conversions): ?int
	{
		if ($costMicros === null || $conversions === null || $conversions === '') {
			return null;
		}

		$conversions = (float) $conversions;

		if ($conversions <= 0.0) {
			return null;
		}

		return (int) round($costMicros / $conversions);
	}

	/**
	 * Procent wykorzystania budżetu.
	 */
	public static function share(?int $part, ?int $whole): ?float
	{
		if ($part === null || $whole === null || $whole === 0) {
			return null;
		}

		return $part / $whole * 100;
	}

	private static function divideRounded(int $numerator, int $denominator): int
	{
		$quotient = intdiv($numerator, $denominator);
		$remainder = $numerator % $denominator;

		if (abs($remainder) * 2 >= abs($denominator)) {
			$quotient += ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
		}

		return $quotient;
	}
}
