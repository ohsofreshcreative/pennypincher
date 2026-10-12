<?php

namespace App\PennyPincher\Domain\Recommendations;

use App\PennyPincher\Domain\Analysis\Statistics;
use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Support\Format;

/**
 * Zamienia wyniki analiz (audyt, Budget Leaks, słowa kluczowe, wykluczenia) w rekomendacje:
 * - łączy podobne problemy (np. wszystkie kosztowne hasła kampanii w jedną rekomendację),
 * - deduplikuje ten sam problem z różnych źródeł (np. audyt PF004 i Budget Leaks BL301 dla tej samej kampanii),
 * - priorytet łączy wpływ, pewność, istotność biznesową i koszt – nie tylko wysokość wydatków.
 *
 * Odcisk (fingerprint) nie zależy od okresu, więc status rekomendacji przetrwa ponowną analizę.
 */
final class RecommendationGenerator
{
	public const STATUSES = [
		'new' => 'Nowa',
		'to_review' => 'Do sprawdzenia',
		'accepted' => 'Zaakceptowana',
		'rejected' => 'Odrzucona',
		'done' => 'Wykonana',
	];

	public const SOURCES = [
		'audit' => 'Audyt',
		'budget_leaks' => 'Budget Leaks',
		'search_terms' => 'Wyszukiwane hasła',
		'keywords' => 'Słowa kluczowe',
		'keyword_opportunities' => 'Keyword Opportunities',
		'negatives' => 'Wykluczenia',
		'dataforseo' => 'DataForSEO',
	];

	/** Reguły audytu i Budget Leaks opisujące to samo zjawisko → wspólny klucz tematu. */
	private const SUBJECTS = [
		'PF004' => 'campaign:%d:no_conversions',
		'BL301' => 'campaign:%d:no_conversions',
		'CT001' => 'measurement:micro_primary',
		'BL401' => 'measurement:micro_primary',
		'CT002' => 'measurement:no_goals',
		'BL402' => 'measurement:no_goals',
		'CS001' => 'campaign:%d:networks',
		'CS002' => 'campaign:%d:networks',
		'BL304' => 'campaign:%d:networks',
		'PF003' => 'campaign:%d:cpa_trend',
		'BL303' => 'campaign:%d:cpa_trend',
	];

	/** @var array<string, string> */
	private array $campaignNames;

	/** @var array<int, array<string, mixed>> */
	private array $adGroups;

	public function __construct(array $campaignNames, array $adGroups, private string $currency)
	{
		$this->campaignNames = array_map('strval', $campaignNames);
		$this->adGroups = $adGroups;
	}

	/**
	 * @param array<int, array<string, mixed>> $findings wiersze audit_findings
	 * @param array<int, array<string, mixed>> $leaks wiersze budget_leaks
	 * @param array<int, array<string, mixed>> $opportunities wiersze keyword_opportunities
	 * @param array<int, array<string, mixed>> $negatives wiersze negative_suggestions
	 * @return array<string, array<string, mixed>> klucz = fingerprint
	 */
	public function generate(array $findings, array $leaks, array $opportunities, array $negatives): array
	{
		$candidates = [];

		foreach ($findings as $finding) {
			if ($finding['status'] === 'dismissed' || $finding['severity'] === 'info') {
				continue;
			}

			$this->add($candidates, $this->fromFinding($finding));
		}

		$groups = [];

		foreach ($leaks as $leak) {
			if ($leak['status'] === 'dismissed' || ($leak['confidence'] === Statistics::LOW && $leak['priority'] === 'low')) {
				continue;
			}

			// BL102 (niska intencja) obsługują propozycje wykluczeń – nie dublujemy.
			if ($leak['rule_id'] === 'BL102' && $negatives !== []) {
				continue;
			}

			if (in_array($leak['rule_id'], ['BL101', 'BL104', 'BL105', 'BL102'], true)) {
				$groups['terms:' . (int) $leak['campaign_id']][] = $leak;
			} elseif (in_array($leak['rule_id'], ['BL201', 'BL203'], true)) {
				$groups['keywords:' . (int) $leak['campaign_id']][] = $leak;
			} elseif ($leak['rule_id'] === 'BL204') {
				$groups['duplicates'][] = $leak;
			} else {
				$this->add($candidates, $this->fromLeak($leak));
			}
		}

		foreach ($groups as $key => $members) {
			$this->add($candidates, $this->fromLeakGroup($key, $members));
		}

		$this->addOpportunities($candidates, $opportunities);
		$this->addNegatives($candidates, $negatives);

		return $candidates;
	}

