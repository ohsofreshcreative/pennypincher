<?php

namespace App\PennyPincher\Domain\Import;

final class HeaderNormalizer
{
	private const FOLD = [
		'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
	];

	/**
	 * „Avg. CPC”, „Koszt (PLN)”, „Wyśw.” → „avg cpc”, „koszt”, „wyśw” (do wyświetlania i wykrywania języka).
	 */
	public static function normalize(?string $header): string
	{
		$value = (string) $header;
		$value = preg_replace('/^\x{FEFF}/u', '', $value);
		$value = str_replace(["\u{00A0}", "\u{202F}", "\t"], ' ', $value);
		$value = mb_strtolower(trim($value, " \t\n\r\"'"));
		$value = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $value);
		$value = str_replace(['.', ':', '_'], ' ', $value);
		$value = preg_replace('/\s+/u', ' ', $value);

		return trim($value);
	}

	/**
	 * Klucz porównawczy nagłówka: bez wielkości liter, polskich znaków, cudzysłowów, kropek i separatorów.
	 * „Konw. po obejrzeniu” = „konw po obejrzeniu”, „View-through conv.” = „view through conv”,
	 * „Uwzględnij w „Konwersjach”” = „uwzglednij w konwersjach”.
	 */
	public static function key(?string $header): string
	{
		$value = strtr(self::normalize($header), self::FOLD);
		$value = str_replace(['"', '„', '”', '“', '\'', '’', '-', '–', '/', ','], ' ', $value);

		return trim(preg_replace('/\s+/u', ' ', $value));
	}

	/**
	 * Wiersze podsumowań na końcu eksportów: „Total: Campaigns”, „Łącznie: konto”, „Razem”.
	 */
	public static function isTotalLabel(?string $value): bool
	{
		$value = mb_strtolower(trim((string) $value));

		return (bool) preg_match('/^(total|totals|łącznie|razem|suma|ogółem|podsumowanie)\b/u', $value);
	}
}
