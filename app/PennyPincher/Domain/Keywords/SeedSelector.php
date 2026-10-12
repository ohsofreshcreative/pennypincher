<?php

namespace App\PennyPincher\Domain\Keywords;

use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoClient;

/**
 * Frazy źródłowe do zapytania DataForSEO: najpierw usługi priorytetowe z kontekstu biznesowego, potem skuteczne
 * (konwertujące) wyszukiwane hasła i słowa kluczowe, na końcu aktywne słowa z ruchem. Bez fraz z wykluczoną intencją.
 */
final class SeedSelector
{
	/**
	 * @param array<int, array<string, mixed>> $terms wyszukiwane hasła z sumami (searchTermStats)
	 * @param array<int, array<string, mixed>> $keywords słowa kluczowe z sumami (keywordStats)
	 * @return array<int, array{phrase: string, source: string}>
	 */
	public static function select(BusinessContext $context, array $terms, array $keywords, int $limit = DataForSeoClient::MAX_SEEDS): array
	{
		$candidates = [];

		foreach (array_slice($context->priorityTexts(), 0, 6) as $text) {
			$candidates[] = [$text, 'Usługa priorytetowa'];
		}

		$converting = array_filter($terms, fn ($t) => (float) ($t['conversions'] ?? 0) > 0);
		usort($converting, fn ($a, $b) => (float) $b['conversions'] <=> (float) $a['conversions'] ?: $b['clicks'] <=> $a['clicks']);

		foreach ($converting as $term) {
			$candidates[] = [(string) $term['term'], 'Konwertujące hasło'];
		}

		$keywordConverting = array_filter($keywords, fn ($k) => (float) ($k['conversions'] ?? 0) > 0);
		usort($keywordConverting, fn ($a, $b) => (float) $b['conversions'] <=> (float) $a['conversions']);

		foreach ($keywordConverting as $keyword) {
			$candidates[] = [(string) $keyword['text'], 'Konwertujące słowo kluczowe'];
		}

		$byClicks = array_filter($keywords, fn ($k) => (int) ($k['clicks'] ?? 0) > 0);
		usort($byClicks, fn ($a, $b) => (int) $b['clicks'] <=> (int) $a['clicks']);

		foreach ($byClicks as $keyword) {
			$candidates[] = [(string) $keyword['text'], 'Słowo kluczowe z ruchem'];
		}

		$seeds = [];
		$excluded = $context->excludedAudiences();

		foreach ($candidates as [$phrase, $source]) {
			$phrase = trim(preg_replace('/\s+/u', ' ', str_replace(['"', '[', ']', '+'], '', $phrase)));
			$key = Text::normalize($phrase);
			$words = count(explode(' ', $key));

			if ($key === '' || isset($seeds[$key]) || mb_strlen($phrase) > DataForSeoClient::MAX_SEED_LENGTH || $words > 6) {
				continue;
			}

			if (array_intersect(Text::intents($phrase, $excluded), KeywordAnalyzer::BLOCKED_INTENTS) !== []) {
				continue;
			}

			$seeds[$key] = ['phrase' => mb_strtolower($phrase), 'source' => $source];

			if (count($seeds) >= $limit) {
				break;
			}
		}

		return array_values($seeds);
	}
}
