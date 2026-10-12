<?php

namespace App\PennyPincher\Domain\Import;

final class HeaderNormalizer
{
	/**
	 * „Avg. CPC”, „Koszt (PLN)”, „Wyśw.” → „avg cpc”, „koszt”, „wyśw”.
	 */
	public static function normalize(?string $header): string
	{
		$value = (string) $header;
		$value = preg_replace('/^\x{FEFF}/u', '', $value);
		$value = str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);
		$value = mb_strtolower(trim($value, " \t\n\r\"'"));
		$value = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $value);
		$value = str_replace(['.', ':', '_'], ' ', $value);
		$value = preg_replace('/\s+/u', ' ', $value);

		return trim($value);
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
