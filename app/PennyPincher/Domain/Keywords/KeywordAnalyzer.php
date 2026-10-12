<?php

namespace App\PennyPincher\Domain\Keywords;

use App\PennyPincher\Domain\Analysis\Statistics;
use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Support\Format;

/**
 * Keyword Opportunities i propozycje wykluczeń – deterministycznie, na danych Google Ads, kontekście projektu
 * i (opcjonalnie) zapisanych wynikach DataForSEO. Nie wykonuje zapytań do API i nie zmienia konta Google Ads.
 */
final class KeywordAnalyzer
{
	public const MAX_OPPORTUNITIES = 300;

	public const MIN_SCORE = 20;

	/** Kategorie intencji, których fraz nie proponujemy jako nowych słów kluczowych. */
	public const BLOCKED_INTENTS = ['excluded', 'jobs', 'free', 'education', 'diy'];

	/**
	 * @param array<int, array<string, mixed>> $terms wyszukiwane hasła z sumami w okresie
	 * @param array<int, array<string, mixed>> $keywords wszystkie słowa kluczowe projektu (z ewentualnymi metrykami w okresie)
	 * @param array<int, array<string, mixed>> $negatives słowa wykluczające z Google Ads
	 * @param array<int, array<string, mixed>> $adGroups
	 * @param array<int, string> $campaignNames
	 * @param array<int, array<string, mixed>> $ideas pomysły z DataForSEO (keyword_ideas)
	 */
	public function __construct(
		private array $terms,
		private array $keywords,
		private array $negatives,
		private array $adGroups,
		private array $campaignNames,
		private BusinessContext $context,
		private array $ideas,
		private ?int $averageCpcMicros,
		private string $currency,
		private int $days,
	) {
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function opportunities(): array
	{
		$existing = [];

		foreach ($this->keywords as $keyword) {
			$existing[Text::normalize($keyword['text'])] = $keyword['status'] ?? 'enabled';
		}

		$offerStems = [];

		foreach ($this->context->offerTexts() as $text) {
			array_push($offerStems, ...Text::stems($text));
		}

		$priorityStems = [];

		foreach ($this->context->priorityTexts() as $text) {
			array_push($priorityStems, ...Text::stems($text));
		}

		// Rdzenie skutecznych słów kluczowych i haseł z konwersjami – dopasowanie do tego, co już działa.
		$converting = [];

		foreach ($this->terms as $term) {
			if (($term['conversions'] ?? 0) > 0) {
				array_push($converting, ...Text::stems($term['term']));
			}
		}

		foreach ($this->keywords as $keyword) {
			if (($keyword['conversions'] ?? 0) > 0) {
				array_push($converting, ...Text::stems($keyword['text']));
			}
		}

		$offerStems = array_values(array_unique($offerStems));
		$priorityStems = array_values(array_unique($priorityStems));
		$converting = array_values(array_unique($converting));
		$candidates = [];

		foreach ($this->terms as $term) {
			if (($term['conversions'] ?? 0) <= 0) {
				continue;
			}

			$key = Text::normalize($term['term']);
			$candidates[$key] ??= ['phrase' => $term['term'], 'sources' => [], 'term' => null, 'idea' => null];
			$candidates[$key]['sources']['search_terms'] = true;
			$candidates[$key]['term'] = $this->mergeTerm($candidates[$key]['term'], $term);
		}

		foreach ($this->ideas as $idea) {
			$key = Text::normalize($idea['keyword']);

			if ($key === '') {
				continue;
			}

			$candidates[$key] ??= ['phrase' => $idea['keyword'], 'sources' => [], 'term' => null, 'idea' => null];
			$candidates[$key]['sources']['dataforseo'] = true;
			$candidates[$key]['idea'] = $idea;
		}

		$opportunities = [];

		foreach ($candidates as $key => $candidate) {
			$status = $existing[$key] ?? null;

			if ($status !== null && $status !== 'removed' && $status !== 'paused') {
				continue; // fraza już jest aktywnym słowem kluczowym
			}

			if ($this->isExcluded($candidate['phrase'])) {
				continue; // fraza jest wykluczona w Google Ads
			}

			$intents = Text::intents($candidate['phrase'], $this->context->excludedAudiences());
			$primary = Text::primaryIntent($intents);

			if (array_intersect($intents, self::BLOCKED_INTENTS) !== []) {
				continue;
			}

			$reference = array_values(array_unique([...$offerStems, ...$converting]));
			$fit = $reference === [] ? null : Text::overlap($candidate['phrase'], $reference);

			if ($fit !== null && $priorityStems !== [] && Text::overlap($candidate['phrase'], $priorityStems) > 0) {
				$fit = min(1.0, $fit + 0.3);
			}

			$idea = $candidate['idea'];
			$term = $candidate['term'];
			$cpc = $idea['cpc_micros'] ?? null;
			$cpcCurrency = $idea !== null ? 'USD' : null;

			if ($term !== null && $term['clicks'] > 0) {
				$cpc = (int) round($term['cost_micros'] / $term['clicks']);
				$cpcCurrency = $this->currency;
			}

			$score = OpportunityScore::calculate([
				'offer_fit' => $fit,
				'intent' => OpportunityScore::intent($primary),
				'cpc' => OpportunityScore::cpc($cpc !== null ? (int) $cpc : null, $cpcCurrency, $this->averageCpcMicros, $this->currency),
				'volume' => OpportunityScore::volume(isset($idea['search_volume']) ? (int) $idea['search_volume'] : null),
				'coverage' => $status === null ? 1.0 : 0.5,
			]);

			if ($score['score'] < self::MIN_SCORE) {
				continue;
			}

			[$campaignId, $adGroupId] = $this->suggestPlacement($candidate['phrase'], $term);
			$reasons = [];

			if ($term !== null) {
				$reasons[] = sprintf('Zapytanie przyniosło %s konwersji Google Ads przy koszcie %s, a nie jest osobnym słowem kluczowym.', Format::conversions($term['conversions']), Format::money($term['cost_micros'], $this->currency));
			}

			if ($idea !== null) {
				$reasons[] = 'Powiązana fraza z DataForSEO' . ($idea['seed'] ? ' (dla „' . $idea['seed'] . '”)' : '') . '.';
			}

			if ($status !== null) {
				$reasons[] = 'Fraza istnieje jako wstrzymane słowo kluczowe.';
			}

			if ($fit === null) {
				$reasons[] = 'Brak opisu oferty w kontekście projektu – dopasowanie nieocenione.';
			}

			$opportunities[] = [
				'phrase' => mb_substr($candidate['phrase'], 0, 255),
				'normalized' => $key,
				'sources' => implode(',', array_keys($candidate['sources'])),
				'score' => $score['score'],
				'score_breakdown' => $score['breakdown'],
				'confidence' => $score['confidence'],
				'intent' => $primary,
				'search_volume' => isset($idea['search_volume']) ? (int) $idea['search_volume'] : null,
				'cpc_micros' => $cpc !== null ? (int) $cpc : null,
				'cpc_currency' => $cpc !== null ? $cpcCurrency : null,
				'competition' => $idea['competition'] ?? null,
				'suggested_campaign_id' => $campaignId,
				'suggested_ad_group_id' => $adGroupId,
				'reason' => implode(' ', $reasons),
				'evidence' => array_filter([
					'term' => $term ? ['clicks' => $term['clicks'], 'cost_micros' => $term['cost_micros'], 'conversions' => $term['conversions']] : null,
					'missing' => $score['missing'],
					'existing_status' => $status,
				]),
				'data_updated_at' => $idea['fetched_at'] ?? null,
			];
		}

		usort($opportunities, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['phrase'], $b['phrase']));

		return array_slice($opportunities, 0, self::MAX_OPPORTUNITIES);
	}

