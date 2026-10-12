<?php

namespace App\PennyPincher\Domain\ActionPlan;

use App\PennyPincher\Domain\Analysis\Statistics;

/**
 * Action Plan: zamienia aktywne rekomendacje w uporządkowane działania.
 *
 * - Każda rekomendacja trafia do dokładnie jednego działania (bez dublowania), podobne są łączone
 *   (np. wszystkie problemy pomiaru → „Popraw pomiar konwersji”, problemy jednej kampanii → jedno działanie).
 * - Zależności: najpierw dane i pomiar, potem decyzje o rentowności, budżecie, nowych słowach i dopasowaniach.
 * - Priorytet łączy wpływ, pewność, koszt problemu, to, czy działanie odblokowuje inne, nakład pracy i ryzyko.
 *   Wysoki priorytet dostaje najwyżej ok. 30% działań – nie wszystko jest „krytyczne”.
 * Generator nie zmienia statusów – robi to wyłącznie użytkownik.
 */
final class ActionPlanBuilder
{
	public const CATEGORIES = [
		'data' => 'Dane i synchronizacja',
		'data_quality' => 'Kompletność danych',
		'measurement' => 'Pomiar konwersji',
		'campaign_review' => 'Weryfikacja kampanii',
		'landing_page' => 'Strona docelowa',
		'keyword_cleanup' => 'Słowa kluczowe i wyszukiwane hasła',
		'negatives' => 'Wykluczenia',
		'match_types' => 'Typy dopasowania',
		'settings' => 'Ustawienia kampanii',
		'budget' => 'Budżet',
		'new_keywords' => 'Nowe słowa kluczowe',
	];

	public const EFFORT = [
		'data' => 'low', 'data_quality' => 'low', 'measurement' => 'medium', 'campaign_review' => 'medium', 'landing_page' => 'high', 'keyword_cleanup' => 'low',
		'negatives' => 'low', 'match_types' => 'medium', 'settings' => 'low', 'budget' => 'low', 'new_keywords' => 'medium',
	];

	public const EFFORT_LABELS = ['low' => 'Mały (do 1 h)', 'medium' => 'Średni (kilka godzin)', 'high' => 'Duży (wymaga zmian na stronie / więcej osób)'];

	/** Ryzyko wykonania (0–1): zmiany budżetu, dopasowań i nowe słowa mogą zwiększyć wydatki. */
	private const RISK = ['budget' => 0.4, 'new_keywords' => 0.3, 'match_types' => 0.3, 'campaign_review' => 0.2, 'landing_page' => 0.2];

	/** Kategorie zależne od wiarygodnego pomiaru konwersji. */
	private const NEEDS_MEASUREMENT = ['campaign_review', 'landing_page', 'budget', 'new_keywords', 'match_types'];

	private const EFFECTS = [
		'data' => 'Kompletne i aktualne dane – bez tego pozostałe wnioski mogą być błędne.',
		'data_quality' => 'Pełniejsze dane do analiz (więcej dni, jedno źródło danych).',
		'measurement' => 'Wiarygodne konwersje: podstawa decyzji o budżecie, stawkach i rentowności kampanii.',
		'campaign_review' => 'Ograniczenie wydatków kampanii, która nie przynosi wartościowych kontaktów.',
		'landing_page' => 'Wyższy współczynnik konwersji z tego samego ruchu.',
		'keyword_cleanup' => 'Mniej wydatków na słowa i zapytania bez wyników.',
		'negatives' => 'Mniej kliknięć z nietrafnych zapytań.',
		'match_types' => 'Lepsza kontrola, na jakie zapytania wyświetlają się reklamy.',
		'settings' => 'Reklamy wyświetlane tylko tam, gdzie mają sens (sieci, lokalizacje, strategia).',
		'budget' => 'Wydatki zgodne z planem miesięcznym.',
		'new_keywords' => 'Dotarcie do nowych, trafnych zapytań – jako kontrolowany test.',
	];

