<?php

namespace App\PennyPincher\Domain\Monitoring;

use App\PennyPincher\Support\Format;

/**
 * Wykrywanie zdarzeń wymagających uwagi – deterministycznie, z ograniczaniem fałszywych alarmów:
 * - porównujemy ostatnie 7 dni z poprzednimi 28 (pełne tygodnie – ten sam rozkład dni tygodnia),
 *   nigdy dnia z dniem,
 * - progi minimalnej liczby kliknięć, wyświetleń i kosztu,
 * - kampanie wstrzymane / usunięte i dni bez aktywności nie generują alarmów o spadkach,
 * - niepełne dane w oknie = brak oceny (alarm o jakości danych zamiast fałszywego spadku).
 *
 * Wejście przygotowuje MonitoringService (dane z bazy); klasa nie czyta bazy i nie wysyła powiadomień.
 */
final class AlertDetector
{
	public const TYPES = [
		'cpc_spike' => 'Gwałtowny wzrost CPC',
		'ctr_drop' => 'Spadek CTR',
		'spend_no_conversions' => 'Wydatki bez konwersji',
		'no_activity' => 'Brak aktywności kampanii',
		'pacing' => 'Tempo wydatków ponad budżet',
		'leads_drop' => 'Spadek liczby leadów',
		'measurement' => 'Pogorszenie jakości pomiaru',
		'sync_failed' => 'Problem z synchronizacją',
		'stale_data' => 'Nieaktualne dane',
		'audit' => 'Nowy krytyczny problem audytu',
	];

	public const MIN_CLICKS_RECENT = 30;

	public const MIN_CLICKS_BASELINE = 100;

	public const MIN_IMPRESSIONS_RECENT = 1000;

	public const CPC_SPIKE = 1.4;

	public const CTR_DROP = 0.7;

