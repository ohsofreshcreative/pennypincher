<?php

namespace App\PennyPincher\Domain\Planner;

/**
 * Konflikty wykluczeń z planowanymi słowami kluczowymi według zasad Google Ads dla wykluczających słów kluczowych:
 * - przybliżone: zapytanie zawiera wszystkie słowa wykluczenia (w dowolnej kolejności),
 * - do wyrażenia: zapytanie zawiera słowa wykluczenia w tej samej kolejności, obok siebie,
 * - ścisłe: zapytanie jest dokładnie takie jak wykluczenie.
 * Wykluczenia nie obejmują bliskich odmian (liczba mnoga, literówki), więc porównujemy dokładne słowa (bez wielkości liter).
 *
 * Planowane słowo traktujemy jak zapytanie, które ma się wyświetlać – jeśli wykluczenie by je zablokowało, to konflikt.
 * Dodatkowo ostrzegamy przed wykluczeniem pojedynczego słowa obecnego w wielu planowanych frazach (np. „projektowanie”).
 */
final class NegativeConflictChecker
{
	/**
	 * @param array<int, array{phrase: string, match_type: string, level: string, plan_campaign_id: ?int, plan_ad_group_id: ?int, id?: int}> $negatives
	 * @param array<int, array{phrase: string, plan_ad_group_id: int, plan_campaign_id: int, id?: int}> $keywords
	 * @return array<int, array{negative: array, keywords: array<int, array>, message: string, severity: string}>
	 */
	public static function conflicts(array $negatives, array $keywords): array
	{
		$conflicts = [];

		foreach ($negatives as $negative) {
			$blocked = [];

			foreach ($keywords as $keyword) {
				if (! self::applies($negative, $keyword)) {
					continue;
				}

				if (self::blocks((string) $negative['phrase'], (string) $negative['match_type'], (string) $keyword['phrase'])) {
					$blocked[] = $keyword;
				}
			}

			if ($blocked === []) {
				continue;
			}

			$words = self::words((string) $negative['phrase']);
			$generic = count($words) === 1 && count($blocked) >= 2;

			$conflicts[] = [
				'negative' => $negative,
				'keywords' => $blocked,
				'severity' => $generic || count($blocked) >= 3 ? 'high' : 'medium',
				'message' => sprintf(
					'Wykluczenie %s zablokuje %d %s: %s.%s',
					MatchType::notation((string) $negative['phrase'], (string) $negative['match_type']),
					count($blocked),
					count($blocked) === 1 ? 'planowane słowo' : 'planowanych słów',
					implode(', ', array_map(fn ($k) => '„' . $k['phrase'] . '”', array_slice($blocked, 0, 5))) . (count($blocked) > 5 ? '…' : ''),
					$generic ? ' To pojedyncze słowo obecne w wielu frazach – rozważ dłuższe wykluczenie albo dopasowanie ścisłe.' : ''
				),
			];
		}

		return $conflicts;
	}

	/**
	 * Czy wykluczenie zablokowałoby zapytanie o treści $query.
	 */
	public static function blocks(string $negative, string $matchType, string $query): bool
	{
		$neg = self::words($negative);
		$q = self::words($query);

		if ($neg === [] || $q === []) {
			return false;
		}

		return match ($matchType) {
			MatchType::EXACT => $neg === $q,
			MatchType::PHRASE => self::containsSequence($q, $neg),
			default => array_diff($neg, $q) === [],
		};
	}

	private static function applies(array $negative, array $keyword): bool
	{
		return match ($negative['level']) {
			'campaign' => (int) $negative['plan_campaign_id'] === (int) $keyword['plan_campaign_id'],
			'ad_group' => (int) $negative['plan_ad_group_id'] === (int) $keyword['plan_ad_group_id'],
			default => true,
		};
	}

	/**
	 * @return array<int, string>
	 */
	public static function words(string $text): array
	{
		$text = mb_strtolower(trim($text));
		$text = (string) preg_replace('/[\[\]"+]/u', '', $text);

		return array_values(array_filter(preg_split('/[^\p{L}\p{N}&\-\.]+/u', $text) ?: [], fn ($w) => $w !== ''));
	}

	private static function containsSequence(array $haystack, array $needle): bool
	{
		$n = count($needle);

		for ($i = 0; $i + $n <= count($haystack); $i++) {
			if (array_slice($haystack, $i, $n) === $needle) {
				return true;
			}
		}

		return false;
	}
}
