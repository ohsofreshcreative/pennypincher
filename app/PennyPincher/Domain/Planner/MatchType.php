<?php

namespace App\PennyPincher\Domain\Planner;

/**
 * Typy dopasowania słów kluczowych Google Ads (planowanie – bez wysyłania do konta).
 */
final class MatchType
{
	public const EXACT = 'exact';

	public const PHRASE = 'phrase';

	public const BROAD = 'broad';

	public const LABELS = [
		self::EXACT => 'Ścisłe [ ]',
		self::PHRASE => 'Do wyrażenia " "',
		self::BROAD => 'Przybliżone',
	];

	public const HINTS = [
		self::EXACT => 'Najwęższe – zapytania o tym samym znaczeniu. Najlepsza kontrola kosztów.',
		self::PHRASE => 'Zapytania zawierające znaczenie frazy. Rozsądny domyślny wybór.',
		self::BROAD => 'Najszersze – Google dobiera powiązane zapytania. Wymaga wiarygodnego pomiaru konwersji i Smart Bidding.',
	];

	/**
	 * Propozycja dopasowania: nigdy automatycznie przybliżone. Ścisłe dla fraz z udokumentowanymi konwersjami
	 * albo długich (≥ 5 słów), pozostałe – do wyrażenia.
	 */
	public static function suggest(string $phrase, ?float $historicalConversions = null): string
	{
		if (($historicalConversions ?? 0) > 0 || count(preg_split('/\s+/u', trim($phrase)) ?: []) >= 5) {
			return self::EXACT;
		}

		return self::PHRASE;
	}

	public static function valid(string $type): bool
	{
		return array_key_exists($type, self::LABELS);
	}

	/**
	 * Zapis w notacji Google Ads ([ścisłe], "do wyrażenia", przybliżone).
	 */
	public static function notation(string $phrase, string $type): string
	{
		return match ($type) {
			self::EXACT => '[' . $phrase . ']',
			self::PHRASE => '"' . $phrase . '"',
			default => $phrase,
		};
	}
}
