<?php

namespace App\PennyPincher\Domain\Keywords;

/**
 * Keyword Opportunity Score 0–100 – heurystyka porządkująca propozycje, a nie prognoza ROI.
 *
 * Wagi (konfigurowalne tylko tutaj): dopasowanie do oferty 35, intencja zakupowa 25, opłacalność CPC 15,
 * potencjał wyszukiwania 10, brak pokrycia w kampaniach 15. Składnik bez danych dostaje 0 punktów
 * (nigdy maksimum), a brakujące dane obniżają pewność oceny.
 */
final class OpportunityScore
{
	public const WEIGHTS = [
		'offer_fit' => 35,
		'intent' => 25,
		'cpc' => 15,
		'volume' => 10,
		'coverage' => 15,
	];

	public const LABELS = [
		'offer_fit' => 'Dopasowanie do oferty',
		'intent' => 'Intencja zakupowa',
		'cpc' => 'Opłacalność CPC',
		'volume' => 'Potencjał wyszukiwania',
		'coverage' => 'Brak pokrycia w kampaniach',
	];

	/**
	 * @param array<string, float|null> $components wartości 0–1 albo null (brak danych)
	 * @return array{score: int, breakdown: array<string, array{value: ?float, points: float, max: int}>, confidence: string, missing: array<int, string>}
	 */
	public static function calculate(array $components): array
	{
		$breakdown = [];
		$score = 0.0;
		$missing = [];

		foreach (self::WEIGHTS as $key => $weight) {
			$value = $components[$key] ?? null;
			$value = $value === null ? null : max(0.0, min(1.0, (float) $value));
			$points = $value === null ? 0.0 : round($value * $weight, 1);
			$score += $points;
			$breakdown[$key] = ['value' => $value, 'points' => $points, 'max' => $weight];

			if ($value === null) {
				$missing[] = self::LABELS[$key];
			}
		}

		$known = count(self::WEIGHTS) - count($missing);
		$confidence = match (true) {
			$known >= 5 => 'high',
			$known >= 3 => 'medium',
			default => 'low',
		};

		return ['score' => (int) round($score), 'breakdown' => $breakdown, 'confidence' => $confidence, 'missing' => $missing];
	}

	/**
	 * Potencjał wyszukiwania: skala logarytmiczna, 10 000 wyszukiwań miesięcznie = maksimum.
	 */
	public static function volume(?int $searchVolume): ?float
	{
		return $searchVolume === null ? null : min(1.0, log10($searchVolume + 1) / 4);
	}

	/**
	 * Opłacalność CPC względem punktu odniesienia (średni CPC konta w tej samej walucie): 1 przy CPC ≤ połowy
	 * odniesienia, 0 przy CPC ≥ 2× odniesienia. Bez wspólnej waluty – null (nie porównujemy walut bez kursu).
	 */
	public static function cpc(?int $cpcMicros, ?string $cpcCurrency, ?int $referenceMicros, string $projectCurrency): ?float
	{
		if ($cpcMicros === null || $referenceMicros === null || $referenceMicros <= 0 || $cpcCurrency !== $projectCurrency) {
			return null;
		}

		$ratio = $cpcMicros / $referenceMicros;

		return max(0.0, min(1.0, (2 - $ratio) / 1.5));
	}

	public static function intent(string $primaryIntent): float
	{
		return match ($primaryIntent) {
			'commercial' => 1.0,
			'unknown' => 0.5,
			'informational' => 0.25,
			default => 0.0,
		};
	}
}
