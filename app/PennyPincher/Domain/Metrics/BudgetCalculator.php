<?php

namespace App\PennyPincher\Domain\Metrics;

/**
 * Budżet miesięczny przeliczony na zakres dat.
 *
 * Dla każdego miesiąca, który obejmuje zakres: budżet × (dni zakresu w miesiącu / dni miesiąca).
 * Dzięki temu „wykorzystanie budżetu” ma sens także dla zakresów krótszych lub dłuższych niż miesiąc.
 */
final class BudgetCalculator
{
	public static function budgetForRange(?int $monthlyBudgetMicros, DateRange $range): ?int
	{
		if ($monthlyBudgetMicros === null) {
			return null;
		}

		$total = 0.0;
		$cursor = $range->from;

		while ($cursor <= $range->to) {
			$monthEnd = $cursor->modify('last day of this month');
			$segmentEnd = $monthEnd < $range->to ? $monthEnd : $range->to;
			$daysInSegment = (int) $cursor->diff($segmentEnd)->days + 1;
			$daysInMonth = (int) $cursor->format('t');

			$total += $monthlyBudgetMicros * $daysInSegment / $daysInMonth;
			$cursor = $segmentEnd->modify('+1 day');
		}

		return (int) round($total);
	}
}
