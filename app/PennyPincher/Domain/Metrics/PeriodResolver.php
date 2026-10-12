<?php

namespace App\PennyPincher\Domain\Metrics;

/**
 * Wybiera zestaw danych, który można bezpiecznie zsumować dla zakresu dat.
 *
 * Dane mają dwie ziarnistości:
 * - `day`    – wiersz dotyczy jednego dnia (raport z kolumną Dzień),
 * - `period` – wiersz dotyczy całego zakresu raportu (eksport bez kolumny Dzień).
 *
 * Zasady:
 * 1. Bierzemy tylko dane mieszczące się w całości w zakresie – nie dzielimy okresów na dni.
 * 2. Nigdy nie sumujemy nakładających się danych (dzień zawarty w okresie, dwa nakładające się okresy).
 * 3. Liczymy dwa warianty: „najpierw dni” i „najpierw okresy” (okresy zachłannie od najdłuższego)
 *    i wybieramy ten, który pokrywa więcej dni zakresu; przy remisie wygrywają dane dzienne.
 * 4. Pierwszeństwo źródeł: dni objęte kompletną synchronizacją z Google Ads API (`$apiCoverage`) są brane
 *    wyłącznie z API. Importy uzupełniają tylko pozostałe dni; raport okresowy z importu nakładający się
 *    choćby na jeden dzień z API jest pomijany (nie da się go podzielić) i raportowany jako konflikt źródeł.
 */
final class PeriodResolver
{
	/**
	 * @param array<int, array{granularity: string, date_from: string, date_to: string, source?: string}> $available
	 * @param array<int, array{0: string, 1: string}> $apiCoverage zakresy dat zsynchronizowane z API
	 */
	public static function resolve(array $available, DateRange $range, array $apiCoverage = []): ResolvedPeriods
	{
		$apiRanges = self::clipAndMerge($apiCoverage, $range);
		$apiDayCount = self::periodDays($apiRanges);

		$days = [];
		$periods = [];
		$outside = [];
		$overridden = 0;

		foreach ($available as $row) {
			if (($row['source'] ?? 'import') === 'api') {
				continue;
			}

			$from = (string) $row['date_from'];
			$to = (string) $row['date_to'];

			if ($row['granularity'] === 'day') {
				if ($range->contains($from)) {
					if (self::dayInPeriods($from, $apiRanges)) {
						$overridden++;
					} else {
						$days[$from] = true;
					}
				}

				continue;
			}

			if (self::overlapsAny($from, $to, $apiRanges)) {
				if ($from <= $range->toString() && $to >= $range->fromString()) {
					$overridden++;
				}

				continue;
			}

			if ($from >= $range->fromString() && $to <= $range->toString()) {
				$periods[$from . '|' . $to] = [$from, $to];
			} elseif ($from <= $range->toString() && $to >= $range->fromString()) {
				$outside[$from . '|' . $to] = [$from, $to];
			}
		}

		$days = array_keys($days);
		sort($days);

		// Okresy od najdłuższego; przy równej długości – nowszy.
		$periods = array_values($periods);
		usort($periods, function (array $a, array $b) {
			$lengthA = self::length($a[0], $a[1]);
			$lengthB = self::length($b[0], $b[1]);

			return $lengthB <=> $lengthA ?: strcmp($b[0], $a[0]);
		});

		$dayFirst = self::pickPeriods($periods, $days, []);
		$dayFirstCoverage = count($days) + self::periodDays($dayFirst);

		$periodFirst = self::pickPeriods($periods, [], []);
		$daysOutsidePeriods = array_values(array_filter($days, fn (string $day) => ! self::dayInPeriods($day, $periodFirst)));
		$periodFirstCoverage = count($daysOutsidePeriods) + self::periodDays($periodFirst);

		if ($periodFirstCoverage > $dayFirstCoverage) {
			$chosenDays = $daysOutsidePeriods;
			$chosenPeriods = $periodFirst;
		} else {
			$chosenDays = $days;
			$chosenPeriods = $dayFirst;
		}

		$excluded = array_values(array_filter($periods, fn (array $p) => ! in_array($p, $chosenPeriods, true)));

		return new ResolvedPeriods(
			days: $chosenDays,
			periods: $chosenPeriods,
			excludedPeriods: $excluded,
			outsidePeriods: array_values($outside),
			excludedDays: count($days) - count($chosenDays),
			coveredDays: $apiDayCount + count($chosenDays) + self::periodDays($chosenPeriods),
			totalDays: $range->days(),
			apiRanges: $apiRanges,
			overriddenByApi: $overridden,
		);
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $candidates
	 * @param array<int, string> $days
	 * @param array<int, array{0: string, 1: string}> $chosen
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function pickPeriods(array $candidates, array $days, array $chosen): array
	{
		foreach ($candidates as $candidate) {
			foreach ($chosen as $existing) {
				if ($candidate[0] <= $existing[1] && $candidate[1] >= $existing[0]) {
					continue 2;
				}
			}

			foreach ($days as $day) {
				if ($day >= $candidate[0] && $day <= $candidate[1]) {
					continue 2;
				}
			}

			$chosen[] = $candidate;
		}

		usort($chosen, fn (array $a, array $b) => strcmp($a[0], $b[0]));

		return $chosen;
	}

	/**
	 * Przycina zakresy do zakresu dat i scala nakładające się / sąsiadujące.
	 *
	 * @param array<int, array{0: string, 1: string}> $ranges
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function clipAndMerge(array $ranges, DateRange $range): array
	{
		$clipped = [];

		foreach ($ranges as [$from, $to]) {
			$from = max((string) $from, $range->fromString());
			$to = min((string) $to, $range->toString());

			if ($from <= $to) {
				$clipped[] = [$from, $to];
			}
		}

		usort($clipped, fn ($a, $b) => strcmp($a[0], $b[0]));
		$merged = [];

		foreach ($clipped as [$from, $to]) {
			$last = count($merged) - 1;
			$dayAfterLast = $last >= 0 ? (new \DateTimeImmutable($merged[$last][1]))->modify('+1 day')->format('Y-m-d') : null;

			if ($last >= 0 && $from <= $dayAfterLast) {
				$merged[$last][1] = max($merged[$last][1], $to);
			} else {
				$merged[] = [$from, $to];
			}
		}

		return $merged;
	}

	private static function overlapsAny(string $from, string $to, array $ranges): bool
	{
		foreach ($ranges as [$rangeFrom, $rangeTo]) {
			if ($from <= $rangeTo && $to >= $rangeFrom) {
				return true;
			}
		}

		return false;
	}

	private static function dayInPeriods(string $day, array $periods): bool
	{
		foreach ($periods as [$from, $to]) {
			if ($day >= $from && $day <= $to) {
				return true;
			}
		}

		return false;
	}

	private static function periodDays(array $periods): int
	{
		return array_sum(array_map(fn (array $p) => self::length($p[0], $p[1]), $periods));
	}

	private static function length(string $from, string $to): int
	{
		return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
	}
}