	/**
	 * @param array<int, array<string, mixed>> $recommendations aktywne rekomendacje (bez odrzuconych, wykonanych i nieaktualnych)
	 * @param array<int, string> $campaignNames
	 * @param ?int $monthlySpendMicros miesięczne wydatki konta (do oceny kosztu problemu)
	 * @return array<string, array<string, mixed>> klucz = fingerprint
	 */
	public static function build(array $recommendations, array $campaignNames, ?int $monthlySpendMicros = null): array
	{
		$groups = [];

		foreach ($recommendations as $rec) {
			[$category, $scope] = self::categorize($rec);
			$groups[$category . '|' . $scope][] = $rec;
		}

		$actions = [];

		foreach ($groups as $key => $members) {
			[$category, $scope] = explode('|', $key, 2);
			$actions[sha1('action|' . $key)] = self::action($category, $scope, $members, $campaignNames, $monthlySpendMicros);
		}

		// Strona docelowa: osobne działanie dla kampanii z dużymi wydatkami bez wartościowych konwersji.
		foreach ($actions as $action) {
			if ($action['category'] === 'campaign_review' && $action['campaign_id'] !== null && in_array('no_conversions', $action['_tags'], true)) {
				$name = $campaignNames[$action['campaign_id']] ?? 'kampanii';
				$actions[sha1('action|landing_page|campaign:' . $action['campaign_id'])] = [
					'category' => 'landing_page',
					'title' => sprintf('Sprawdź stronę docelową kampanii „%s”', $name),
					'description' => 'Kampania ma ruch, ale nie przynosi wartościowych konwersji. Sprawdź, czy strona docelowa odpowiada na zapytania z reklam: oferta, cena lub sposób wyceny, wyraźny formularz i dane kontaktowe, szybkość na telefonie.',
					'rationale' => $action['rationale'],
					'details' => ['items' => [], 'note' => 'Działanie wynika z rekomendacji: ' . $action['title']],
					'sources' => $action['sources'],
					'recommendation_ids' => [],
					'campaign_id' => $action['campaign_id'],
					'impact' => $action['impact'] * 0.8,
					'confidence' => Statistics::MEDIUM,
					'impact_micros' => null,
					'_tags' => [],
				];
			}
		}

		// Zależności.
		$byCategory = [];

		foreach ($actions as $fp => $action) {
			$byCategory[$action['category']][] = $fp;
		}

		foreach ($actions as $fp => &$action) {
			$depends = [];

			if (! in_array($action['category'], ['data', 'data_quality'], true)) {
				array_push($depends, ...($byCategory['data'] ?? []));
			}

			if (in_array($action['category'], self::NEEDS_MEASUREMENT, true)) {
				array_push($depends, ...($byCategory['measurement'] ?? []));
			}

			if ($action['category'] === 'landing_page' && $action['campaign_id'] !== null) {
				$review = sha1('action|campaign_review|campaign:' . $action['campaign_id']);

				if (isset($actions[$review])) {
					$depends[] = $review;
				}
			}

			$action['depends_on'] = array_values(array_unique(array_diff($depends, [$fp])));
		}

		unset($action);

		// Priorytet.
		$blocks = [];

		foreach ($actions as $fp => $action) {
			foreach ($action['depends_on'] as $dep) {
				$blocks[$dep] = ($blocks[$dep] ?? 0) + 1;
			}
		}

		foreach ($actions as $fp => &$action) {
			$effort = self::EFFORT[$action['category']];
			$cost = $monthlySpendMicros && $action['impact_micros'] ? min(1.0, $action['impact_micros'] / $monthlySpendMicros * 4) : 0.0;
			$score = 100 * (
				0.35 * $action['impact']
				+ 0.20 * (['high' => 1.0, 'medium' => 0.6, 'low' => 0.3][$action['confidence']] ?? 0.3)
				+ 0.15 * $cost
				+ 0.15 * min(1.0, ($blocks[$fp] ?? 0) / 3)
				+ 0.10 * ['low' => 1.0, 'medium' => 0.6, 'high' => 0.2][$effort]
				+ 0.05 * (1 - (self::RISK[$action['category']] ?? 0.1))
			);
			$action['priority_score'] = (int) max(1, min(100, round($score)));
			$action['effort'] = $effort;
			$action['expected_effect'] = self::EFFECTS[$action['category']] . ($action['impact_label'] ?? null ? ' ' . $action['impact_label'] . '.' : '');
			unset($action['_tags']);
		}

		unset($action);

		uasort($actions, fn ($a, $b) => $b['priority_score'] <=> $a['priority_score']);
		$highLimit = max(1, (int) ceil(count($actions) * 0.3));
		$high = 0;

		foreach ($actions as &$action) {
			$label = $action['priority_score'] >= 60 ? 'high' : ($action['priority_score'] >= 40 ? 'medium' : 'low');

			if ($label === 'high' && ++$high > $highLimit) {
				$label = 'medium';
			}

			$action['priority'] = $label;
		}

		unset($action);

		return self::ordered($actions);
	}