	private function fromFinding(array $finding): array
	{
		$subject = isset(self::SUBJECTS[$finding['rule_id']]) ? sprintf(self::SUBJECTS[$finding['rule_id']], (int) $finding['entity_id']) : 'audit:' . $finding['fingerprint'];
		$score = $finding['severity'] === 'critical' ? 78 : 52;

		return [
			'subject' => $subject,
			'source' => 'audit',
			'type' => 'audit_' . strtolower($finding['rule_id']),
			'title' => $finding['title'],
			'description' => $finding['description'],
			'rationale' => self::pairsText($finding['evidence'] ?? []),
			'evidence' => $finding['evidence'] ?? [],
			'action' => $finding['recommendation'],
			'campaign_id' => $finding['entity_type'] === 'campaign' ? (int) $finding['entity_id'] : null,
			'ad_group_id' => null,
			'priority_score' => $score,
			'confidence' => in_array($finding['category'], ['data_quality', 'performance'], true) ? Statistics::MEDIUM : Statistics::HIGH,
			'impact_micros' => null,
			'impact_label' => $finding['severity'] === 'critical' ? 'Problem krytyczny w audycie' : 'Ostrzeżenie z audytu',
		];
	}

	private function fromLeak(array $leak): array
	{
		$subject = isset(self::SUBJECTS[$leak['rule_id']]) ? sprintf(self::SUBJECTS[$leak['rule_id']], (int) $leak['campaign_id']) : 'leak:' . $leak['fingerprint'];

		return [
			'subject' => $subject,
			'source' => 'budget_leaks',
			'type' => 'leak_' . strtolower($leak['rule_id']),
			'title' => $leak['title'],
			'description' => $leak['reason'],
			'rationale' => self::pairsText($leak['evidence']['pairs'] ?? []),
			'evidence' => $leak['evidence']['pairs'] ?? [],
			'action' => $leak['action'],
			'campaign_id' => $leak['campaign_id'] !== null ? (int) $leak['campaign_id'] : null,
			'ad_group_id' => $leak['ad_group_id'] !== null ? (int) $leak['ad_group_id'] : null,
			'priority_score' => (int) $leak['priority_score'],
			'confidence' => $leak['confidence'],
			'impact_micros' => $leak['savings_micros'],
			'impact_label' => $leak['savings_micros'] !== null ? 'Szacunek: ok. ' . Format::money($leak['savings_micros'], $this->currency, 0) . ' / mies.' : null,
		];
	}

	private function fromLeakGroup(string $key, array $members): array
	{
		usort($members, fn ($a, $b) => $b['priority_score'] <=> $a['priority_score'] ?: (int) $b['cost_micros'] <=> (int) $a['cost_micros']);
		[$kind, $campaignId] = array_pad(explode(':', $key), 2, null);
		$campaign = $campaignId !== null ? ($this->campaignNames[(int) $campaignId] ?? 'kampania') : null;
		$cost = array_sum(array_map(fn ($m) => (int) $m['cost_micros'], $members));
		$savings = array_sum(array_map(fn ($m) => (int) ($m['savings_micros'] ?? 0), $members));
		$confidence = $this->groupConfidence($members);
		$examples = array_map(fn ($m) => '„' . $m['subject'] . '” (' . Format::money((int) $m['cost_micros'], $this->currency) . ')', array_slice($members, 0, 6));

		[$title, $description, $action, $source] = match ($kind) {
			'terms' => [
				sprintf('Przejrzyj %d %s bez wyników w kampanii „%s”', count($members), Format::plural(count($members), 'zapytanie', 'zapytania', 'zapytań'), $campaign),
				sprintf('Zapytania kosztowały łącznie %s bez konwersji (lub przy wysokim CPC). Przykłady: %s.', Format::money($cost, $this->currency), implode(', ', $examples)),
				'Oceń każde zapytanie: nietrafne dodaj jako wykluczenia, trafne – sprawdź pod kątem reklamy i strony docelowej.',
				'search_terms',
			],
			'keywords' => [
				sprintf('Zoptymalizuj %d %s w kampanii „%s”', count($members), Format::plural(count($members), 'słowo kluczowe', 'słowa kluczowe', 'słów kluczowych'), $campaign),
				sprintf('Słowa kosztowały łącznie %s przy braku konwersji lub wysokim koszcie konwersji. Przykłady: %s.', Format::money($cost, $this->currency), implode(', ', $examples)),
				'Obniż stawki, zawęź dopasowanie albo wstrzymaj słowa, które po sprawdzeniu haseł nie rokują.',
				'keywords',
			],
			default => [
				sprintf('Uporządkuj %d %s', count($members), Format::plural(count($members), 'zdublowane słowo kluczowe', 'zdublowane słowa kluczowe', 'zdublowanych słów kluczowych')),
				'Te same słowa kluczowe występują w kilku grupach reklam: ' . implode(', ', array_map(fn ($m) => '„' . $m['subject'] . '”', array_slice($members, 0, 8))) . '.',
				'Zostaw każde słowo w jednej grupie reklam.',
				'keywords',
			],
		};

		return [
			'subject' => 'group:' . $key,
			'source' => $source,
			'type' => 'group_' . $kind,
			'title' => $title,
			'description' => $description,
			'rationale' => implode("\n", array_map(fn ($m) => '• ' . $m['title'] . ' – ' . $m['reason'], array_slice($members, 0, 10))),
			'evidence' => array_map(fn ($m) => [(string) $m['subject'], Format::money((int) $m['cost_micros'], $this->currency) . ' · ' . Format::number($m['clicks']) . ' kliknięć · pewność: ' . Statistics::CONFIDENCE_LABELS[$m['confidence']]], array_slice($members, 0, 15)),
			'action' => $action,
			'campaign_id' => $campaignId !== null ? (int) $campaignId : null,
			'ad_group_id' => null,
			'priority_score' => min(100, (int) $members[0]['priority_score'] + min(10, count($members) - 1)),
			'confidence' => $confidence,
			'impact_micros' => $savings > 0 ? $savings : null,
			'impact_label' => $savings > 0 ? 'Szacunek: do ok. ' . Format::money($savings, $this->currency, 0) . ' / mies.' : null,
		];
	}