	/**
	 * @param array{
	 *   currency: string,
	 *   min_cost_micros: int,
	 *   recent_complete: bool,
	 *   baseline_complete: bool,
	 *   campaigns: array<int, array{name: string, status: ?string, recent: array, baseline: array, last3_impressions: ?int, baseline_active_days: int}>,
	 *   goals: array{recent: ?float, previous: ?float, recent_cost: int, previous_cost: int, google_recent: float, google_previous: float, micro_recent: float, micro_previous: float},
	 *   pacing: ?array,
	 *   sync: array{status: ?string, last_run_status: ?string, last_completed_at: ?string, now: string},
	 *   audit_new_critical: array<int, array{fingerprint: string, title: string, id: int}>,
	 *   period: array{recent_from: string, recent_to: string, baseline_from: string, baseline_to: string}
	 * } $input
	 * @return array<string, array{type: string, severity: string, title: string, message: string, evidence: array, campaign_id: ?int, period_from: ?string, period_to: ?string}>
	 */
	public static function detect(array $input): array
	{
		$alerts = [];
		$currency = $input['currency'];
		$period = $input['period'];
		$add = function (string $type, string $severity, string $title, string $message, array $evidence, ?int $campaignId = null, ?string $entity = null) use (&$alerts, $period) {
			$alerts[sha1($type . '|' . ($entity ?? ($campaignId !== null ? 'c' . $campaignId : 'account')))] = [
				'type' => $type, 'severity' => $severity, 'title' => $title, 'message' => $message, 'evidence' => $evidence,
				'campaign_id' => $campaignId, 'period_from' => $period['recent_from'], 'period_to' => $period['recent_to'],
			];
		};

		// Synchronizacja i świeżość danych – zawsze, bo bez nich pozostałe reguły milczą.
		$sync = $input['sync'];

		if (in_array($sync['status'], ['reauth_required', 'error'], true)) {
			$add('sync_failed', 'critical', 'Połączenie z Google Ads wymaga uwagi', $sync['status'] === 'reauth_required' ? 'Google odrzucił autoryzację – synchronizacja nie działa, dopóki nie połączysz konta ponownie.' : 'Połączenie z Google Ads zgłasza błąd.', [['Status połączenia', $sync['status']]]);
		} elseif ($sync['last_run_status'] === 'failed') {
			$add('sync_failed', 'warning', 'Ostatnia synchronizacja nie powiodła się', 'Dane mogą być nieaktualne. Sprawdź szczegóły na stronie Google Ads i ponów synchronizację.', [['Ostatni przebieg', 'błąd']]);
		}

		if ($sync['status'] === 'active') {
			$age = $sync['last_completed_at'] ? (strtotime($sync['now']) - strtotime($sync['last_completed_at'])) / 3600 : null;

			if ($age === null || $age > 48) {
				$add('stale_data', $age === null || $age > 168 ? 'critical' : 'warning', 'Dane nie są aktualne', $age === null ? 'Nie ma jeszcze zakończonej synchronizacji.' : sprintf('Ostatnia udana synchronizacja była %d h temu (oczekiwana codziennie).', (int) round($age)), [['Ostatnia synchronizacja', $sync['last_completed_at'] ?? 'brak']]);
			}
		}

		// Niepełne dane w oknach = brak ocen skuteczności (nie generujemy fałszywych spadków).
		$dataOk = $input['recent_complete'] && $input['baseline_complete'];

		foreach ($dataOk ? $input['campaigns'] : [] as $id => $c) {
			$r = $c['recent'];
			$b = $c['baseline'];
			$enabled = in_array($c['status'], ['enabled', null], true);

			// Kampania wstrzymana/usunięta: brak alarmów o spadkach (świadomie zatrzymana).
			if (! $enabled) {
				continue;
			}

			$cpcR = $r['clicks'] > 0 ? $r['cost_micros'] / $r['clicks'] : null;
			$cpcB = $b['clicks'] > 0 ? $b['cost_micros'] / $b['clicks'] : null;

			if ($cpcR !== null && $cpcB !== null && $r['clicks'] >= self::MIN_CLICKS_RECENT && $b['clicks'] >= self::MIN_CLICKS_BASELINE && $r['cost_micros'] >= $input['min_cost_micros'] && $cpcR / $cpcB >= self::CPC_SPIKE) {
				$ratio = $cpcR / $cpcB;
				$add('cpc_spike', $ratio >= 1.8 ? 'critical' : 'warning', sprintf('CPC wzrósł o %s: „%s”', Format::percent(($ratio - 1) * 100, 0), $c['name']),
					'Średni koszt kliknięcia w ostatnich 7 dniach jest wyraźnie wyższy niż w 4 poprzednich tygodniach. Sprawdź konkurencję w aukcjach, zmiany stawek lub strategii i wyszukiwane hasła.',
					[['CPC (7 dni)', Format::money((int) round($cpcR), $currency)], ['CPC (28 dni wcześniej)', Format::money((int) round($cpcB), $currency)], ['Kliknięcia 7 / 28 dni', $r['clicks'] . ' / ' . $b['clicks']]], $id);
			}

			$ctrR = $r['impressions'] > 0 ? $r['clicks'] / $r['impressions'] : null;
			$ctrB = $b['impressions'] > 0 ? $b['clicks'] / $b['impressions'] : null;

			if ($ctrR !== null && $ctrB !== null && $ctrB > 0 && $r['impressions'] >= self::MIN_IMPRESSIONS_RECENT && $b['impressions'] >= 3 * self::MIN_IMPRESSIONS_RECENT && $ctrR / $ctrB <= self::CTR_DROP) {
				$add('ctr_drop', 'warning', sprintf('CTR spadł o %s: „%s”', Format::percent((1 - $ctrR / $ctrB) * 100, 0), $c['name']),
					'Reklamy są rzadziej klikane niż w poprzednich tygodniach. Możliwe przyczyny: nowe, mniej trafne zapytania, gorsza pozycja reklam albo mocniejsza konkurencja.',
					[['CTR (7 dni)', Format::percent($ctrR * 100, 2)], ['CTR (28 dni wcześniej)', Format::percent($ctrB * 100, 2)], ['Wyświetlenia 7 / 28 dni', $r['impressions'] . ' / ' . $b['impressions']]], $id);
			}

			// Wydatki bez konwersji: tylko gdy przy dotychczasowym współczynniku oczekiwaliśmy ≥ 3 konwersji.
			$rateB = $b['clicks'] > 0 ? $b['conversions'] / $b['clicks'] : 0.0;
			$expected = $r['clicks'] * $rateB;
			$cpaB = $b['conversions'] > 0 ? $b['cost_micros'] / $b['conversions'] : null;

			if ($r['conversions'] == 0 && $b['conversions'] >= 4 && $expected >= 3 && $r['cost_micros'] >= max($input['min_cost_micros'], (int) round(2 * ($cpaB ?? 0)))) {
				$add('spend_no_conversions', $expected >= 6 ? 'critical' : 'warning', sprintf('Wydatki bez konwersji: „%s”', $c['name']),
					sprintf('W ostatnich 7 dniach kampania wydała %s bez żadnej konwersji, choć przy dotychczasowym współczynniku spodziewaliśmy się ok. %s. Sprawdź pomiar (czy tag działa), stronę docelową i wyszukiwane hasła.', Format::money($r['cost_micros'], $currency), Format::conversions($expected)),
					[['Wydatki (7 dni)', Format::money($r['cost_micros'], $currency)], ['Kliknięcia (7 dni)', (string) $r['clicks']], ['Konwersje 28 dni wcześniej', Format::conversions($b['conversions'])]], $id);
			}

			// Brak aktywności: kampania aktywna w ustawieniach, wcześniej regularnie aktywna, a od 3 dni bez wyświetleń.
			if ($c['last3_impressions'] === 0 && $c['baseline_active_days'] >= 14 && $b['cost_micros'] > 0) {
				$add('no_activity', 'warning', sprintf('Kampania przestała się wyświetlać: „%s”', $c['name']),
					'Kampania jest włączona, ale od 3 dni nie ma wyświetleń, choć wcześniej działała regularnie. Sprawdź płatności, budżet, stan reklam (odrzucone?) i harmonogram.',
					[['Dni z aktywnością w 28 dniach', (string) $c['baseline_active_days']], ['Wyświetlenia w ostatnich 3 dniach', '0']], $id);
			}
		}

		// Tempo miesiąca.
		$pacing = $input['pacing'];

		if ($pacing && ($pacing['forecast_share'] ?? null) !== null && ($pacing['elapsed_days'] ?? 0) >= 5 && $pacing['forecast_share'] >= 115) {
			$add('pacing', $pacing['forecast_share'] >= 130 ? 'critical' : 'warning', 'Wydatki w tym miesiącu przekroczą budżet',
				sprintf('Przy obecnym tempie miesiąc zamknie się na ok. %s budżetu (szacunek liniowy). Rozważ obniżenie budżetów dziennych.', Format::percent($pacing['forecast_share'], 0)),
				[['Wydano', Format::money($pacing['spend'], $currency)], ['Budżet miesięczny', Format::money($pacing['budget'], $currency)], ['Prognoza', Format::money($pacing['forecast'], $currency)]], null, 'pacing:' . $pacing['month']);
		}

		// Leady (cele biznesowe) – 14 dni vs poprzednie 14; bez alarmu, gdy wydatki też spadły (świadome ograniczenie).
		$g = $input['goals'];

		if ($g['recent'] !== null && $g['previous'] !== null && $g['previous'] >= 6 && $g['recent'] <= 0.5 * $g['previous'] && $g['recent_cost'] >= 0.7 * $g['previous_cost'] && $g['previous_cost'] > 0) {
			$add('leads_drop', 'warning', 'Istotny spadek liczby leadów',
				sprintf('Konwersje oznaczone jako cel biznesowy spadły z %s do %s (14 dni do 14 dni), choć wydatki pozostały podobne. Sprawdź formularz i pomiar na stronie oraz wyszukiwane hasła.', Format::conversions($g['previous']), Format::conversions($g['recent'])),
				[['Cele (14 dni)', Format::conversions($g['recent'])], ['Cele (poprzednie 14 dni)', Format::conversions($g['previous'])], ['Wydatki 14 / poprzednie 14 dni', Format::money($g['recent_cost'], $currency) . ' / ' . Format::money($g['previous_cost'], $currency)]]);
		}

		// Jakość pomiaru.
		$microNow = $g['google_recent'] > 0 ? $g['micro_recent'] / $g['google_recent'] : null;
		$microBefore = $g['google_previous'] > 0 ? $g['micro_previous'] / $g['google_previous'] : null;

		if (($microNow !== null && $microBefore !== null && $g['google_previous'] >= 5 && $microNow >= 0.5 && $microBefore < 0.3)
			|| ($g['recent'] !== null && $g['previous'] !== null && $g['recent'] == 0 && $g['previous'] > 0 && $g['google_recent'] >= 5)) {
			$add('measurement', 'warning', 'Pogorszyła się jakość pomiaru konwersji',
				'Konwersje Google Ads w ostatnich 14 dniach to głównie mikrokonwersje albo zniknęły konwersje celów biznesowych przy dalszych konwersjach Google. Sprawdź, czy nie zmieniono ustawień konwersji lub tagów.',
				[['Udział mikrokonwersji teraz / wcześniej', ($microNow === null ? '—' : Format::percent($microNow * 100, 0)) . ' / ' . ($microBefore === null ? '—' : Format::percent($microBefore * 100, 0))], ['Cele teraz / wcześniej', Format::conversions($g['recent']) . ' / ' . Format::conversions($g['previous'])]]);
		}

		foreach ($input['audit_new_critical'] as $finding) {
			$add('audit', 'warning', 'Nowy krytyczny problem audytu: ' . $finding['title'], 'Najnowszy audyt wykrył krytyczny problem, którego nie było w poprzednim audycie.', [['Problem', $finding['title']]], null, 'audit:' . $finding['fingerprint']);
		}

		return $alerts;
	}
}