	/**
	 * Kolejność wykonania: działania bez niespełnionych zależności najpierw, w ich obrębie wg priorytetu.
	 */
	private static function ordered(array $actions): array
	{
		$depth = [];
		$resolve = function (string $fp, array $seen = []) use (&$resolve, &$depth, $actions): int {
			if (isset($depth[$fp])) {
				return $depth[$fp];
			}

			if (in_array($fp, $seen, true)) {
				return 0;
			}

			$d = 0;

			foreach ($actions[$fp]['depends_on'] ?? [] as $dep) {
				if (isset($actions[$dep])) {
					$d = max($d, 1 + $resolve($dep, [...$seen, $fp]));
				}
			}

			return $depth[$fp] = $d;
		};

		foreach (array_keys($actions) as $fp) {
			$resolve($fp);
		}

		$keys = array_keys($actions);
		usort($keys, fn ($a, $b) => ($depth[$a] <=> $depth[$b]) ?: ($actions[$b]['priority_score'] <=> $actions[$a]['priority_score']));
		$result = [];

		foreach ($keys as $position => $fp) {
			$result[$fp] = $actions[$fp] + ['position' => $position];
		}

		return $result;
	}

	/**
	 * @return array{0: string, 1: string} kategoria i zakres grupowania
	 */
	public static function categorize(array $rec): array
	{
		$type = (string) $rec['type'];
		$campaign = $rec['campaign_id'] !== null ? 'campaign:' . (int) $rec['campaign_id'] : 'account';
		$rule = str_starts_with($type, 'audit_') || str_starts_with($type, 'leak_') ? strtoupper(substr($type, strpos($type, '_') + 1)) : '';

		return match (true) {
			// Blokujące: brak danych, nieaktualna lub zepsuta synchronizacja, inna waluta. Pozostałe – informacyjne.
			in_array($rule, ['DQ001', 'DQ003', 'DQ004', 'DQ007'], true) => ['data', 'account'],
			in_array($rule, ['DQ002', 'DQ005', 'DQ006', 'DQ008'], true) => ['data_quality', 'account'],
			in_array($rule, ['CT001', 'CT002', 'CT003', 'BL401', 'BL402', 'BL403', 'BL404', 'BL405'], true) => ['measurement', 'account'],
			in_array($rule, ['PF004', 'PF001', 'PF002', 'PF003', 'BL301', 'BL302', 'BL303'], true) => ['campaign_review', $campaign],
			in_array($rule, ['BP001', 'BP002'], true) => ['budget', 'account'],
			in_array($rule, ['CS001', 'CS002', 'CS003', 'CS004', 'CS005', 'BL304'], true) => ['settings', $campaign],
			$rule === 'BL202' => ['match_types', 'account'],
			$type === 'negatives' => ['negatives', 'rec:' . $rec['fingerprint']],
			$type === 'keyword_opportunities' => ['new_keywords', 'account'],
			in_array($type, ['group_terms', 'group_keywords', 'group_duplicates'], true) || in_array($rule, ['PF005', 'BL101', 'BL102', 'BL103', 'BL104', 'BL105', 'BL201', 'BL203', 'BL204'], true) => ['keyword_cleanup', $campaign],
			default => ['campaign_review', $campaign],
		};
	}

