<?php

namespace App\PennyPincher\Domain\Metrics;

use InvalidArgumentException;

/**
 * Operacje na kwotach w mikrojednostkach (1 jednostka waluty = 1 000 000 mikro).
 * Bez floatów: konwersja odbywa się na łańcuchach i liczbach całkowitych.
 */
final class Money
{
	public const MICROS = 1_000_000;

	/**
	 * "1234.5" / "-0.01" (kanoniczny zapis dziesiętny z kropką) → mikrojednostki.
	 * Cyfry poza 6. miejscem po przecinku są zaokrąglane połówkowo w górę (od zera).
	 */
	public static function toMicros(string $decimal): int
	{
		$decimal = trim($decimal);

		if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $decimal, $m)) {
			throw new InvalidArgumentException("Nieprawidłowa kwota: {$decimal}");
		}

		$negative = $m[1] === '-';
		$integer = ltrim($m[2], '0') ?: '0';
		$fraction = $m[3] ?? '';

		if (strlen($integer) > 12) {
			throw new InvalidArgumentException("Kwota poza zakresem: {$decimal}");
		}

		$micros = (int) $integer * self::MICROS + (int) str_pad(substr($fraction, 0, 6), 6, '0');

		if (strlen($fraction) > 6 && (int) $fraction[6] >= 5) {
			$micros++;
		}

		return $negative ? -$micros : $micros;
	}

	/**
	 * Mikrojednostki → zapis dziesiętny z zadaną liczbą miejsc po przecinku (zaokrąglenie połówkowe).
	 */
	public static function toDecimal(int $micros, int $scale = 2): string
	{
		$negative = $micros < 0;
		$abs = abs($micros);
		$divisor = 10 ** (6 - $scale);
		$scaled = intdiv($abs + intdiv($divisor, 2), $divisor);

		$integer = intdiv($scaled, 10 ** $scale);
		$fraction = $scale > 0 ? '.' . str_pad((string) ($scaled % (10 ** $scale)), $scale, '0', STR_PAD_LEFT) : '';

		return ($negative && $scaled > 0 ? '-' : '') . $integer . $fraction;
	}

	public static function fromUnits(int|float|string|null $value): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}

		if (is_int($value)) {
			return $value * self::MICROS;
		}

		return self::toMicros(is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value);
	}
}
