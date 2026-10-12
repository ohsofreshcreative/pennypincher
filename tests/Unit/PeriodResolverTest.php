<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\PeriodResolver;
use Tests\TestCase;

class PeriodResolverTest extends TestCase
{
	private function day(string $date): array
	{
		return ['granularity' => 'day', 'date_from' => $date, 'date_to' => $date];
	}

	private function period(string $from, string $to): array
	{
		return ['granularity' => 'period', 'date_from' => $from, 'date_to' => $to];
	}

	public function testDailyDataInRange(): void
	{
		$resolved = PeriodResolver::resolve([$this->day('2026-09-01'), $this->day('2026-09-02'), $this->day('2026-10-01')], new DateRange('2026-09-01', '2026-09-30'));

		$this->assertSame(['2026-09-01', '2026-09-02'], $resolved->days);
		$this->assertSame(2, $resolved->coveredDays);
		$this->assertFalse($resolved->isComplete());
		$this->assertSame('day', $resolved->source());
	}

	public function testPeriodMustFitEntirelyInRange(): void
	{
		$available = [$this->period('2026-09-01', '2026-09-30')];

		$inside = PeriodResolver::resolve($available, new DateRange('2026-09-01', '2026-09-30'));
		$this->assertSame([['2026-09-01', '2026-09-30']], $inside->periods);
		$this->assertTrue($inside->isComplete());

		// Nie dzielimy okresu na dni – częściowe pokrycie oznacza brak danych w zakresie.
		$partial = PeriodResolver::resolve($available, new DateRange('2026-09-15', '2026-10-15'));
		$this->assertTrue($partial->isEmpty());
		$this->assertSame([['2026-09-01', '2026-09-30']], $partial->outsidePeriods);
	}

	public function testOverlappingPeriodsAreNotSummed(): void
	{
		$resolved = PeriodResolver::resolve([
			$this->period('2026-09-01', '2026-09-30'),
			$this->period('2026-09-15', '2026-09-21'),
			$this->period('2026-10-01', '2026-10-31'),
		], new DateRange('2026-09-01', '2026-10-31'));

		$this->assertSame([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31']], $resolved->periods);
		$this->assertSame([['2026-09-15', '2026-09-21']], $resolved->excludedPeriods);
		$this->assertSame(61, $resolved->coveredDays);
	}

	public function testDailyAndPeriodDataDoNotDoubleCount(): void
	{
		// Pełny wrzesień jako okres + 3 dni dziennych danych z września: wygrywa wariant z większym pokryciem.
		$resolved = PeriodResolver::resolve([
			$this->period('2026-09-01', '2026-09-30'),
			$this->day('2026-09-01'),
			$this->day('2026-09-02'),
			$this->day('2026-09-03'),
		], new DateRange('2026-09-01', '2026-09-30'));

		$this->assertSame([['2026-09-01', '2026-09-30']], $resolved->periods);
		$this->assertSame([], $resolved->days);
		$this->assertSame(3, $resolved->excludedDays);

		// Pełne dane dzienne mają pierwszeństwo przy remisie pokrycia.
		$days = array_map(fn ($d) => $this->day(sprintf('2026-09-%02d', $d)), range(1, 30));
		$resolved = PeriodResolver::resolve([...$days, $this->period('2026-09-01', '2026-09-30')], new DateRange('2026-09-01', '2026-09-30'));
		$this->assertCount(30, $resolved->days);
		$this->assertSame([], $resolved->periods);
		$this->assertCount(1, $resolved->excludedPeriods);
	}

	public function testMixedNonOverlappingSources(): void
	{
		$resolved = PeriodResolver::resolve([
			$this->period('2026-08-01', '2026-08-31'),
			$this->day('2026-09-01'),
		], new DateRange('2026-08-01', '2026-09-01'));

		$this->assertSame('mixed', $resolved->source());
		$this->assertTrue($resolved->isComplete());
	}
}