	private static function action(string $category, string $scope, array $members, array $campaignNames, ?int $monthlySpend): array
	{
		usort($members, fn ($a, $b) => (int) $b['priority_score'] <=> (int) $a['priority_score']);
		$lead = $members[0];
		$campaignId = str_starts_with($scope, 'campaign:') ? (int) substr($scope, 9) : null;
		$campaign = $campaignId !== null ? ($campaignNames[$campaignId] ?? 'kampania') : null;
		$count = count($members);
		$impactMicros = array_sum(array_map(fn ($m) => (int) ($m['impact_micros'] ?? 0), $members)) ?: null;
		$ranks = array_map(fn ($m) => Statistics::rank((string) $m['confidence']), $members);
		$rank = max($ranks);
		$tags = [];

		foreach ($members as $m) {
			if (in_array($m['type'], ['audit_pf004', 'leak_bl301'], true)) {
				$tags[] = 'no_conversions';
			}
		}

		$title = match ($category) {
			'data' => 'Uporządkuj dane i synchronizację',
			'data_quality' => 'Uzupełnij dane do analiz',
			'measurement' => 'Popraw pomiar konwersji',
			'campaign_review' => $campaign ? sprintf('Zweryfikuj kampanię „%s”', $campaign) : 'Zweryfikuj wyniki kampanii',
			'keyword_cleanup' => $campaign ? sprintf('Sprawdź kosztowne słowa i hasła w „%s”', $campaign) : 'Sprawdź kosztowne słowa i hasła',
			'negatives' => 'Zweryfikuj wykluczenia: ' . self::negativeTopic($lead),
			'match_types' => 'Przeanalizuj szerokie dopasowania',
			'settings' => $campaign ? sprintf('Sprawdź ustawienia kampanii „%s”', $campaign) : 'Sprawdź ustawienia kampanii',
			'budget' => 'Dopasuj tempo wydatków do budżetu',
			'new_keywords' => 'Przetestuj nowe klastry słów kluczowych',
			default => (string) $lead['title'],
		};

		$description = $count === 1
			? (string) $lead['description']
			: sprintf('Działanie łączy %d powiązane rekomendacje. Najważniejsza: %s', $count, $lead['title']);

		$items = array_map(fn ($m) => [
			'id' => (int) $m['id'],
			'title' => (string) $m['title'],
			'status' => (string) $m['status'],
			'evidence' => array_slice((array) $m['evidence'], 0, 8),
		], $members);

		return [
			'category' => $category,
			'title' => mb_substr($title, 0, 255),
			'description' => $description,
			'rationale' => implode("\n", array_map(fn ($m) => '• ' . $m['title'] . ($m['impact_label'] ? ' (' . $m['impact_label'] . ')' : ''), array_slice($members, 0, 8))),
			'details' => ['items' => $items, 'action' => (string) $lead['action']],
			'sources' => array_values(array_unique(array_merge(...array_map(fn ($m) => (array) ($m['sources'] ?? [$m['source']]), $members)))),
			'recommendation_ids' => array_map(fn ($m) => (int) $m['id'], $members),
			'campaign_id' => $campaignId,
			'impact' => max(array_map(fn ($m) => (int) $m['priority_score'], $members)) / 100,
			'confidence' => [3 => Statistics::HIGH, 2 => Statistics::MEDIUM][$rank] ?? Statistics::LOW,
			'impact_micros' => $impactMicros,
			'impact_label' => $category === 'negatives' || $category === 'keyword_cleanup' ? ($lead['impact_label'] ?? null) : null,
			'_tags' => $tags,
		];
	}

	private static function negativeTopic(array $rec): string
	{
		if (preg_match('/:\s*([^(]+)\(/u', (string) $rec['title'], $m)) {
			return trim($m[1]);
		}

		return 'nietrafne zapytania';
	}
}
