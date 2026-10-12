<?php

namespace App\PennyPincher\Domain\Audit;

/**
 * Audit Score 0–100 – ocena higieny konta, NIE wskaźnik rentowności (to nie jest ROAS).
 *
 * Każdy obszar (Dimension) ma pulę punktów; każdy problem odejmuje swoją karę (penalty) z puli swojego obszaru,
 * ale nie więcej niż wynosi pula. Problemy oznaczone jako „Pominięte” nie obniżają wyniku.
 * Gdy danych jest za mało, wynik NIE jest liczony (null) – zamiast pseudo-precyzyjnej liczby pokazujemy powód.
 */
final class AuditScore
{
	/** Minimalne pokrycie okresu audytu danymi kampanii, od którego liczymy wynik. */
	public const MIN_COVERAGE = 0.5;

	public const NO_ACTIVITY = 'W wybranym okresie kampanie nie generowały wydatków ani wyświetleń. Wybierz wcześniejszy okres, aby przeanalizować wyniki historyczne.';

	/**
	 * @param array<int, array{severity: string, penalty: float|string, score_dimension: string, status?: string}> $findings
	 * @return array{score: ?int, reason: ?string, dimensions: array<string, array{label: string, max: int, penalty: float, points: float, findings: int}>}
	 */
	public static function calculate(array $findings, ?string $unavailableReason = null): array
	{
		$dimensions = [];

		foreach (Dimension::cases() as $dimension) {
			$dimensions[$dimension->value] = ['label' => $dimension->label(), 'max' => $dimension->maxPoints(), 'penalty' => 0.0, 'points' => (float) $dimension->maxPoints(), 'findings' => 0];
		}

		foreach ($findings as $finding) {
			if (($finding['status'] ?? 'new') === 'dismissed' || ! isset($dimensions[$finding['score_dimension']])) {
				continue;
			}

			$key = $finding['score_dimension'];
			$dimensions[$key]['penalty'] += (float) $finding['penalty'];
			$dimensions[$key]['findings']++;
		}

		$total = 0.0;

		foreach ($dimensions as $key => $dimension) {
			$dimensions[$key]['penalty'] = round($dimension['penalty'], 2);
			$dimensions[$key]['points'] = round(max(0, $dimension['max'] - $dimension['penalty']), 2);
			$total += $dimensions[$key]['points'];
		}

		return [
			'score' => $unavailableReason === null ? (int) round($total) : null,
			'reason' => $unavailableReason,
			'dimensions' => $dimensions,
		];
	}

	/**
	 * Powód, dla którego wyniku nie liczymy, albo null.
	 */
	public static function unavailableReason(AuditContext $context): ?string
	{
		if ($context->dataState() === 'no_activity') {
			return self::NO_ACTIVITY;
		}

		if (! $context->hasCampaignData()) {
			return 'Brak danych o kampaniach w wybranym okresie – połącz Google Ads albo zaimportuj raport kampanii.';
		}

		$coverage = $context->coverage;

		if ($coverage->totalDays > 0 && min($coverage->coveredDays, $coverage->totalDays) / $coverage->totalDays < self::MIN_COVERAGE) {
			return sprintf('Dane obejmują tylko %d z %d dni okresu audytu – za mało, żeby rzetelnie policzyć wynik.', min($coverage->coveredDays, $coverage->totalDays), $coverage->totalDays);
		}

		return null;
	}

	public static function label(?int $score): string
	{
		return match (true) {
			$score === null => 'Brak wyniku',
			$score >= 85 => 'Dobra kondycja',
			$score >= 65 => 'Wymaga uwagi',
			$score >= 40 => 'Istotne problemy',
			default => 'Krytyczny stan',
		};
	}

	public static function tone(?int $score): string
	{
		return match (true) {
			$score === null => 'neutral',
			$score >= 85 => 'success',
			$score >= 65 => 'warning',
			default => 'danger',
		};
	}
}
