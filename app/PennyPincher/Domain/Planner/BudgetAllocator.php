<?php

namespace App\PennyPincher\Domain\Planner;

use App\PennyPincher\Domain\Metrics\Money;

/**
 * Budget Planner: podział miesięcznego budżetu planu na kampanie i orientacyjne prognozy.
 *
 * Podział nie opiera się na liczbie kliknięć. Kampania jest „sprawdzona”, gdy jej frazy mają udokumentowane wyniki
 * w Google Ads (min. 100 kliknięć i 3 konwersje), w przeciwnym razie to „test”. Scenariusz decyduje, jaka część budżetu
 * trafia do kampanii sprawdzonych, a jaka do testów, oraz jak ważone są: priorytet biznesowy, potencjał fraz
 * (Opportunity Score, popyt) i historyczne wyniki; niepewność obniża udział (szczególnie w scenariuszu ostrożnym).
 * Nowa fraza nie jest z założenia lepsza od sprawdzonej.
 *
 * Prognozy to zakresy: CPC z historii konta albo z DataForSEO (przeliczone kursem), kliknięcia = budżet / CPC.
 * Konwersje i koszt pozyskania liczymy tylko przy wiarygodnym pomiarze i udokumentowanej historii – inaczej komunikat.
 */
final class BudgetAllocator
{
	public const DAYS_PER_MONTH = 30.4;

	public const NO_LEAD_FORECAST = 'Brak wystarczających danych do wiarygodnej prognozy kosztu pozyskania leada.';

	public const PROVEN_MIN_CLICKS = 100;

	public const PROVEN_MIN_CONVERSIONS = 3;

	/** Domyślne scenariusze (konfigurowalne w planie): udział sprawdzonych kampanii i wagi czynników. */
	public const SCENARIOS = [
		'cautious' => ['label' => 'Ostrożny', 'proven_share' => 0.85, 'priority' => 0.2, 'potential' => 0.3, 'performance' => 0.5, 'uncertainty_penalty' => 0.5, 'max_share' => 0.6,
			'description' => 'Większość budżetu na kampanie z udokumentowanymi wynikami, ograniczone testy.'],
		'balanced' => ['label' => 'Zrównoważony', 'proven_share' => 0.7, 'priority' => 0.25, 'potential' => 0.4, 'performance' => 0.35, 'uncertainty_penalty' => 0.3, 'max_share' => 0.6,
			'description' => 'Sprawdzone kampanie plus testy nowych grup słów.'],
		'experimental' => ['label' => 'Eksperymentalny', 'proven_share' => 0.5, 'priority' => 0.25, 'potential' => 0.55, 'performance' => 0.2, 'uncertainty_penalty' => 0.1, 'max_share' => 0.7,
			'description' => 'Większa część budżetu na nowe grupy słów kluczowych – wyższe ryzyko.'],
	];

	/**
	 * Konfiguracja scenariusza z nadpisaniami użytkownika (wartości 0–1, wagi normalizowane).
	 */
	public static function config(string $scenario, array $overrides = []): array
	{
		$config = (self::SCENARIOS[$scenario] ?? self::SCENARIOS['balanced']) + ['key' => $scenario];

		foreach (['proven_share', 'priority', 'potential', 'performance', 'uncertainty_penalty', 'max_share'] as $key) {
			if (isset($overrides[$key]) && is_numeric($overrides[$key])) {
				$config[$key] = max(0.0, min(1.0, (float) $overrides[$key]));
			}
		}

		$config['max_share'] = max(0.2, $config['max_share']);
		$sum = $config['priority'] + $config['potential'] + $config['performance'];

		foreach (['priority', 'potential', 'performance'] as $key) {
			$config[$key] = $sum > 0 ? $config[$key] / $sum : 1 / 3;
		}

		return $config;
	}

