<?php

namespace App\PennyPincher\Domain\Metrics;

/**
 * Wynik PeriodResolver: które dane dzienne i okresowe wchodzą do sumy oraz jakie pokrycie zakresu dają.
 */
final class ResolvedPeriods
{
	/**
	 * @param array<int, string> $days
	 * @param array<int, array{0: string, 1: string}> $periods
	 * @param array<int, array{0: string, 1: string}> $excludedPeriods okresy w zakresie pominięte z powodu nakładania się
	 * @param array<int, array{0: string, 1: string}> $outsidePeriods okresy tylko częściowo w zakresie (nie da się ich użyć)
	 */
	public function __construct(
		public readonly array $days,
		public readonly array $periods,
		public readonly array $excludedPeriods,
		public readonly array $outsidePeriods,
		public readonly int $excludedDays,
		public readonly int $coveredDays,
		public readonly int $totalDays,
	) {
	}

	public function isEmpty(): bool
	{
		return $this->days === [] && $this->periods === [];
	}

	public function isComplete(): bool
	{
		return $this->coveredDays >= $this->totalDays;
	}

	public function hasDaily(): bool
	{
		return $this->days !== [];
	}

	public function source(): string
	{
		return match (true) {
			$this->isEmpty() => 'none',
			$this->days !== [] && $this->periods !== [] => 'mixed',
			$this->days !== [] => 'day',
			default => 'period',
		};
	}
}