	private function addOpportunities(array &$candidates, array $opportunities): void
	{
		$groups = [];

		foreach ($opportunities as $opportunity) {
			if ((int) $opportunity['score'] >= 50) {
				$groups[(int) ($opportunity['suggested_ad_group_id'] ?? 0)][] = $opportunity;
			}
		}

		foreach ($groups as $adGroupId => $members) {
			usort($members, fn ($a, $b) => $b['score'] <=> $a['score']);
			$group = $adGroupId ? ($this->adGroups[$adGroupId] ?? null) : null;
			$where = $group ? sprintf('grupy „%s” (%s)', $group['name'], $this->campaignNames[$group['campaign_id']] ?? '—') : 'kampanii (do przypisania)';
			$dataforseo = (bool) array_filter($members, fn ($m) => str_contains((string) $m['sources'], 'dataforseo'));
			$top = array_slice($members, 0, 8);

			$this->add($candidates, [
				'subject' => 'opportunities:' . $adGroupId,
				'source' => $dataforseo ? 'dataforseo' : 'keyword_opportunities',
				'type' => 'keyword_opportunities',
				'title' => sprintf('Rozważ %d %s do %s', count($members), Format::plural(count($members), 'nową frazę', 'nowe frazy', 'nowych fraz'), $where),
				'description' => 'Frazy o wysokim Opportunity Score: ' . implode(', ', array_map(fn ($m) => '„' . $m['phrase'] . '” (' . $m['score'] . ')', $top)) . '.',
				'rationale' => implode("\n", array_map(fn ($m) => '• „' . $m['phrase'] . '”: ' . $m['reason'], $top)),
				'evidence' => array_map(fn ($m) => [(string) $m['phrase'], 'Score ' . $m['score'] . ' · intencja: ' . (Text::INTENT_LABELS[$m['intent']] ?? $m['intent']) . ' · wolumen: ' . ($m['search_volume'] === null ? 'brak danych' : Format::number($m['search_volume']))], $top),
				'action' => 'Dodaj wybrane frazy ręcznie w Google Ads (najlepiej w dopasowaniu do wyrażenia lub ścisłym) i obserwuj wyszukiwane hasła przez 2–4 tygodnie.',
				'campaign_id' => $group['campaign_id'] ?? null,
				'ad_group_id' => $adGroupId ?: null,
				'priority_score' => (int) min(70, round(array_sum(array_column($top, 'score')) / count($top) * 0.7)),
				'confidence' => $this->groupConfidence($members),
				'impact_micros' => null,
				'impact_label' => 'Potencjalnie więcej trafnego ruchu (bez prognozy)',
			]);
		}
	}

