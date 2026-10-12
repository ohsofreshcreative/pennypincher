<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Metrics\BudgetCalculator;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\MetricCalculator;
use App\PennyPincher\Domain\Metrics\Money;
use Tests\TestCase;

class MoneyAndMetricsTest extends TestCase
{
	public function testMicrosConversionIsExact(): void
	{
		$this->assertSame(1_498_380_000, Money::toMicros('1498.38'));
		$this->assertSame(100_000, Money::toMicros('0.1'));
		$this->assertSame(-10_000, Money::toMicros('-0.01'));
		$this->assertSame(1, Money::toMicros('0.0000005'));
		$this->assertSame(0, Money::toMicros('0.0000004'));

		// Klasyczny błąd floatów: 0.1 + 0.2 – na mikrojednostkach wynik jest dokładny.
		$this->assertSame(Money::toMicros('0.3'), Money::toMicros('0.1') + Money::toMicros('0.2'));
	}

	public function testDecimalFormatting(): void
	{
		$this->assertSame('1498.38', Money::toDecimal(1_498_380_000));
		$this->assertSame('0.01', Money::toDecimal(5_000));
		$this->assertSame('0.00', Money::toDecimal(4_999));
		$this->assertSame('-2.50', Money::toDecimal(-2_500_000));
		$this->assertSame('3000', Money::toDecimal(3_000_000_000, 0));
	}

	public function testDerivedMetricsAndDivisionByZero(): void
	{
		$this->assertEqualsWithDelta(5.4921, MetricCalculator::ctr(678, 12345), 0.0001);
		$this->assertNull(MetricCalculator::ctr(10, 0));
		$this->assertNull(MetricCalculator::ctr(null, 100));

		$this->assertSame(2_210_000, MetricCalculator::averageCpc(1_498_380_000, 678));
		$this->assertNull(MetricCalculator::averageCpc(1_000_000, 0));
		$this->assertNull(MetricCalculator::averageCpc(null, 10));

		$this->assertSame(119_870_400, MetricCalculator::costPerConversion(1_498_380_000, '12.5'));
		$this->assertNull(MetricCalculator::costPerConversion(1_000_000, '0'));
		$this->assertNull(MetricCalculator::costPerConversion(1_000_000, null));

		$this->assertEqualsWithDelta(50.0, MetricCalculator::share(1_500, 3_000), 0.0001);
		$this->assertNull(MetricCalculator::share(1_500, 0));
	}

	public function testBudgetIsProratedPerMonth(): void
	{
		$monthly = 3000 * Money::MICROS;

		$this->assertSame($monthly, BudgetCalculator::budgetForRange($monthly, new DateRange('2026-09-01', '2026-09-30')));
		$this->assertSame(1000 * Money::MICROS, BudgetCalculator::budgetForRange($monthly, new DateRange('2026-09-01', '2026-09-10')));
		// 16 dni września (16/30) + 15 dni października (15/31)
		$expected = (int) round($monthly * 16 / 30 + $monthly * 15 / 31);
		$this->assertSame($expected, BudgetCalculator::budgetForRange($monthly, new DateRange('2026-09-15', '2026-10-15')));
		$this->assertNull(BudgetCalculator::budgetForRange(null, new DateRange('2026-09-01', '2026-09-30')));
	}

	public function testBudgetForCoverageCountsOnlyDaysWithData(): void
	{
		$monthly = 3000 * Money::MICROS;
		$resolved = \App\PennyPincher\Domain\Metrics\PeriodResolver::resolve([
			['granularity' => 'day', 'date_from' => '2026-09-01', 'date_to' => '2026-09-01'],
			['granularity' => 'day', 'date_from' => '2026-09-02', 'date_to' => '2026-09-02'],
			['granularity' => 'period', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'],
		], new DateRange('2026-01-01', '2026-12-31'));

		$this->assertSame(2 * 100 * Money::MICROS + $monthly, BudgetCalculator::budgetForCoverage($monthly, $resolved));
		$this->assertNull(BudgetCalculator::budgetForCoverage(null, $resolved));
	}
}
