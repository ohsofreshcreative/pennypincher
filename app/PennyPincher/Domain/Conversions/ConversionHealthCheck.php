<?php

namespace App\PennyPincher\Domain\Conversions;

use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\GoogleAds\Enums;

/**
 * Conversion Health Check – ocena konfiguracji konwersji Google Ads na tle klasyfikacji biznesowej Penny Pincher.
 *
 * Wykrywa: mikrokonwersje jako konwersje główne, możliwe duplikaty, nieaktywne lub przestarzałe działania,
 * cele niedopasowane do oferty, niejasne zliczanie leadów, kampanie optymalizowane pod niewłaściwe cele
 * i brak wiarygodnego pomiaru leadów. Dla problemów, które da się poprawić przez API, zwraca propozycję
 * operacji (do edycji i zatwierdzenia w Change Center) – nigdy nie zmienia niczego sama.
 */
final class ConversionHealthCheck
{
	/** Nazwy sugerujące zachowanie na stronie (np. „30 sekund – Bezpłatna konsultacja” to licznik czasu, nie zgłoszenie). */
	public const MICRO_NAME = '/(\d+\s*(s|sek|sekund\w*|second\w*)\b|scroll|przewij|czas na stronie|time on (site|page)|engag|zaangaż|page[_ ]?view|odsłon|wyświetleni\w* (strony|podstrony)|sesj|session|klik w realizacj|realizacj|video|wideo)/iu';

	public const LEAD_CATEGORIES = ['SUBMIT_LEAD_FORM', 'REQUEST_QUOTE', 'BOOK_APPOINTMENT', 'CONTACT', 'PHONE_CALL_LEAD', 'IMPORTED_LEAD', 'QUALIFIED_LEAD', 'CONVERTED_LEAD'];

	public const ECOMMERCE_CATEGORIES = ['PURCHASE', 'ADD_TO_CART', 'BEGIN_CHECKOUT', 'STORE_SALE'];