	/**
	 * Propozycje wykluczeń: tematy o niskiej intencji (z kontekstu i słowników) oraz kosztowne hasła bez konwersji.
	 * Zapytania informacyjne proponujemy ostrożnie (niska pewność) – nie każde jest bezwartościowe.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function negatives(int $minCostMicros): array
	{
		$groups = [];
		$excluded = $this->context->excludedAudiences();

		foreach ($this->terms as $term) {
			if (($term['conversions'] ?? null) === null || $term['conversions'] > 0 || $term['clicks'] < 1) {
				continue;
			}

			$intents = Text::intents($term['term'], $excluded);

			foreach (['excluded', 'jobs', 'free', 'education', 'diy', 'informational'] as $intent) {
				if (! in_array($intent, $intents, true)) {
					continue;
				}

				$token = $this->matchingToken($term['term'], $intent, $excluded);

				if ($token === null) {
					continue;
				}

				$groups[$intent . '|' . $token] ??= ['phrase' => $token, 'reason_code' => $intent, 'terms' => []];
				$groups[$intent . '|' . $token]['terms'][] = $term;
				break;
			}
		}

		$suggestions = [];

		foreach ($groups as $group) {
			$cost = array_sum(array_column($group['terms'], 'cost_micros'));
			$clicks = array_sum(array_column($group['terms'], 'clicks'));
			$informational = $group['reason_code'] === 'informational';

			if ($informational && ($cost < $minCostMicros || $clicks < 5)) {
				continue;
			}

			$confidence = match (true) {
				$informational => Statistics::LOW,
				$group['reason_code'] === 'excluded', count($group['terms']) >= 3, $clicks >= 10 => Statistics::MEDIUM,
				default => Statistics::LOW,
			};

			if (! $informational && in_array($group['reason_code'], ['jobs', 'free'], true) && $clicks >= 10) {
				$confidence = Statistics::HIGH;
			}

			$suggestions[] = $this->negativeRow($group['phrase'], $group['reason_code'], $group['terms'], $cost, $clicks, $confidence, 'phrase');
		}

		// Pojedyncze kosztowne hasła bez konwersji, nieobjęte tematami powyżej – wykluczenie ścisłe do decyzji.
		$covered = [];

		foreach ($groups as $group) {
			foreach ($group['terms'] as $term) {
				$covered[$term['id']] = true;
			}
		}

		foreach ($this->terms as $term) {
			if (isset($covered[$term['id']]) || ($term['conversions'] ?? null) === null || $term['conversions'] > 0 || $term['clicks'] < 10 || $term['cost_micros'] < $minCostMicros) {
				continue;
			}

			$suggestions[] = $this->negativeRow($term['term'], 'no_conversions', [$term], $term['cost_micros'], $term['clicks'], Statistics::LOW, 'exact');
		}

		usort($suggestions, fn ($a, $b) => $a['already_excluded'] <=> $b['already_excluded'] ?: $b['cost_micros'] <=> $a['cost_micros']);

		return array_slice($suggestions, 0, 200);
	}

	private function negativeRow(string $phrase, string $reasonCode, array $terms, int $cost, int $clicks, string $confidence, string $matchType): array
	{
		$campaigns = array_values(array_unique(array_column($terms, 'campaign_id')));
		usort($terms, fn ($a, $b) => $b['cost_micros'] <=> $a['cost_micros']);
		$reasons = [
			'excluded' => 'Pasuje do niepożądanych odbiorców z kontekstu projektu.',
			'jobs' => 'Zapytania o pracę – zwykle nie szukają usług ani produktów.',
			'free' => 'Zapytania o darmowe rozwiązania.',
			'education' => 'Zapytania edukacyjne (kursy, nauka).',
			'diy' => 'Zapytania „zrób to sam” (kreatory, szablony, samodzielne wykonanie).',
			'informational' => 'Zapytania informacyjne bez konwersji. Uwaga: część takich zapytań bywa wartościowa – sprawdź przed wykluczeniem.',
			'no_conversions' => 'Kosztowne zapytanie bez konwersji – wyklucz tylko, jeśli nie pasuje do oferty.',
		];

		return [
			'phrase' => mb_substr($phrase, 0, 255),
			'reason_code' => $reasonCode,
			'reason' => $reasons[$reasonCode],
			'related_terms' => array_map(fn ($t) => ['term' => $t['term'], 'cost_micros' => $t['cost_micros'], 'clicks' => $t['clicks'], 'campaign' => $this->campaignNames[$t['campaign_id']] ?? '—'], array_slice($terms, 0, 10)),
			'cost_micros' => $cost,
			'clicks' => $clicks,
			'conversions' => 0,
			'confidence' => $confidence,
			'level' => count($campaigns) > 1 ? 'account' : 'campaign',
			'campaign_id' => count($campaigns) === 1 ? $campaigns[0] : null,
			'match_type' => $matchType,
			'already_excluded' => $this->isExcluded($phrase) ? 1 : 0,
		];
	}

	/**
	 * Czy fraza jest już objęta wykluczeniem (ścisłym, do wyrażenia albo przybliżonym) w Google Ads.
	 */
	public function isExcluded(string $phrase): bool
	{
		$normalized = ' ' . Text::normalize($phrase) . ' ';

		foreach ($this->negatives as $negative) {
			$text = Text::normalize((string) $negative['text']);

			if ($text === '') {
				continue;
			}

			$matched = match ($negative['match_type'] ?? 'broad') {
				'exact' => trim($normalized) === $text,
				'phrase' => str_contains($normalized, ' ' . $text . ' '),
				default => array_diff(explode(' ', $text), explode(' ', trim($normalized))) === [],
			};

			if ($matched) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Słowo z hasła odpowiadające wzorcowi intencji (np. „darmowa” dla wzorca „darmow”).
	 */
	private function matchingToken(string $term, string $intent, array $excluded): ?string
	{
		$normalized = Text::normalize($term);
		$patterns = $intent === 'excluded' ? $excluded : Text::PATTERNS[$intent];

		foreach ($patterns as $pattern) {
			$p = Text::normalize((string) $pattern);

			if ($p === '' || ! Text::containsPattern(' ' . $normalized . ' ', $p)) {
				continue;
			}

			if (str_contains($p, ' ')) {
				return $p; // wzorzec wielowyrazowy, np. „oferty pracy”
			}

			foreach (explode(' ', $normalized) as $word) {
				if (str_starts_with($word, $p)) {
					return $word;
				}
			}
		}

		return null;
	}

	/**
	 * @return array{0: ?int, 1: ?int}
	 */
	private function suggestPlacement(string $phrase, ?array $term): array
	{
		if ($term !== null && $term['ad_group_id'] !== null) {
			return [$term['campaign_id'], $term['ad_group_id']];
		}

		$best = null;
		$bestScore = 0.0;
		$stemsByGroup = [];

		foreach ($this->keywords as $keyword) {
			if ($keyword['ad_group_id'] !== null && ($keyword['status'] ?? 'enabled') !== 'removed') {
				$stemsByGroup[$keyword['ad_group_id']] = array_values(array_unique(array_merge($stemsByGroup[$keyword['ad_group_id']] ?? [], Text::stems($keyword['text']))));
			}
		}

		foreach ($stemsByGroup as $groupId => $stems) {
			$score = Text::overlap($phrase, $stems);

			if ($score > $bestScore) {
				$bestScore = $score;
				$best = $groupId;
			}
		}

		return $best !== null ? [$this->adGroups[$best]['campaign_id'] ?? null, $best] : [null, null];
	}

	private function mergeTerm(?array $existing, array $term): array
	{
		if ($existing === null) {
			return $term;
		}

		$existing['clicks'] += $term['clicks'];
		$existing['cost_micros'] += $term['cost_micros'];
		$existing['conversions'] = (float) $existing['conversions'] + (float) $term['conversions'];

		if ($term['cost_micros'] > ($existing['_max_cost'] ?? 0)) {
			$existing['campaign_id'] = $term['campaign_id'];
			$existing['ad_group_id'] = $term['ad_group_id'];
			$existing['_max_cost'] = $term['cost_micros'];
		}

		return $existing;
	}
}
