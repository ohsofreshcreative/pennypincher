<?php

namespace App\PennyPincher\Domain\Keywords;

use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Projects\BusinessContext;

/**
 * Ocena trafności frazy względem projektu (bez AI): język, nazwy firm, marki konkurencji, związek z ofertą,
 * zgodność z grupą docelową. Zwraca flagi z polskim opisem – używane do filtrowania automatycznych fraz źródłowych
 * DataForSEO, ostrzeżeń przy frazach dodanych ręcznie i w Opportunity Score 2.0.
 *
 * Marki nie są blokowane bezwarunkowo: własna marka jest dozwolona, marki konkurencji tylko obniżają ocenę.
 */
final class PhraseQuality
{
	public const WRONG_LANGUAGE = 'wrong_language';

	public const COMPANY_NAME = 'company_name';

	public const COMPETITOR_BRAND = 'competitor_brand';

	public const OWN_BRAND = 'own_brand';

	public const UNRELATED = 'unrelated';

	public const LOW_INTENT = 'low_intent';

	public const INFORMATIONAL = 'informational';

	public const LABELS = [
		self::WRONG_LANGUAGE => 'fraza w innym języku niż projekt',
		self::COMPANY_NAME => 'wygląda na nazwę firmy lub domenę',
		self::COMPETITOR_BRAND => 'zawiera markę konkurencji',
		self::OWN_BRAND => 'zawiera własną markę',
		self::UNRELATED => 'brak związku z opisaną ofertą',
		self::LOW_INTENT => 'niska intencja zakupowa (praca, darmowe, nauka, zrób to sam lub niepożądani odbiorcy)',
		self::INFORMATIONAL => 'zapytanie informacyjne',
	];

	/** Flagi, przy których automatyczna fraza źródłowa jest odrzucana. */
	public const REJECT_AUTO = [self::WRONG_LANGUAGE, self::COMPANY_NAME, self::COMPETITOR_BRAND, self::UNRELATED, self::LOW_INTENT, self::INFORMATIONAL];

	private const EN_MARKERS = ['the', 'for', 'of', 'and', 'with', 'new', 'online', 'best', 'how', 'what', 'my', 'your', 'near', 'me', 'cheap', 'free', 'plan', 'seller', 'sell', 'buy', 'make', 'build', 'company', 'services', 'agency', 'website', 'websites', 'product', 'limited', 'store', 'shop', 'business', 'marketing', 'development', 'developer', 'designer'];

	private const PL_MARKERS = ['dla', 'jak', 'ile', 'na', 'w', 'z', 'do', 'od', 'cena', 'koszt', 'strona', 'strony', 'stron', 'sklep', 'firma', 'firmy', 'firm', 'tworzenie', 'projektowanie', 'oferta', 'usługi', 'uslugi', 'internetowa', 'internetowe', 'internetowych', 'tanie', 'najlepsza', 'warszawa', 'polska', 'wykonanie', 'zrobienie', 'stworzenie'];

	/**
	 * @return array<int, string> flagi
	 */
	public static function flags(string $phrase, BusinessContext $context, array $extraReference = []): array
	{
		$flags = [];
		$normalized = Text::normalize($phrase);
		$padded = ' ' . $normalized . ' ';
		$words = $normalized === '' ? [] : explode(' ', $normalized);

		if (self::matchesAny($padded, $context->ownBrands())) {
			$flags[] = self::OWN_BRAND;
		}

		if (self::matchesAny($padded, $context->competitorBrands())) {
			$flags[] = self::COMPETITOR_BRAND;
		}

		if (self::language($phrase) !== null && self::language($phrase) !== $context->language()) {
			$flags[] = self::WRONG_LANGUAGE;
		}

		$hasLegalForm = in_array('limited', $words, true) || in_array('ltd', $words, true) || in_array('inc', $words, true) || in_array('llc', $words, true)
			|| in_array('gmbh', $words, true) || in_array('corp', $words, true) || in_array('plc', $words, true) || str_contains($padded, ' sp z o o ') || str_contains($padded, ' spolka ');

		if (! in_array(self::OWN_BRAND, $flags, true) && ($hasLegalForm || preg_match('/\b[a-z0-9-]+\.(com|pl|eu|net|org|io)\b/i', mb_strtolower($phrase)))) {
			$flags[] = self::COMPANY_NAME;
		}

		$intents = Text::intents($phrase, $context->excludedAudiences());

		if (array_intersect($intents, KeywordAnalyzer::BLOCKED_INTENTS) !== []) {
			$flags[] = self::LOW_INTENT;
		} elseif (in_array('informational', $intents, true) && ! in_array('commercial', $intents, true)) {
			$flags[] = self::INFORMATIONAL;
		}

		if (! in_array(self::OWN_BRAND, $flags, true) && self::offerFit($phrase, $context, $extraReference) === 0.0) {
			$flags[] = self::UNRELATED;
		}

		return array_values(array_unique($flags));
	}