	/**
	 * @param array<int, array<string, mixed>> $actions działania (lokalne + stan z API, liczba konwersji 90 dni, kampanie)
	 * @param array{customer: ?array, campaigns: ?array} $goals cele z Google Ads (null – nieodczytane)
	 * @param array{reliable: bool, reason: string} $measurement
	 * @return array<int, array<string, mixed>>
	 */
	public static function check(array $actions, array $goals, array $measurement, bool $leadBusiness = true): array
	{
		$issues = [];
		$enabled = array_values(array_filter($actions, fn ($a) => ($a['status'] ?? 'ENABLED') === 'ENABLED'));
		$primary = array_values(array_filter($enabled, fn ($a) => (bool) ($a['primary_for_goal'] ?? false)));

		foreach ($primary as $action) {
			$micro = self::isMicro($action);

			if ($micro === null) {
				continue;
			}

			$issues[] = [
				'key' => 'micro_primary:' . $action['id'],
				'kind' => 'micro_primary',
				'severity' => $micro === 'certain' ? 'critical' : 'warning',
				'title' => sprintf('„%s” jest konwersją główną, a %s mikrokonwersją', $action['name'], $micro === 'certain' ? 'jest' : 'prawdopodobnie jest'),
				'why' => 'Konwersje główne trafiają do kolumny „Konwersje” i – przy automatycznych strategiach stawek (np. Maksymalizacja konwersji) – uczą algorytm, kogo szukać. Jeśli główną konwersją jest np. 30 sekund na stronie, Google optymalizuje pod osoby, które chwilę czytają, a nie pod osoby, które wysyłają zapytanie. Raporty pokazują wtedy zawyżoną liczbę „konwersji”.',
				'proposal' => 'Ustaw to działanie jako dodatkowe (secondary): nadal będzie mierzone w kolumnie „Wszystkie konwersje”, ale przestanie sterować stawkami. Jako główne zostaw zgłoszenia (formularz, telefon, e-mail).',
				'action_id' => (int) $action['id'],
				'operations' => self::editable($action, 'primary_for_goal') ? [['type' => 'conversion_action.update', 'action_id' => (int) $action['id'], 'payload' => ['primary_for_goal' => false]]] : [],
				'not_editable' => self::editable($action, 'primary_for_goal') ? null : self::readOnlyReason($action),
				'campaigns' => $action['campaigns'] ?? [],
				'bidding' => true,
			];
		}

		// Niejasne zliczanie: leady liczone „każda” (jedna osoba wysyłająca formularz 3 razy = 3 leady).
		foreach ($enabled as $action) {
			if (in_array($action['category'] ?? '', self::LEAD_CATEGORIES, true) && ($action['counting_type'] ?? '') === 'MANY_PER_CLICK') {
				$issues[] = [
					'key' => 'counting:' . $action['id'],
					'kind' => 'counting',
					'severity' => 'warning',
					'title' => sprintf('„%s” liczy każdą konwersję po kliknięciu', $action['name']),
					'why' => 'Dla leadów zwykle liczy się jedną konwersję na kliknięcie – ponowne wysłanie formularza przez tę samą osobę nie jest nowym klientem. Zliczanie „każda” zawyża liczbę leadów i koszt pozyskania wygląda lepiej, niż jest.',
					'proposal' => 'Zmień sposób zliczania na „Jedna”.',
					'action_id' => (int) $action['id'],
					'operations' => self::editable($action, 'counting_type') ? [['type' => 'conversion_action.update', 'action_id' => (int) $action['id'], 'payload' => ['counting_type' => 'ONE_PER_CLICK']]] : [],
					'not_editable' => self::editable($action, 'counting_type') ? null : self::readOnlyReason($action),
					'campaigns' => $action['campaigns'] ?? [],
					'bidding' => (bool) ($action['primary_for_goal'] ?? false),
				];
			}
		}

		// Możliwe duplikaty: dwa działania główne tej samej kategorii o podobnej nazwie.
		for ($i = 0; $i < count($primary); $i++) {
			for ($j = $i + 1; $j < count($primary); $j++) {
				$a = $primary[$i];
				$b = $primary[$j];

				if (($a['category'] ?? null) !== null && $a['category'] === ($b['category'] ?? null) && self::similarNames((string) $a['name'], (string) $b['name'])) {
					$issues[] = [
						'key' => 'duplicate:' . min($a['id'], $b['id']) . ':' . max($a['id'], $b['id']),
						'kind' => 'duplicate',
						'severity' => 'warning',
						'title' => sprintf('Możliwy duplikat: „%s” i „%s”', $a['name'], $b['name']),
						'why' => 'Dwa działania główne mierzące to samo zdarzenie (np. tag Google Ads i import z GA4) liczą każdy lead podwójnie, a automatyczne strategie dostają zawyżone dane.',
						'proposal' => 'Sprawdź w Google Ads → Cele → Konwersje, czy oba działania mierzą to samo. Jeśli tak – jedno ustaw jako dodatkowe.',
						'action_id' => (int) $b['id'],
						'operations' => [],
						'not_editable' => 'Penny Pincher nie rozstrzyga duplikatów automatycznie – wybierz działanie, które ma zostać główne, i przygotuj zmianę przy nim.',
						'campaigns' => [],
						'bidding' => true,
					];
				}
			}
		}

		// Nieaktywne lub przestarzałe: główne działanie bez konwersji w 90 dniach, działania UA (wyłączone przez Google).
		foreach ($primary as $action) {
			$stale = str_starts_with((string) ($action['type'] ?? ''), 'UNIVERSAL_ANALYTICS');

			if ($stale || (($action['conversions_90'] ?? null) !== null && (float) $action['conversions_90'] === 0.0 && (float) ($action['all_conversions_90'] ?? 0) === 0.0)) {
				$issues[] = [
					'key' => 'stale:' . $action['id'],
					'kind' => 'stale',
					'severity' => 'info',
					'title' => sprintf('„%s” nie zarejestrowało konwersji w 90 dniach%s', $action['name'], $stale ? ' (Universal Analytics – wycofane)' : ''),
					'why' => 'Nieaktywne działanie główne może oznaczać zepsuty tag, zmianę strony albo działanie, którego już nie używasz. Jeśli to jedyne działanie główne – kampanie nie mają danych do optymalizacji.',
					'proposal' => 'Sprawdź tag na stronie (Google Tag Assistant). Jeśli działanie nie jest już używane – ustaw je jako dodatkowe.',
					'action_id' => (int) $action['id'],
					'operations' => self::editable($action, 'primary_for_goal') ? [['type' => 'conversion_action.update', 'action_id' => (int) $action['id'], 'payload' => ['primary_for_goal' => false]]] : [],
					'not_editable' => self::editable($action, 'primary_for_goal') ? null : self::readOnlyReason($action),
					'campaigns' => $action['campaigns'] ?? [],
					'bidding' => true,
				];
			}
		}

		// Cele niedopasowane do oferty: e-commerce w biznesie opartym o zapytania.
		if ($leadBusiness) {
			foreach ($primary as $action) {
				if (in_array($action['category'] ?? '', ['ADD_TO_CART', 'BEGIN_CHECKOUT'], true)) {
					$issues[] = [
						'key' => 'offer:' . $action['id'],
						'kind' => 'offer',
						'severity' => 'warning',
						'title' => sprintf('„%s” to cel sklepu internetowego, a oferta opiera się na zapytaniach', $action['name']),
						'why' => 'Kontekst biznesowy projektu wskazuje na pozyskiwanie zapytań (leadów). Cele koszykowe nie mówią, czy ktoś się skontaktował.',
						'proposal' => 'Ustaw działanie jako dodatkowe albo popraw kontekst biznesowy, jeśli projekt sprzedaje online.',
						'action_id' => (int) $action['id'],
						'operations' => self::editable($action, 'primary_for_goal') ? [['type' => 'conversion_action.update', 'action_id' => (int) $action['id'], 'payload' => ['primary_for_goal' => false]]] : [],
						'not_editable' => self::editable($action, 'primary_for_goal') ? null : self::readOnlyReason($action),
						'campaigns' => $action['campaigns'] ?? [],
						'bidding' => true,
					];
				}
			}
		}

		// Kampanie optymalizowane pod niewłaściwe cele (wymaga odczytu celów kampanii z Google Ads).
		foreach ((array) ($goals['campaigns'] ?? []) as $goal) {
			if (! $goal['biddable']) {
				continue;
			}

			$inCategory = array_values(array_filter($primary, fn ($a) => ($a['category'] ?? null) === $goal['category'] && (($a['origin'] ?? null) === null || $a['origin'] === $goal['origin'])));

			if ($inCategory !== [] && array_filter($inCategory, fn ($a) => self::isMicro($a) === null) === []) {
				$issues[] = [
					'key' => 'campaign_goal:' . $goal['resource_name'],
					'kind' => 'campaign_goal',
					'severity' => 'warning',
					'title' => sprintf('Kampania „%s” optymalizuje pod cel „%s”, w którym są tylko mikrokonwersje', $goal['campaign_name'] ?? '—', Enums::categoryLabel($goal['category']) ?? $goal['category']),
					'why' => 'Cel konwersji kampanii decyduje, które konwersje uczą strategię stawek. Cel złożony z samych mikrokonwersji kieruje budżet do osób, które tylko przeglądają stronę.',
					'proposal' => 'Wyłącz ten cel w optymalizacji kampanii (cel pozostanie mierzony) – kampania będzie optymalizowana pod pozostałe cele konta.',
					'action_id' => null,
					'operations' => [['type' => 'campaign_conversion_goal.update', 'resource_name' => $goal['resource_name'], 'payload' => ['biddable' => false]]],
					'not_editable' => null,
					'campaigns' => [['name' => $goal['campaign_name'] ?? '—']],
					'bidding' => true,
				];
			}
		}

		// Brak wiarygodnego pomiaru leadów.
		$valuable = array_filter($primary, fn ($a) => self::isMicro($a) === null && in_array($a['category'] ?? '', [...self::LEAD_CATEGORIES, 'PURCHASE'], true));

		if (! $measurement['reliable'] || ($primary !== [] && $valuable === [])) {
			$issues[] = [
				'key' => 'no_reliable_leads',
				'kind' => 'no_reliable_leads',
				'severity' => 'critical',
				'title' => 'Brak wiarygodnego pomiaru prawdziwych leadów',
				'why' => ($measurement['reliable'] ? 'Żadna konwersja główna nie mierzy zgłoszenia (formularz, telefon, e-mail).' : $measurement['reason']) . ' Bez tego nie da się ocenić kosztu pozyskania klienta, a automatyczne strategie stawek optymalizują pod niewłaściwe zdarzenia.',
				'proposal' => 'Utwórz działanie konwersji „Wysłanie formularza” (kategoria: przesłanie formularza), wdroż tag na stronie podziękowania lub zdarzenie w Google Tag Manager, a po potwierdzeniu, że rejestruje prawdziwe zgłoszenia – ustaw je jako główne. Rozważ import potwierdzonych leadów z CRM.',
				'action_id' => null,
				'operations' => [],
				'not_editable' => null,
				'campaigns' => [],
				'bidding' => false,
				'create_suggestion' => true,
			];
		}

		$order = ['critical' => 0, 'warning' => 1, 'info' => 2];
		usort($issues, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

		return $issues;
	}

	/**
	 * 'certain' – mikrokonwersja wg klasyfikacji lub kategorii; 'likely' – nazwa wskazuje na zachowanie na stronie; null – nie.
	 */
	public static function isMicro(array $action): ?string
	{
		if (($action['effective_class'] ?? null) === ConversionClass::Micro->value || in_array($action['category'] ?? '', Enums::MICRO_CATEGORIES, true)) {
			return 'certain';
		}

		$manual = ($action['classification_source'] ?? null) === 'manual';

		return ! $manual && preg_match(self::MICRO_NAME, (string) ($action['name'] ?? '')) ? 'likely' : null;
	}

	private static function editable(array $action, string $field): bool
	{
		return in_array($field, (array) ($action['editable'] ?? []), true);
	}

	private static function readOnlyReason(array $action): string
	{
		return ($action['external_id'] ?? null) === null
			? 'Działanie pochodzi z importu (brak identyfikatora Google Ads) – połącz konto i zsynchronizuj dane, aby przygotować zmianę.'
			: 'Tego pola nie można zmienić przez API dla działania typu ' . ($action['type'] ?? 'nieznanego') . ' – zmień je w Google Ads.';
	}

	private static function similarNames(string $a, string $b): bool
	{
		$tokens = fn (string $s) => array_values(array_filter(explode(' ', Text::normalize(preg_replace('/\b(gads|google|ads|ga4|analytics|tag)\b/iu', ' ', $s))), fn ($t) => mb_strlen($t) > 2));
		$x = $tokens($a);
		$y = $tokens($b);

		if ($x === [] || $y === []) {
			return false;
		}

		$common = count(array_intersect($x, $y));

		return $common / min(count($x), count($y)) >= 0.6;
	}
}
