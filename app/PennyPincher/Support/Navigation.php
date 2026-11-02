<?php

namespace App\PennyPincher\Support;

/**
 * Architektura informacji aplikacji: sześć sekcji głównych i ich podwidoki.
 *
 * Podwidok to istniejący moduł (klucz `section` przekazywany przez kontroler do layoutu) pod swoim dotychczasowym
 * adresem – nowa nawigacja tylko grupuje stare trasy, niczego nie przenosi. Mapa tras i uprawnień: docs/NAWIGACJA.md.
 */
final class Navigation
{
	/**
	 * Moduły projektu z własnym adresem /app/projects/{id}/{sekcja}/ – te same klucze obsługują skróty /app/{sekcja}/
	 * i przełącznik projektu.
	 */
	public const SECTIONS = [
		'campaigns', 'search-terms', 'imports', 'settings', 'google-ads', 'audits', 'budget-leaks', 'recommendations',
		'keywords', 'conversions', 'planner', 'action-plan', 'monitoring', 'changes', 'management', 'conversion-management',
	];

	/**
	 * Adresy sekcji głównych, które nie mają własnego ekranu – przekierowują do pierwszego podwidoku
	 * (z zachowaniem parametrów zapytania). Pozostałe sekcje główne mają już adres modułu o tej samej nazwie.
	 */
	public const ALIASES = [
		'command-center' => '',
		'optimization' => 'audits',
		'administration' => 'settings',
	];

	/**
	 * Sekcja główna => etykieta, ikona i podwidoki (klucz = `section`, `path` względem projektu albo – przy
	 * `global` – względem /app/). Pierwszy podwidok jest stroną startową sekcji (link w nawigacji i cel aliasu).
	 */
	public const GROUPS = [
		'command-center' => [
			'label' => 'Centrum dowodzenia',
			'icon' => 'overview',
			'items' => [
				'overview' => ['label' => 'Przegląd', 'path' => ''],
			],
		],
		'campaigns' => [
			'label' => 'Kampanie',
			'icon' => 'campaigns',
			'items' => [
				'campaigns' => ['label' => 'Lista kampanii', 'path' => 'campaigns'],
				'search-terms' => ['label' => 'Wyszukiwane hasła', 'path' => 'search-terms'],
				'planner' => ['label' => 'Planowanie', 'path' => 'planner'],
			],
		],
		'optimization' => [
			'label' => 'Optymalizacja',
			'icon' => 'idea',
			'items' => [
				'audits' => ['label' => 'Audyt', 'path' => 'audits'],
				'budget-leaks' => ['label' => 'Wycieki budżetu', 'path' => 'budget-leaks'],
				'keywords' => ['label' => 'Słowa kluczowe', 'path' => 'keywords'],
				'recommendations' => ['label' => 'Rekomendacje', 'path' => 'recommendations'],
			],
		],
		'action-plan' => [
			'label' => 'Plan działania',
			'icon' => 'list',
			'items' => [
				'action-plan' => ['label' => 'Zadania', 'path' => 'action-plan'],
				'monitoring' => ['label' => 'Monitoring', 'path' => 'monitoring'],
				'changes' => ['label' => 'Zmiany na koncie', 'path' => 'changes'],
			],
		],
		'conversions' => [
			'label' => 'Konwersje',
			'icon' => 'target',
			'items' => [
				'conversions' => ['label' => 'Klasyfikacja', 'path' => 'conversions'],
				'conversion-management' => ['label' => 'Konfiguracja w Google Ads', 'path' => 'conversion-management'],
			],
		],
		'administration' => [
			'label' => 'Zarządzanie',
			'icon' => 'settings',
			'items' => [
				'settings' => ['label' => 'Ustawienia projektu', 'path' => 'settings'],
				'google-ads' => ['label' => 'Integracja Google Ads', 'path' => 'google-ads'],
				'management' => ['label' => 'Zarządzanie kontem', 'path' => 'management'],
				'imports' => ['label' => 'Importy', 'path' => 'imports'],
				'projects' => ['label' => 'Projekty', 'path' => 'projects', 'global' => true],
			],
		],
	];

	/**
	 * Wspólny punkt wejścia Optymalizacji (pp2_optimization) – pierwszy podwidok sekcji, gdy flaga jest włączona.
	 */
	public const OPTIMIZATION_HUB = ['optimization' => ['label' => 'Przegląd analiz', 'path' => 'optimization']];

	/**
	 * Wspólna kolejka Planu działania (pp2_action_plan) – pierwszy podwidok sekcji, gdy flaga jest włączona.
	 */
	public const ACTION_INBOX = ['action-inbox' => ['label' => 'Kolejka', 'path' => 'action-plan/inbox']];

	/**
	 * Centrum konwersji (pp2_conversions) – pierwszy podwidok sekcji Konwersje, gdy flaga jest włączona.
	 */
	public const CONVERSION_CENTER = ['conversion-center' => ['label' => 'Centrum konwersji', 'path' => 'conversions/center']];

	/**
	 * Przegląd Zarządzania (pp2_management) – onboarding integracji i podział ustawień; pierwszy podwidok sekcji.
	 */
	public const ADMINISTRATION_HUB = ['administration' => ['label' => 'Przegląd i konfiguracja', 'path' => 'administration']];

	/**
	 * Asystent (pp2_assistant) – wyjaśnienia istniejących wyników; ostatni podwidok sekcji Optymalizacja.
	 */
	public const ASSISTANT = ['assistant' => ['label' => 'Asystent', 'path' => 'assistant']];

	/**
	 * Sekcje główne z uwzględnieniem flag (bez flag = GROUPS).
	 *
	 * @return array<string, array{label: string, icon: string, items: array<string, array>}>
	 */
	public static function groups(): array
	{
		$groups = self::GROUPS;

		if (Feature::enabled(Feature::OPTIMIZATION)) {
			$groups['optimization']['items'] = self::OPTIMIZATION_HUB + $groups['optimization']['items'];
		}

		if (Feature::enabled(Feature::ACTION_PLAN)) {
			$groups['action-plan']['items'] = self::ACTION_INBOX + $groups['action-plan']['items'];
		}

		if (Feature::enabled(Feature::CONVERSIONS)) {
			$groups['conversions']['items'] = self::CONVERSION_CENTER + $groups['conversions']['items'];
		}

		if (Feature::enabled(Feature::MANAGEMENT)) {
			$groups['administration']['items'] = self::ADMINISTRATION_HUB + $groups['administration']['items'];
		}

		if (Feature::enabled(Feature::ASSISTANT)) {
			$groups['optimization']['items'] += self::ASSISTANT;
		}

		return $groups;
	}

	/**
	 * Sekcja główna, do której należy moduł; null dla ekranów spoza nawigacji (np. członkowie organizacji).
	 */
	public static function groupOf(?string $section): ?string
	{
		foreach (self::groups() as $group => $definition) {
			if ($section !== null && isset($definition['items'][$section])) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Ścieżka modułu względem projektu dla sekcji albo aliasu sekcji głównej.
	 */
	public static function resolve(string $section): string
	{
		if ($section === 'optimization' && Feature::enabled(Feature::OPTIMIZATION)) {
			return 'optimization';
		}

		if ($section === 'administration' && Feature::enabled(Feature::MANAGEMENT)) {
			return 'administration';
		}

		return self::ALIASES[$section] ?? $section;
	}
}
