<?php

namespace App\PennyPincher\Domain\Keywords;

use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoClient;

/**
 * Automatyczne frazy źródłowe do zapytania DataForSEO, w kolejności:
 * 1. usługi priorytetowe z kontekstu (z wariantem „dla firm”, gdy grupą docelową są firmy),
 * 2. produkty i usługi z kontekstu,
 * 3. skuteczne (konwertujące) wyszukiwane hasła i słowa kluczowe,
 * 4. aktywne słowa kluczowe z ruchem.
 *
 * Odrzucane (z podaniem powodu): frazy w innym języku, nazwy firm i domen, marki konkurencji, zapytania informacyjne,
 * darmowe/praca/nauka/zrób to sam, niepożądani odbiorcy i frazy bez związku z ofertą. Własna marka jest dozwolona.
 * Frazy dodane ręcznie przez użytkownika nie przechodzą przez ten filtr (dostają tylko ostrzeżenia).
 */
final class SeedSelector
{
	/**
	 * @return array<int, array{phrase: string, source: string}>
	 */
	public static function select(BusinessContext $context, array $terms, array $keywords, int $limit = DataForSeoClient::MAX_SEEDS, array $working = []): array
	{
		return self::analyze($context, $terms, $keywords, $limit, $working)['seeds'];
	}

	/**
	 * @param array<int, array<string, mixed>> $terms wyszukiwane hasła z sumami (searchTermStats)
	 * @param array<int, array<string, mixed>> $keywords słowa kluczowe z sumami (keywordStats)
	 * @param array<int, string> $working pojęcia tego, co konto już reklamuje (do oceny związku z ofertą)
	 * @return array{seeds: array<int, array{phrase: string, source: string}>, rejected: array<int, array{phrase: string, source: string, reason: string}>}
	 */
	public static function analyze(BusinessContext $context, array $terms, array $keywords, int $limit = DataForSeoClient::MAX_SEEDS, array $working = []): array
	{
		$candidates = [];
		$audience = [];

		foreach ($context->audienceTexts() as $text) {
			array_push($audience, ...Concepts::of($text));
		}

		$forCompanies = in_array('company', $audience, true);

		foreach (array_slice($context->priorityTexts(), 0, 6) as $i => $text) {
			$candidates[] = [$text, 'Usługa priorytetowa', true];

			if ($forCompanies && $i < 2 && $context->language() === 'pl' && ! in_array('company', Concepts::of($text), true)) {
				$candidates[] = [$text . ' dla firm', 'Usługa priorytetowa + grupa docelowa', true];
			}
		}

		foreach (array_slice(BusinessContext::lines($context->get('products_services')), 0, 6) as $text) {
			$candidates[] = [$text, 'Produkt / usługa', true];
		}

		$converting = array_filter($terms, fn ($t) => (float) ($t['conversions'] ?? 0) > 0);
		usort($converting, fn ($a, $b) => (float) $b['conversions'] <=> (float) $a['conversions'] ?: $b['clicks'] <=> $a['clicks']);

		foreach ($converting as $term) {
			$candidates[] = [(string) $term['term'], 'Konwertujące hasło', false];
		}

		$keywordConverting = array_filter($keywords, fn ($k) => (float) ($k['conversions'] ?? 0) > 0);
		usort($keywordConverting, fn ($a, $b) => (float) $b['conversions'] <=> (float) $a['conversions']);

		foreach ($keywordConverting as $keyword) {
			$candidates[] = [(string) $keyword['text'], 'Konwertujące słowo kluczowe', false];
		}

		$byClicks = array_filter($keywords, fn ($k) => (int) ($k['clicks'] ?? 0) > 0);
		usort($byClicks, fn ($a, $b) => (int) $b['clicks'] <=> (int) $a['clicks']);

		foreach ($byClicks as $keyword) {
			$candidates[] = [(string) $keyword['text'], 'Słowo kluczowe z ruchem', false];
		}

		$seeds = [];
		$rejected = [];

		foreach ($candidates as [$phrase, $source, $fromContext]) {
			$phrase = self::clean($phrase);
			$key = Text::normalize($phrase);

			if ($key === '' || isset($seeds[$key]) || isset($rejected[$key])) {
				continue;
			}

			if (mb_strlen($phrase) > DataForSeoClient::MAX_SEED_LENGTH || count(explode(' ', $key)) > 6) {
				$rejected[$key] = ['phrase' => $phrase, 'source' => $source, 'reason' => 'za długa fraza (maks. 80 znaków i 6 słów)'];
				continue;
			}

			$flags = array_intersect(PhraseQuality::flags($phrase, $context, $working), PhraseQuality::REJECT_AUTO);

			// Teksty z kontekstu biznesowego z definicji opisują ofertę – nie odrzucamy ich za „brak związku z ofertą”.
			if ($fromContext) {
				$flags = array_diff($flags, [PhraseQuality::UNRELATED]);
			}

			if ($flags !== []) {
				$rejected[$key] = ['phrase' => mb_strtolower($phrase), 'source' => $source, 'reason' => PhraseQuality::describe(array_values($flags))];
				continue;
			}

			if (count($seeds) < $limit) {
				$seeds[$key] = ['phrase' => mb_strtolower($phrase), 'source' => $source];
			}
		}

		return ['seeds' => array_values($seeds), 'rejected' => array_values(array_slice($rejected, 0, 30))];
	}

	public static function clean(string $phrase): string
	{
		return trim((string) preg_replace('/\s+/u', ' ', str_replace(['"', '[', ']', '+'], '', $phrase)));
	}
}
