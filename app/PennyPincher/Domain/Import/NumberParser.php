<?php

namespace App\PennyPincher\Domain\Import;

/**
 * Zamienia liczby z eksportów Google Ads na kanoniczny zapis dziesiętny ("1234.56").
 *
 * Obsługuje: polskie i angielskie separatory ("1 234,56", "1,234.56", "1.234,56"),
 * spacje niełamliwe, symbole i kody walut, procenty, zapis księgowy "(12,50)",
 * oraz brak danych ("--", "", "—"), który zwraca jako null.
 */
final class NumberParser
{
	public const COMMA = 'comma';

	public const DOT = 'dot';

	private const EMPTY = ['', '--', '-', '—', '–', 'n/a', 'na', 'brak', 'null', '< 10%', '<10%', '> 90%', '>90%'];

	/**
	 * @param string|null $decimalHint separator dziesiętny wykryty dla całego pliku (comma|dot)
	 * @throws InvalidValue
	 */
	public static function parse(?string $raw, ?string $decimalHint = null): ?string
	{
		if ($raw === null) {
			return null;
		}

		$value = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}"], ' ', $raw);
		$value = trim($value);

		if (in_array(mb_strtolower($value), self::EMPTY, true)) {
			return null;
		}

		$negative = false;

		if (preg_match('/^\((.*)\)$/u', $value, $m)) {
			$negative = true;
			$value = $m[1];
		}

		$value = preg_replace('/(zł|pln|eur|usd|gbp|chf|czk|€|\$|£|%)/iu', '', $value);
		$value = str_replace([' ', "'", '’'], '', $value);
		$value = str_replace('−', '-', $value);

		if (str_starts_with($value, '-')) {
			$negative = ! $negative;
			$value = substr($value, 1);
		} elseif (str_starts_with($value, '+')) {
			$value = substr($value, 1);
		}

		if ($value === '' || ! preg_match('/^[0-9.,]+$/', $value) || ! preg_match('/\d/', $value)) {
			throw new InvalidValue("„{$raw}” nie jest liczbą.");
		}

		$value = self::normalizeSeparators($value, $decimalHint, $raw);
		$value = ltrim($value, '0');

		if ($value === '' || str_starts_with($value, '.')) {
			$value = '0' . $value;
		}

		if (str_contains($value, '.')) {
			$value = rtrim(rtrim($value, '0'), '.');
		}

		return ($negative && $value !== '0' ? '-' : '') . $value;
	}

	/**
	 * Wykrywa separator dziesiętny na podstawie wielu wartości z pliku.
	 *
	 * @param iterable<string|null> $values
	 */
	public static function detectDecimalSeparator(iterable $values): ?string
	{
		$comma = 0;
		$dot = 0;

		foreach ($values as $value) {
			if ($value === null) {
				continue;
			}

			$value = str_replace(["\u{00A0}", "\u{202F}", ' '], '', (string) $value);
			$value = preg_replace('/[^0-9.,]/', '', $value);

			if ($value === '') {
				continue;
			}

			$lastComma = strrpos($value, ',');
			$lastDot = strrpos($value, '.');

			if ($lastComma !== false && $lastDot !== false) {
				$lastComma > $lastDot ? $comma++ : $dot++;
			} elseif ($lastComma !== false && strlen($value) - $lastComma - 1 !== 3) {
				$comma++;
			} elseif ($lastDot !== false && strlen($value) - $lastDot - 1 !== 3) {
				$dot++;
			}
		}

		if ($comma === 0 && $dot === 0) {
			return null;
		}

		return $comma >= $dot ? self::COMMA : self::DOT;
	}

	private static function normalizeSeparators(string $value, ?string $hint, string $raw): string
	{
		$commas = substr_count($value, ',');
		$dots = substr_count($value, '.');

		if ($commas > 0 && $dots > 0) {
			$decimal = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
			$thousands = $decimal === ',' ? '.' : ',';

			if (substr_count($value, $decimal) > 1) {
				throw new InvalidValue("„{$raw}” ma niejednoznaczny format liczby.");
			}

			return str_replace([$thousands, $decimal], ['', '.'], $value);
		}

		if ($commas > 0) {
			if ($commas > 1 || ($hint === self::DOT && preg_match('/^\d{1,3},\d{3}$/', $value))) {
				self::assertThousands($value, ',', $raw);

				return str_replace(',', '', $value);
			}

			return str_replace(',', '.', $value);
		}

		if ($dots > 0) {
			if ($dots > 1 || ($hint === self::COMMA && preg_match('/^\d{1,3}\.\d{3}$/', $value))) {
				self::assertThousands($value, '.', $raw);

				return str_replace('.', '', $value);
			}
		}

		return $value;
	}

	private static function assertThousands(string $value, string $separator, string $raw): void
	{
		$pattern = '/^\d{1,3}(' . preg_quote($separator, '/') . '\d{3})+$/';

		if (! preg_match($pattern, $value)) {
			throw new InvalidValue("„{$raw}” ma nieprawidłowe separatory tysięcy.");
		}
	}
}