	/**
	 * Ocena kampanii planu na podstawie jej słów kluczowych.
	 *
	 * @param array<int, array<string, mixed>> $keywords
	 * @return array{evidence: string, clicks: int, conversions: float, cost: int, potential: float, performance: ?float, uncertainty: float, cpc: ?int, cpc_source: ?string, keywords: int}
	 */
	public static function profile(array $keywords, ?float $accountRate = null): array
	{
		$clicks = 0;
		$conversions = 0.0;
		$cost = 0;
		$scores = [];
		$volume = 0;
		$uncertain = 0;
		$historyCpc = [];
		$marketCpc = [];

		foreach ($keywords as $keyword) {
			$clicks += (int) ($keyword['term_clicks'] ?? 0);
			$conversions += (float) ($keyword['term_conversions'] ?? 0);
			$cost += (int) ($keyword['term_cost_micros'] ?? 0);

			if (($keyword['score'] ?? null) !== null) {
				$scores[] = (int) $keyword['score'];
			}

			$volume += (int) ($keyword['search_volume'] ?? 0);

			if (($keyword['term_clicks'] ?? null) === null && ($keyword['confidence'] ?? 'low') !== 'high') {
				$uncertain++;
			}

			if ((int) ($keyword['term_clicks'] ?? 0) > 0 && (int) ($keyword['term_cost_micros'] ?? 0) > 0) {
				$historyCpc[] = (int) round($keyword['term_cost_micros'] / $keyword['term_clicks']);
			} elseif (($keyword['cpc_project_micros'] ?? null) !== null) {
				$marketCpc[] = (int) $keyword['cpc_project_micros'];
			}
		}

		rsort($scores);
		$top = array_slice($scores, 0, 5);
		$potential = $top === [] ? 0.3 : (array_sum($top) / count($top)) / 100;

		if ($volume > 0) {
			$potential = min(1.0, $potential * 0.8 + 0.2 * min(1.0, log10($volume + 1) / 4));
		}

		$performance = null;

		if ($clicks >= 20) {
			$rate = $conversions / $clicks;
			$performance = $accountRate !== null && $accountRate > 0 ? max(0.0, min(1.0, 0.5 * $rate / $accountRate)) : ($conversions > 0 ? 0.6 : 0.2);
		}

		$cpc = null;
		$cpcSource = null;

		if ($clicks > 0 && $cost > 0) {
			$cpc = (int) round($cost / $clicks);
			$cpcSource = 'historia konta';
		} elseif ($marketCpc !== []) {
			sort($marketCpc);
			$cpc = $marketCpc[intdiv(count($marketCpc), 2)];
			$cpcSource = 'DataForSEO (przeliczone)';
		}

		$evidence = $clicks >= self::PROVEN_MIN_CLICKS && $conversions >= self::PROVEN_MIN_CONVERSIONS ? 'proven' : 'test';

		return [
			'evidence' => $evidence,
			'clicks' => $clicks,
			'conversions' => $conversions,
			'cost' => $cost,
			'potential' => $potential,
			'performance' => $performance,
			'uncertainty' => count($keywords) === 0 ? 1.0 : $uncertain / count($keywords),
			'cpc' => $cpc,
			'cpc_source' => $cpcSource,
			'keywords' => count($keywords),
		];
	}

	/**
	 * Proponowany podział budżetu.
	 *
	 * @param array<int, array{id: int, name: string, priority: bool, profile: array}> $campaigns
	 * @return array{allocations: array<int, int>, shares: array<int, float>, notes: array<int, string>, buckets: array<string, int>}
	 */
	public static function allocate(int $budgetMicros, array $campaigns, array $config): array
	{
		$notes = [];

		if ($campaigns === [] || $budgetMicros <= 0) {
			return ['allocations' => [], 'shares' => [], 'notes' => $budgetMicros <= 0 ? ['Ustaw budżet miesięczny planu.'] : ['Dodaj kampanie do planu.'], 'buckets' => ['proven' => 0, 'test' => 0]];
		}

		$proven = array_filter($campaigns, fn ($c) => $c['profile']['evidence'] === 'proven');
		$test = array_filter($campaigns, fn ($c) => $c['profile']['evidence'] !== 'proven');
		$provenShare = $config['proven_share'];

		if ($proven === []) {
			$provenShare = 0.0;
			$notes[] = 'Żadna kampania nie ma udokumentowanych wyników (min. ' . self::PROVEN_MIN_CLICKS . ' kliknięć i ' . self::PROVEN_MIN_CONVERSIONS . ' konwersji) – cały budżet to test. Zacznij ostrożnie i oceniaj wyniki po 2–4 tygodniach.';
		} elseif ($test === []) {
			$provenShare = 1.0;
		}

		$buckets = ['proven' => (int) round($budgetMicros * $provenShare), 'test' => $budgetMicros - (int) round($budgetMicros * $provenShare)];
		$weights = [];

		foreach ($campaigns as $campaign) {
			$p = $campaign['profile'];
			$performance = $p['performance'] ?? 0.5; // brak historii = neutralnie (nie zakładamy, że nowe jest lepsze)
			$weight = $config['priority'] * ($campaign['priority'] ? 1.0 : 0.4) + $config['potential'] * $p['potential'] + $config['performance'] * $performance;
			$weight *= 1 - $config['uncertainty_penalty'] * $p['uncertainty'];
			$weights[$campaign['id']] = max(0.05, $weight);
		}

		$allocations = [];

		foreach (['proven' => $proven, 'test' => $test] as $bucket => $members) {
			if ($members === [] || $buckets[$bucket] <= 0) {
				continue;
			}

			$sum = array_sum(array_map(fn ($c) => $weights[$c['id']], $members));

			foreach ($members as $campaign) {
				$allocations[$campaign['id']] = (int) floor($buckets[$bucket] * $weights[$campaign['id']] / $sum);
			}
		}

		// Ograniczenie maksymalnego udziału jednej kampanii (dywersyfikacja ryzyka) – nadwyżka do pozostałych.
		$cap = (int) floor($budgetMicros * $config['max_share']);

		if (count($allocations) > 1) {
			for ($i = 0; $i < 5; $i++) {
				$excess = 0;

				foreach ($allocations as $id => $amount) {
					if ($amount > $cap) {
						$excess += $amount - $cap;
						$allocations[$id] = $cap;
					}
				}

				if ($excess === 0) {
					break;
				}

				$open = array_filter($allocations, fn ($a) => $a < $cap);
				$sum = array_sum(array_map(fn ($id) => $weights[$id], array_keys($open)));

				foreach (array_keys($open) as $id) {
					$allocations[$id] += (int) floor($excess * $weights[$id] / max(0.0001, $sum));
				}
			}
		}

		// Zaokrąglenie do pełnych jednostek waluty; reszta do największej kampanii.
		foreach ($allocations as $id => $amount) {
			$allocations[$id] = intdiv($amount, Money::MICROS) * Money::MICROS;
		}

		$rest = $budgetMicros - array_sum($allocations);

		if ($rest > 0 && $allocations !== []) {
			$largest = array_search(max($allocations), $allocations, true);
			$allocations[$largest] += $rest;
		}

		return [
			'allocations' => $allocations,
			'shares' => array_map(fn ($a) => $a / $budgetMicros, $allocations),
			'notes' => $notes,
			'buckets' => $buckets,
		];
	}

