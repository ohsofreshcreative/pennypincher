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

	/** @var array<int|string, array{0: int, 1: float}>|null */
	private ?array $rates = null;

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
		private ?\Closure $fx = null,
		private bool $measurementReliable = true,
	) {
	}

	/**
	 * Współczynnik konwersji kampanii (min. 50 kliknięć) albo konta – punkt odniesienia dla wyników historycznych frazy.
	 */
	private function referenceRate(?int $campaignId): ?float
	{
		if ($this->rates === null) {
			$this->rates = ['all' => [0, 0.0]];

			foreach ($this->terms as $term) {
				$c = (int) $term['campaign_id'];
				$this->rates[$c] ??= [0, 0.0];
				$this->rates[$c][0] += (int) $term['clicks'];
				$this->rates[$c][1] += (float) ($term['conversions'] ?? 0);
				$this->rates['all'][0] += (int) $term['clicks'];
				$this->rates['all'][1] += (float) ($term['conversions'] ?? 0);
			}
		}

		[$clicks, $conversions] = $campaignId !== null ? ($this->rates[$campaignId] ?? [0, 0.0]) : [0, 0.0];

		if ($clicks >= 50 && $conversions > 0) {
			return $conversions / $clicks;
		}

		[$clicks, $conversions] = $this->rates['all'];

		return $clicks > 0 && $conversions > 0 ? $conversions / $clicks : null;
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

		// Pojęcia tego, co konto już targetuje (aktywne lub konwertujące słowa kluczowe) – drugi punkt odniesienia
		// dla dopasowania do oferty, gdy opis oferty jest ogólny albo go brak.
		$working = [];

		foreach ($this->keywords as $keyword) {
			if (in_array($keyword['status'] ?? 'enabled', ['enabled', null], true) || ($keyword['conversions'] ?? 0) > 0) {
				array_push($working, ...Concepts::of($keyword['text']));
			}
		}

		// Nazwy kampanii i grup reklam też opisują, co konto reklamuje (np. „Stoły dębowe”).
		foreach ([...array_values($this->campaignNames), ...array_map(fn ($g) => (string) $g['name'], $this->adGroups)] as $name) {
			array_push($working, ...Concepts::of($name));
		}

		$working = array_values(array_unique($working));
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

			$idea = $candidate['idea'];
			$term = $candidate['term'];
			$flags = PhraseQuality::flags($candidate['phrase'], $this->context, $working);
			$fromSearchTerms = isset($candidate['sources']['search_terms']);

			// Automatyczne pomysły DataForSEO w innym języku albo wyglądające na nazwy firm odrzucamy (z własnych haseł – tylko kara).
			if (! $fromSearchTerms && array_intersect($flags, [PhraseQuality::WRONG_LANGUAGE, PhraseQuality::COMPANY_NAME]) !== []) {
				continue;
			}

			// Dopasowanie do oferty: pojęcia z synonimami; bez opisu oferty – podobieństwo do tego, co już konwertuje.
			$fit = PhraseQuality::offerFit($candidate['phrase'], $this->context, $working);

			if ($fit !== null && $priorityStems !== [] && Text::overlap($candidate['phrase'], $priorityStems) > 0) {
				$fit = min(1.0, $fit + 0.2);
			}

			// CPC: z historii konta (waluta projektu) albo z DataForSEO (oryginalna waluta + przeliczenie po kursie z datą i źródłem).
			$cpc = null;
			$cpcCurrency = null;
			$cpcProject = null;
			$fx = null;

			if ($term !== null && $term['clicks'] > 0) {
				$cpc = (int) round($term['cost_micros'] / $term['clicks']);
				$cpcCurrency = $this->currency;
				$cpcProject = $cpc;
			} elseif (($idea['cpc_micros'] ?? null) !== null) {
				$cpc = (int) $idea['cpc_micros'];
				$cpcCurrency = (string) ($idea['cpc_currency'] ?? 'USD');

				if ($cpcCurrency === $this->currency) {
					$cpcProject = $cpc;
				} elseif ($this->fx !== null) {
					$fx = ($this->fx)($cpc, $cpcCurrency);
					$cpcProject = $fx['micros'] ?? null;
				}
			}

			$referenceRate = $term !== null ? $this->referenceRate($term['campaign_id'] ?? null) : null;
			$score = OpportunityScoreV2::calculate([
				'offer_fit' => $fit,
				'intent' => OpportunityScoreV2::intent($primary, $candidate['phrase']),
				'performance' => $term !== null ? OpportunityScoreV2::performance((int) $term['clicks'], $term['conversions'] === null ? null : (float) $term['conversions'], $referenceRate) : null,
				'audience' => PhraseQuality::audienceFit($candidate['phrase'], $this->context),
				'coverage' => $status === null ? 1.0 : 0.5,
				'cpc' => OpportunityScore::cpc($cpcProject, $cpcProject !== null ? $this->currency : null, $this->averageCpcMicros, $this->currency),
				'volume' => OpportunityScore::volume(isset($idea['search_volume']) ? (int) $idea['search_volume'] : null),
				'competition' => OpportunityScoreV2::competition($idea['competition'] ?? null),
			], $flags, [
				'term_clicks' => $term !== null ? (int) $term['clicks'] : null,
				'measurement_reliable' => $this->measurementReliable,
				'intent_known' => $primary !== 'unknown',
			]);

			if ($score['score'] < self::MIN_SCORE && ! $fromSearchTerms) {
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

			if ($cpcCurrency !== null && $cpcCurrency !== $this->currency && $cpcProject === null) {
				$reasons[] = sprintf('CPC w %s – brak aktualnego kursu do %s, porównanie z kontem niedostępne.', $cpcCurrency, $this->currency);
			}

			$opportunities[] = [
				'phrase' => mb_substr($candidate['phrase'], 0, 255),
				'normalized' => $key,
				'sources' => implode(',', array_keys($candidate['sources'])),
				'score' => $score['score'],
				'score_version' => OpportunityScoreV2::VERSION,
				'score_breakdown' => $score['breakdown'],
				'confidence' => $score['confidence'],
				'confidence_score' => $score['confidence_score'],
				'explanation' => [
					'strengths' => $score['strengths'],
					'risks' => $score['risks'],
					'missing' => $score['missing'],
					'action' => $score['action'],
				],
				'quality_flags' => $flags === [] ? null : implode(',', $flags),
				'intent' => $primary,
				'search_volume' => isset($idea['search_volume']) ? (int) $idea['search_volume'] : null,
				'cpc_micros' => $cpc,
				'cpc_currency' => $cpcCurrency,
				'cpc_project_micros' => $cpcProject,
				'fx_rate' => $fx['rate'] ?? null,
				'fx_date' => $fx['rate_date'] ?? null,
				'fx_source' => $fx['source'] ?? null,
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
