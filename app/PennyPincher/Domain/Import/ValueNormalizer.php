<?php

namespace App\PennyPincher\Domain\Import;

/**
 * Normalizacja wartości słownikowych (status, typ dopasowania) do stałych aplikacji.
 * Zawsze zachowujemy też oryginalny tekst, bo słownik Google Ads się zmienia.
 */
final class ValueNormalizer
{
	public static function status(?string $raw): ?string
	{
		$value = self::fold(mb_strtolower(trim((string) $raw)));

		if ($value === '' || $value === '--') {
			return null;
		}

		return match (true) {
			(bool) preg_match('/^(enabled|eligible|active|wlaczon|aktywn|kwalifikuj|ograniczon|limited|learning|uczenie)/u', $value) => 'enabled',
			(bool) preg_match('/^(paused|wstrzyman)/u', $value) => 'paused',
			(bool) preg_match('/^(removed|usuniet|deleted)/u', $value) => 'removed',
			(bool) preg_match('/^(ended|zakonczon)/u', $value) => 'ended',
			(bool) preg_match('/^(pending|oczekuj)/u', $value) => 'pending',
			default => 'other',
		};
	}

	public static function matchType(?string $raw): ?string
	{
		$value = self::fold(mb_strtolower(trim((string) $raw)));

		if ($value === '' || $value === '--') {
			return null;
		}

		$closeVariant = (bool) preg_match('/(close variant|bliska odmiana|odmiana bliska|bliski wariant)/u', $value);

		$type = match (true) {
			str_contains($value, 'exact') || str_contains($value, 'scisl') => 'exact',
			str_contains($value, 'phrase') || str_contains($value, 'wyrazeni') => 'phrase',
			str_contains($value, 'broad') || str_contains($value, 'przyblizon') || str_contains($value, 'szerok') => 'broad',
			default => 'other',
		};

		return $closeVariant && $type !== 'other' ? $type . '_close_variant' : $type;
	}

	/**
	 * Słowo kluczowe zapisane z operatorami dopasowania: [słowo] → exact, "słowo" → phrase.
	 *
	 * @return array{0: string, 1: string|null} [tekst, typ dopasowania wynikający z zapisu]
	 */
	public static function keywordText(string $raw): array
	{
		$text = trim($raw);

		if (preg_match('/^\[(.*)\]$/u', $text, $m)) {
			return [trim($m[1]), 'exact'];
		}

		if (preg_match('/^"(.*)"$/u', $text, $m)) {
			return [trim($m[1]), 'phrase'];
		}

		return [$text, null];
	}

	public static function boolean(?string $raw): ?bool
	{
		$value = self::fold(mb_strtolower(trim((string) $raw)));

		return match (true) {
			in_array($value, ['yes', 'tak', 'true', '1', 'included', 'uwzglednione'], true) => true,
			in_array($value, ['no', 'nie', 'false', '0', 'excluded', 'nieuwzglednione'], true) => false,
			default => null,
		};
	}

	public static function currency(?string $raw): ?string
	{
		$value = strtoupper(trim((string) $raw));

		return preg_match('/^[A-Z]{3}$/', $value) ? $value : null;
	}

	public static function matchTypeLabel(?string $type): string
	{
		return match ($type) {
			'exact' => 'Ścisłe',
			'exact_close_variant' => 'Ścisłe (bliska odmiana)',
			'phrase' => 'Do wyrażenia',
			'phrase_close_variant' => 'Do wyrażenia (bliska odmiana)',
			'broad' => 'Przybliżone',
			'other' => 'Inne',
			default => '—',
		};
	}

	public static function statusLabel(?string $status): string
	{
		return match ($status) {
			'enabled' => 'Aktywna',
			'paused' => 'Wstrzymana',
			'removed' => 'Usunięta',
			'ended' => 'Zakończona',
			'pending' => 'Oczekująca',
			'other' => 'Inny',
			default => 'Brak danych',
		};
	}

	private static function fold(string $value): string
	{
		return strtr($value, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
	}
}