	private function addNegatives(array &$candidates, array $negatives): void
	{
		$groups = [];

		foreach ($negatives as $negative) {
			if (! (int) $negative['already_excluded']) {
				$groups[$negative['reason_code']][] = $negative;
			}
		}

		$labels = [
			'excluded' => 'niepożądani odbiorcy',
			'jobs' => 'zapytania o pracę',
			'free' => 'darmowe rozwiązania',
			'education' => 'zapytania edukacyjne',
			'diy' => 'zrób to sam',
			'informational' => 'zapytania informacyjne',
			'no_conversions' => 'kosztowne zapytania bez konwersji',
		];

		foreach ($groups as $reason => $members) {
			usort($members, fn ($a, $b) => (int) $b['cost_micros'] <=> (int) $a['cost_micros']);
			$cost = array_sum(array_map(fn ($m) => (int) $m['cost_micros'], $members));
			$confidence = $this->groupConfidence($members);
			$top = array_slice($members, 0, 10);

			$this->add($candidates, [
				'subject' => 'negatives:' . $reason,
				'source' => 'negatives',
				'type' => 'negatives',
				'title' => sprintf('Dodaj wykluczenia: %s (%d %s)', $labels[$reason] ?? $reason, count($members), Format::plural(count($members), 'fraza', 'frazy', 'fraz')),
				'description' => $members[0]['reason'] . ' Propozycje: ' . implode(', ', array_map(fn ($m) => '„' . $m['phrase'] . '” [' . $m['match_type'] . ']', $top)) . '.',
				'rationale' => sprintf('Powiązane zapytania kosztowały łącznie %s bez konwersji.', Format::money($cost, $this->currency)),
				'evidence' => array_map(fn ($m) => [(string) $m['phrase'], Format::money((int) $m['cost_micros'], $this->currency) . ' · ' . Format::number($m['clicks']) . ' kliknięć · ' . ($m['level'] === 'account' ? 'lista wykluczeń konta' : 'kampania')], $top),
				'action' => 'Sprawdź listę w Keyword Opportunities → Wykluczenia i dodaj wybrane frazy ręcznie w Google Ads. Penny Pincher nie dodaje wykluczeń automatycznie.',
				'campaign_id' => null,
				'ad_group_id' => null,
				'priority_score' => (int) min(85, ($confidence === Statistics::HIGH ? 45 : ($confidence === Statistics::MEDIUM ? 32 : 15)) + min(25, count($members) * 3) + ($reason === 'excluded' ? 10 : 0)),
				'confidence' => $confidence,
				'impact_micros' => null,
				'impact_label' => 'Historyczny koszt: ' . Format::money($cost, $this->currency, 0),
			]);
		}
	}

	/**
	 * Dodaje kandydata albo łączy go z istniejącym o tym samym temacie (wyższy priorytet wygrywa, źródła się sumują).
	 */
	private function add(array &$candidates, array $candidate): void
	{
		$fingerprint = sha1($candidate['subject']);
		$candidate['sources'] = [$candidate['source']];

		if (isset($candidates[$fingerprint])) {
			$existing = $candidates[$fingerprint];
			$winner = $candidate['priority_score'] > $existing['priority_score'] ? $candidate : $existing;
			$winner['sources'] = array_values(array_unique([...$existing['sources'], $candidate['source']]));
			$winner['priority_score'] = min(100, max($candidate['priority_score'], $existing['priority_score']) + 5);
			$winner['confidence'] = Statistics::rank($candidate['confidence']) > Statistics::rank($existing['confidence']) ? $candidate['confidence'] : $existing['confidence'];
			$winner['impact_micros'] ??= $candidate['impact_micros'] ?? $existing['impact_micros'];
			$winner['impact_label'] ??= $candidate['impact_label'] ?? $existing['impact_label'];
			$candidates[$fingerprint] = $winner;

			return;
		}

		$candidates[$fingerprint] = $candidate;
	}

	private function groupConfidence(array $members): string
	{
		$ranks = array_map(fn ($m) => Statistics::rank($m['confidence']), $members);
		$average = array_sum($ranks) / max(1, count($ranks));

		return $average >= 2.5 ? Statistics::HIGH : ($average >= 1.6 ? Statistics::MEDIUM : Statistics::LOW);
	}

	public static function priority(int $score): string
	{
		return $score >= 60 ? 'high' : ($score >= 35 ? 'medium' : 'low');
	}

	private static function pairsText(array $pairs): string
	{
		return implode("\n", array_map(fn ($pair) => $pair[0] . ': ' . $pair[1], array_filter($pairs, 'is_array')));
	}
}
