<?php

namespace App\PennyPincher\Support;

use App\PennyPincher\Domain\Metrics\Money;

/**
 * Formatowanie liczb w interfejsie (polski zapis). Brak danych zawsze jako „—”.
 */
final class Format
{
	public const EMPTY = '—';

	public static function money(?int $micros, string $currency = 'PLN', int $decimals = 2): string
	{
		if ($micros === null) {
			return self::EMPTY;
		}

		return self::groupDecimal(Money::toDecimal($micros, $decimals)) . "\u{00A0}" . self::currencySymbol($currency);
	}

	public static function moneyCompact(?int $micros, string $currency = 'PLN'): string
	{
		if ($micros === null) {
			return self::EMPTY;
		}

		return abs($micros) >= 1000 * Money::MICROS ? self::money($micros, $currency, 0) : self::money($micros, $currency);
	}

	public static function number(int|float|string|null $value, int $decimals = 0): string
	{
		if ($value === null || $value === '') {
			return self::EMPTY;
		}

		return number_format((float) $value, $decimals, ',', "\u{00A0}");
	}

	/**
	 * Konwersje: bez zbędnych miejsc po przecinku („3”, „2,5”).
	 */
	public static function conversions(int|float|string|null $value): string
	{
		if ($value === null || $value === '') {
			return self::EMPTY;
		}

		$float = (float) $value;
		$decimals = abs($float - round($float)) < 0.005 ? 0 : 2;

		return self::number($float, $decimals);
	}

	public static function percent(?float $value, int $decimals = 2): string
	{
		return $value === null ? self::EMPTY : number_format($value, $decimals, ',', "\u{00A0}") . "\u{00A0}%";
	}

	public static function date(?string $date): string
	{
		if (! $date) {
			return self::EMPTY;
		}

		$timestamp = strtotime($date);

		return $timestamp ? date('d.m.Y', $timestamp) : self::EMPTY;
	}

	public static function datetime(?string $gmt): string
	{
		if (! $gmt) {
			return self::EMPTY;
		}

		return get_date_from_gmt($gmt, 'd.m.Y H:i');
	}

	public static function period(?string $from, ?string $to): string
	{
		if (! $from || ! $to) {
			return self::EMPTY;
		}

		return $from === $to ? self::date($from) : self::date($from) . ' – ' . self::date($to);
	}

	/**
	 * Wartość kwoty do pola formularza (bez separatorów tysięcy).
	 */
	public static function moneyInput(?int $micros): string
	{
		if ($micros === null) {
			return '';
		}

		$decimal = Money::toDecimal($micros, 2);

		return str_replace('.', ',', preg_replace('/\.00$/', '', $decimal));
	}

	public static function currencySymbol(string $currency): string
	{
		return match ($currency) {
			'PLN' => 'zł',
			'EUR' => '€',
			'USD' => '$',
			'GBP' => '£',
			default => $currency,
		};
	}

	/**
	 * Polska odmiana liczebnika: plural(1, 'fraza', 'frazy', 'fraz').
	 */
	public static function plural(int $n, string $one, string $few, string $many): string
	{
		if ($n === 1) {
			return $one;
		}

		$tens = $n % 100;
		$units = $n % 10;

		return $units >= 2 && $units <= 4 && ($tens < 12 || $tens > 14) ? $few : $many;
	}

	private static function groupDecimal(string $decimal): string
	{
		$negative = str_starts_with($decimal, '-');
		[$integer, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, null);
		$grouped = number_format((int) $integer, 0, '', "\u{00A0}");

		return ($negative ? '-' : '') . $grouped . ($fraction !== null ? ',' . $fraction : '');
	}
}
