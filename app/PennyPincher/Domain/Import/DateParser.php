<?php

namespace App\PennyPincher\Domain\Import;

use DateTimeImmutable;

/**
 * Parsuje daty z eksportów Google Ads (PL i EN) do formatu Y-m-d.
 */
final class DateParser
{
	private const MONTHS = [
		// polski: mianownik, dopełniacz, skróty
		'styczeń' => 1, 'stycznia' => 1, 'sty' => 1,
		'luty' => 2, 'lutego' => 2, 'lut' => 2,
		'marzec' => 3, 'marca' => 3, 'mar' => 3,
		'kwiecień' => 4, 'kwietnia' => 4, 'kwi' => 4,
		'maj' => 5, 'maja' => 5,
		'czerwiec' => 6, 'czerwca' => 6, 'cze' => 6,
		'lipiec' => 7, 'lipca' => 7, 'lip' => 7,
		'sierpień' => 8, 'sierpnia' => 8, 'sie' => 8,
		'wrzesień' => 9, 'września' => 9, 'wrz' => 9,
		'październik' => 10, 'października' => 10, 'paź' => 10, 'paz' => 10,
		'listopad' => 11, 'listopada' => 11, 'lis' => 11,
		'grudzień' => 12, 'grudnia' => 12, 'gru' => 12,
		// angielski
		'january' => 1, 'jan' => 1,
		'february' => 2, 'feb' => 2,
		'march' => 3,
		'april' => 4, 'apr' => 4,
		'may' => 5,
		'june' => 6, 'jun' => 6,
		'july' => 7, 'jul' => 7,
		'august' => 8, 'aug' => 8,
		'september' => 9, 'sep' => 9, 'sept' => 9,
		'october' => 10, 'oct' => 10,
		'november' => 11, 'nov' => 11,
		'december' => 12, 'dec' => 12,
	];

	/**
	 * @param string|null $locale 'pl' albo 'en' – rozstrzyga kolejność dzień/miesiąc w zapisie z ukośnikami
	 * @throws InvalidValue
	 */
	public static function parse(?string $raw, ?string $locale = null): ?string
	{
		if ($raw === null) {
			return null;
		}

		$value = trim(str_replace("\u{00A0}", ' ', $raw));

		if ($value === '' || $value === '--') {
			return null;
		}

		// Data seryjna Excela (gdy komórka XLSX nie ma formatu daty).
		if (preg_match('/^\d{5}(\.0+)?$/', $value)) {
			return self::fromExcelSerial((int) $value);
		}

		// 2026-09-01, 2026/09/01, 2026.09.01 (opcjonalnie z godziną)
		if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $value, $m)) {
			return self::build((int) $m[1], (int) $m[2], (int) $m[3], $raw);
		}

		// 01.09.2026, 1.9.2026, 01-09-2026
		if (preg_match('/^(\d{1,2})[.\-](\d{1,2})[.\-](\d{4})$/', $value, $m)) {
			return self::build((int) $m[3], (int) $m[2], (int) $m[1], $raw);
		}

		// 09/01/2026 – zależnie od języka; jeśli pierwsza liczba > 12, to na pewno dzień
		if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $value, $m)) {
			$a = (int) $m[1];
			$b = (int) $m[2];
			$year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
			$dayFirst = $a > 12 || ($b <= 12 && $locale !== 'en');

			return $dayFirst ? self::build($year, $b, $a, $raw) : self::build($year, $a, $b, $raw);
		}

		$lower = self::fold(mb_strtolower($value));
		$lower = preg_replace('/^(pon|wt|śr|czw|pt|sob|niedz?|mon|tue|wed|thu|fri|sat|sun)[a-ząćęłńóśźż]*\.?,?\s+/u', '', $lower);
		$lower = rtrim(str_replace([' r.', 'r.'], '', $lower));

		// 1 września 2026, 1 wrz 2026, 1 Sep 2026
		if (preg_match('/^(\d{1,2})\.?\s+([a-ząćęłńóśźż]+)\.?,?\s+(\d{4})$/u', $lower, $m) && ($month = self::month($m[2])) !== null) {
			return self::build((int) $m[3], $month, (int) $m[1], $raw);
		}

		// Sep 1, 2026 / September 1, 2026
		if (preg_match('/^([a-ząćęłńóśźż]+)\.?\s+(\d{1,2}),?\s+(\d{4})$/u', $lower, $m) && ($month = self::month($m[1])) !== null) {
			return self::build((int) $m[3], $month, (int) $m[2], $raw);
		}

		throw new InvalidValue("„{$raw}” nie jest rozpoznawalną datą.");
	}

	/**
	 * Szuka zakresu dat w tekście nagłówka raportu, np. „1 września 2026 - 30 września 2026”.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function parseRange(string $text, ?string $locale = null): ?array
	{
		$text = trim(str_replace("\u{00A0}", ' ', $text), " \t\"'");
		$parts = preg_split('/\s+[-–—]\s+|\s+(?:do|to)\s+/u', $text);

		if (! is_array($parts) || count($parts) !== 2) {
			return null;
		}

		try {
			$from = self::parse(trim($parts[0]), $locale);
			$to = self::parse(trim($parts[1]), $locale);
		} catch (InvalidValue) {
			return null;
		}

		if ($from === null || $to === null) {
			return null;
		}

		return $from <= $to ? [$from, $to] : [$to, $from];
	}

	/**
	 * Nazwa miesiąca bez względu na polskie znaki („wrzesnia” = „września”).
	 */
	private static function month(string $name): ?int
	{
		static $folded = null;

		if ($folded === null) {
			$folded = [];

			foreach (self::MONTHS as $key => $number) {
				$folded[self::fold($key)] = $number;
			}
		}

		return $folded[self::fold($name)] ?? null;
	}

	private static function fold(string $value): string
	{
		return strtr($value, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
	}

	public static function fromExcelSerial(int $serial): string
	{
		return (new DateTimeImmutable('1899-12-30'))->modify("+{$serial} days")->format('Y-m-d');
	}

	private static function build(int $year, int $month, int $day, string $raw): string
	{
		if (! checkdate($month, $day, $year) || $year < 2000 || $year > 2100) {
			throw new InvalidValue("„{$raw}” nie jest poprawną datą.");
		}

		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}
}
