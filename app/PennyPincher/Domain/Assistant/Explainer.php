<?php

namespace App\PennyPincher\Domain\Assistant;

use App\PennyPincher\Domain\Analysis\Statistics;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
use App\PennyPincher\Support\Format;

/**
 * Asystent (pp2_assistant): wyjaśnienie istniejącej rekomendacji – co wykryto, na podstawie jakich danych, jak pewny
 * jest wynik i jaki jest bezpieczny następny krok. Deterministyczny i bez bazy: nie wylicza nowych liczb, przytacza
 * wyłącznie treść i dowody zapisane przez reguły (audyt, Budget Leaks, analizy) oraz daty okresu. Nie zmienia statusów.
 */
final class Explainer
{
	public const ENGINE = 'Reguły Penny Pincher (bez modelu AI)';

	private const CONFIDENCE_HINTS = [
		Statistics::HIGH => 'dane wystarczają do jednoznacznego wniosku',
		Statistics::MEDIUM => 'wniosek prawdopodobny – sprawdź dowody przed decyzją',
		Statistics::LOW => 'mała próba danych – traktuj jako sygnał do obserwacji, nie do zmiany',
	];

	/**
	 * @param array $rec rekomendacja z RecommendationRepository::find (evidence = pary [etykieta, wartość])
	 * @param array|null $change powiązany zestaw Change Center (id, status) albo null
	 * @param bool $deployable czy rekomendację da się zamienić na operacje Google Ads API
	 * @return array{what: string, action: string, evidence: array, basis: array, estimate: ?string, caveats: array, next: array}
	 */
	public static function recommendation(array $rec, ?array $change, bool $deployable): array
	{
		$sources = array_map(fn ($s) => RecommendationGenerator::SOURCES[$s] ?? $s, (array) ($rec['sources'] ?? [$rec['source']]));
		$confidence = (string) $rec['confidence'];
		$caveats = [];

		if (! empty($rec['is_obsolete'])) {
			$caveats[] = 'Rekomendacja jest nieaktualna – ostatnia analiza już jej nie potwierdziła.';
		}

		if ($confidence === Statistics::LOW) {
			$caveats[] = 'Niska pewność: brak konwersji przy małej liczbie kliknięć nie oznacza straty.';
		}

		if (! empty($rec['impact_label'])) {
			$caveats[] = 'Wpływ to szacunek z reguły, nie gwarantowany wynik; szacunków z różnych rekomendacji nie sumujemy.';
		}

		if ($rec['evidence'] === [] || $rec['evidence'] === null) {
			$caveats[] = 'Reguła nie zapisała szczegółowych dowodów – podstawą jest opis wyniku.';
		}

		return [
			'what' => (string) $rec['description'],
			'action' => (string) $rec['action'],
			'evidence' => array_values(array_filter((array) $rec['evidence'], 'is_array')),
			'basis' => [
				'Źródło' => implode(' + ', $sources),
				'Okres danych' => Format::period($rec['date_from'] ?? null, $rec['date_to'] ?? null),
				'Pewność' => (Statistics::CONFIDENCE_LABELS[$confidence] ?? $confidence) . (isset(self::CONFIDENCE_HINTS[$confidence]) ? ' – ' . self::CONFIDENCE_HINTS[$confidence] : ''),
				'Status' => RecommendationGenerator::STATUSES[$rec['status']] ?? (string) $rec['status'],
			],
			'estimate' => $rec['impact_label'] ?: null,
			'caveats' => $caveats,
			'next' => self::next($rec, $change, $deployable),
		];
	}

	/**
	 * Następny krok wg stanu – zawsze w module źródłowym; zmiany na koncie wyłącznie przez Change Center z zatwierdzeniem.
	 *
	 * @return array<int, array{key: string, label: string, consent: bool}>
	 */
	public static function next(array $rec, ?array $change, bool $deployable): array
	{
		$status = (string) $rec['status'];

		if (! empty($rec['is_obsolete'])) {
			return [['key' => 'recommendation', 'label' => 'Sprawdź, czy problem nadal występuje (po kolejnej analizie), zanim cokolwiek zmienisz.', 'consent' => false]];
		}

		if (in_array($status, ['new', 'to_review'], true)) {
			return [['key' => 'recommendation', 'label' => 'Oceń rekomendację: zaakceptuj albo odrzuć z notatką (decyzja należy do Ciebie).', 'consent' => false]];
		}

		if ($status === 'accepted') {
			if ($change !== null) {
				return [['key' => 'change', 'label' => 'Zmiana na koncie #' . (int) $change['id'] . ' ma status „' . (ChangeStatus::LABELS[$change['status']] ?? $change['status']) . '” – kontynuuj w Change Center (podgląd przed/po, zatwierdzenie, wdrożenie).', 'consent' => true]];
			}

			return $deployable
				? [['key' => 'prepare', 'label' => 'Przygotuj szkic zmiany w Change Center – nic nie trafi do Google Ads bez zatwierdzenia i polecenia wdrożenia.', 'consent' => true]]
				: [['key' => 'action', 'label' => 'Wykonaj zmianę ręcznie (poza Google Ads API) i oznacz zadanie jako wykonane w planie działania.', 'consent' => false]];
		}

		if ($status === 'done') {
			return [['key' => 'monitoring', 'label' => 'Obserwuj efekty po kilku pełnych dniach w Monitoringu (porównanie przed/po, nie dowód przyczynowości).', 'consent' => false]];
		}

		return [['key' => 'recommendation', 'label' => 'Rekomendacja odrzucona – możesz przywrócić ją do oceny w Rekomendacjach.', 'consent' => false]];
	}
}