	/**
	 * Dopasowanie do oferty 0–1 na pojęciach (synonimy) i rdzeniach; null, gdy kontekst nie opisuje oferty.
	 * $extraReference – dodatkowe pojęcia (np. z konwertujących haseł i aktywnych słów kluczowych konta).
	 *
	 * @param array<int, string> $extraReference
	 */
	public static function offerFit(string $phrase, BusinessContext $context, array $extraReference = []): ?float
	{
		$offer = $context->offerTexts();

		if ($offer === [] && $extraReference === []) {
			return null;
		}

		$reference = $extraReference;

		foreach ($offer as $text) {
			array_push($reference, ...Concepts::of($text));
		}

		$concepts = array_filter(Concepts::of($phrase), fn ($c) => ! in_array($c, Concepts::MODIFIERS, true) && $c !== 'service' && $c !== 'company');

		if ($concepts === []) {
			return null;
		}

		$set = array_flip($reference);

		return count(array_filter($concepts, fn ($c) => isset($set[$c]))) / count($concepts);
	}

	/**
	 * Zgodność z grupą docelową 0–1: 1 – fraza wskazuje odbiorców z kontekstu, 0,5 – neutralna; null bez opisu odbiorców.
	 */
	public static function audienceFit(string $phrase, BusinessContext $context): ?float
	{
		$audience = $context->audienceTexts();

		if ($audience === []) {
			return null;
		}

		if (self::matchesAny(' ' . Text::normalize($phrase) . ' ', $context->excludedAudiences())) {
			return 0.0;
		}

		$reference = [];

		foreach ($audience as $text) {
			array_push($reference, ...Concepts::of($text));
		}

		$phraseConcepts = Concepts::of($phrase);
		$audienceConcepts = array_intersect($phraseConcepts, ['company', 'shop', 'local', '~b2b']);

		if ($audienceConcepts === []) {
			return 0.5;
		}

		return array_intersect($audienceConcepts, $reference) !== [] ? 1.0 : 0.3;
	}

	/**
	 * Wykryty język frazy ('pl', 'en') albo null, gdy nie da się go ustalić (np. jedno słowo bez znaczników).
	 */
	public static function language(string $phrase): ?string
	{
		$lower = mb_strtolower(trim($phrase));

		if ($lower === '') {
			return null;
		}

		$pl = preg_match('/[ąćęłńóśźż]/u', $lower) ? 2 : 0;
		$en = 0;

		foreach (preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [] as $word) {
			if ($word === '') {
				continue;
			}

			$ascii = Text::normalize($word);

			if (in_array($word, self::PL_MARKERS, true) || in_array($ascii, self::PL_MARKERS, true) || preg_match('/(owy|owa|owe|owych|anie|enie|ość|osc|ych|ami|ach|ego|emu|cja|cje|cji)$/u', $word)) {
				$pl++;
			}

			if (in_array($word, self::EN_MARKERS, true) || preg_match('/(ing|tion|ness|ment)$/', $word)) {
				$en++;
			}
		}

		return match (true) {
			$pl > $en && $pl >= 1 => 'pl',
			$en > $pl && $en >= 1 && $pl === 0 => 'en',
			default => null,
		};
	}

	/**
	 * @param array<int, string> $flags
	 */
	public static function describe(array $flags): string
	{
		return implode('; ', array_map(fn ($f) => self::LABELS[$f] ?? $f, $flags));
	}

	/**
	 * @param array<int, string> $patterns
	 */
	private static function matchesAny(string $padded, array $patterns): bool
	{
		foreach ($patterns as $pattern) {
			$p = Text::normalize($pattern);

			if ($p !== '' && str_contains($padded, ' ' . $p . ' ')) {
				return true;
			}

			// nazwa pisana razem w frazie („stevedesign” vs „steve design”)
			if ($p !== '' && str_contains(str_replace(' ', '', $padded), str_replace(' ', '', $p)) && mb_strlen($p) >= 5) {
				return true;
			}
		}

		return false;
	}
}