	/**
	 * Prognoza kampanii (zakresy). Konwersje tylko przy wiarygodnym pomiarze i udokumentowanej historii.
	 *
	 * @return array<string, mixed>
	 */
	public static function forecast(?int $budgetMicros, array $profile, bool $measurementReliable): array
	{
		$daily = $budgetMicros !== null ? (int) round($budgetMicros / self::DAYS_PER_MONTH) : null;
		$cpc = $profile['cpc'];
		$result = [
			'monthly' => $budgetMicros,
			'daily' => $daily,
			'cpc' => $cpc,
			'cpc_source' => $profile['cpc_source'],
			'cpc_low' => $cpc !== null ? (int) round($cpc * 0.8) : null,
			'cpc_high' => $cpc !== null ? (int) round($cpc * 1.3) : null,
			'clicks_low' => null,
			'clicks_high' => null,
			'conversions_low' => null,
			'conversions_high' => null,
			'cpl_low' => null,
			'cpl_high' => null,
			'lead_message' => self::NO_LEAD_FORECAST,
			'uncertainty' => 'high',
		];

		if ($budgetMicros === null || $budgetMicros <= 0 || $cpc === null || $cpc <= 0) {
			return $result;
		}

		$result['clicks_low'] = (int) floor($budgetMicros / $result['cpc_high']);
		$result['clicks_high'] = (int) floor($budgetMicros / $result['cpc_low']);
		$result['uncertainty'] = $profile['evidence'] === 'proven' ? ($profile['clicks'] >= 500 ? 'low' : 'medium') : 'high';

		if ($measurementReliable && $profile['evidence'] === 'proven' && $profile['clicks'] > 0) {
			[$low, $high] = self::wilson($profile['conversions'], $profile['clicks']);
			$result['conversions_low'] = round($result['clicks_low'] * $low, 1);
			$result['conversions_high'] = round($result['clicks_high'] * $high, 1);

			if ($result['conversions_low'] >= 1) {
				$result['cpl_high'] = (int) round($budgetMicros / $result['conversions_low']);
				$result['cpl_low'] = (int) round($budgetMicros / max(0.1, $result['conversions_high']));
				$result['lead_message'] = null;
			}
		}

		return $result;
	}

	/**
	 * Przedział Wilsona (80%) dla współczynnika konwersji.
	 *
	 * @return array{0: float, 1: float}
	 */
	public static function wilson(float $conversions, int $clicks, float $z = 1.2816): array
	{
		if ($clicks <= 0) {
			return [0.0, 0.0];
		}

		$p = min(1.0, $conversions / $clicks);
		$denominator = 1 + $z * $z / $clicks;
		$center = $p + $z * $z / (2 * $clicks);
		$margin = $z * sqrt($p * (1 - $p) / $clicks + $z * $z / (4 * $clicks * $clicks));

		return [max(0.0, ($center - $margin) / $denominator), min(1.0, ($center + $margin) / $denominator)];
	}
}
