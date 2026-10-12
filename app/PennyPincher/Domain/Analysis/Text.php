<?php

namespace App\PennyPincher\Domain\Analysis;

/**
 * Proste, deterministyczne przetwarzanie fraz (PL/EN): normalizacja, tokeny, „rdzenie” dla odmiany,
 * rozpoznanie intencji na podstawie słowników ogólnych (nie branżowych).
 */
final class Text
{
	private const STOPWORDS = [
		'i', 'w', 'z', 'na', 'do', 'od', 'po', 'za', 'o', 'u', 'a', 'oraz', 'lub', 'czy', 'się', 'sie', 'to', 'ten', 'ta', 'te',
		'dla', 'jak', 'co', 'jest', 'są', 'przez', 'przy', 'pod', 'nad', 'bez', 'ze', 'we', 'mój', 'moja', 'moje',
		'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'and', 'or', 'with', 'my', 'is', 'how', 'what',
	];

	/**
	 * Słowniki intencji – ogólne wzorce językowe (prefiksy po normalizacji bez polskich znaków).
	 * Kategorie: free, jobs, education, diy, informational, commercial.
	 */
	public const PATTERNS = [
		'free' => ['darmow', 'za darmo', 'bezplatn', 'gratis', 'free', 'za free'],
		'jobs' => ['praca', 'pracy', 'prace', 'oferty pracy', 'zatrudni', 'rekrutac', 'staz', 'stazu', 'zarobki', 'wynagrodzen', 'pensja', 'job', 'jobs', 'career', 'salary', 'hiring'],
		'education' => ['kurs', 'kursy', 'kursu', 'kursie', 'szkoleni', 'nauka', 'naucz', 'lekcj', 'studia', 'egzamin', 'certyfikat', 'tutorial', 'course', 'learn', 'lesson', 'training'],
		'diy' => ['samodzieln', 'krok po kroku', 'kreator', 'generator', 'szablon', 'template', 'wzor', 'wzory', 'wzorow', 'zrob to sam', 'diy', 'do it yourself', 'stworz sam', 'jak zrobic', 'jak stworzyc', 'jak zalozyc'],
		'informational' => ['jak', 'co to', 'czym jest', 'dlaczego', 'poradnik', 'przyklad', 'definicj', 'wikipedia', 'inspiracj', 'pomysl', 'how to', 'what is', 'why', 'guide', 'example', 'ideas'],
		'commercial' => ['cena', 'ceny', 'cennik', 'koszt', 'ile kosztuje', 'oferta', 'oferty', 'firma', 'firmy', 'agencj', 'uslug', 'zlecen', 'wycen', 'zamow', 'kup', 'najlepsz', 'profesjonaln', 'dla firm', 'price', 'pricing', 'cost', 'agency', 'company', 'services', 'quote', 'buy', 'hire'],
	];

	public const INTENT_LABELS = [
		'commercial' => 'zakupowa',
		'informational' => 'informacyjna',
		'free' => 'darmowe rozwiązania',
		'jobs' => 'praca',
		'education' => 'edukacyjna',
		'diy' => 'zrób to sam',
		'unknown' => 'nieokreślona',
	];

	public static function normalize(string $text): string
	{
		$text = mb_strtolower(trim($text));
		$text = strtr($text, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'č' => 'c', 'ř' => 'r', 'š' => 's', 'ž' => 'z']);
		$text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

		return trim((string) preg_replace('/\s+/u', ' ', $text));
	}

	/**
	 * @return array<int, string> tokeny bez słów funkcyjnych (min. 2 znaki)
	 */
	public static function tokens(string $text): array
	{
		$tokens = array_filter(explode(' ', self::normalize($text)), fn ($t) => mb_strlen($t) >= 2 && ! in_array($t, self::STOPWORDS, true));

		return array_values(array_unique($tokens));
	}

	/**
	 * Rdzeń słowa dla prostego dopasowania odmian (pierwsze 5 znaków; krótkie słowa bez zmian).
	 */
	public static function stem(string $token): string
	{
		return mb_strlen($token) > 5 ? mb_substr($token, 0, 5) : $token;
	}

	/**
	 * @return array<int, string>
	 */
	public static function stems(string $text): array
	{
		return array_values(array_unique(array_map([self::class, 'stem'], self::tokens($text))));
	}

	/**
	 * Udział rdzeni frazy obecnych w zbiorze rdzeni odniesienia (0–1).
	 *
	 * @param array<int, string> $reference
	 */
	public static function overlap(string $phrase, array $reference): float
	{
		$stems = self::stems($phrase);

		if ($stems === [] || $reference === []) {
			return 0.0;
		}

		$set = array_flip($reference);

		return count(array_filter($stems, fn ($stem) => isset($set[$stem]))) / count($stems);
	}

	/**
	 * Kategorie intencji, do których pasuje fraza (może być kilka).
	 *
	 * @param array<int, string> $extraExcluded dodatkowe frazy niepożądane z kontekstu projektu
	 * @return array<int, string>
	 */
	public static function intents(string $phrase, array $extraExcluded = []): array
	{
		$normalized = ' ' . self::normalize($phrase) . ' ';
		$found = [];

		foreach (self::PATTERNS as $intent => $patterns) {
			foreach ($patterns as $pattern) {
				if (self::containsPattern($normalized, $pattern)) {
					$found[] = $intent;
					break;
				}
			}
		}

		foreach ($extraExcluded as $excluded) {
			$pattern = self::normalize($excluded);

			if ($pattern !== '' && self::containsPattern($normalized, $pattern)) {
				$found[] = 'excluded';
				break;
			}
		}

		return array_values(array_unique($found));
	}

	/**
	 * Wzorzec dopasowany od początku słowa (prefiks), żeby „szkoleni” pasował do „szkolenia”, ale nie do innych słów.
	 * Krótkie wzorce (≤ 4 znaki, np. „jak”, „kurs”, „cena”) muszą być całym słowem – „jak” nie pasuje do „jakość”.
	 */
	public static function containsPattern(string $normalizedWithSpaces, string $pattern): bool
	{
		$pattern = trim(self::normalize($pattern));

		if ($pattern === '') {
			return false;
		}

		return mb_strlen($pattern) <= 4
			? str_contains($normalizedWithSpaces, ' ' . $pattern . ' ')
			: str_contains($normalizedWithSpaces, ' ' . $pattern);
	}

	/**
	 * Główna intencja frazy (do prezentacji): negatywne kategorie mają pierwszeństwo przed zakupową.
	 *
	 * @param array<int, string> $intents
	 */
	public static function primaryIntent(array $intents): string
	{
		foreach (['excluded', 'jobs', 'free', 'diy', 'education', 'commercial', 'informational'] as $intent) {
			if (in_array($intent, $intents, true)) {
				return $intent === 'excluded' ? 'excluded' : $intent;
			}
		}

		return 'unknown';
	}
}
