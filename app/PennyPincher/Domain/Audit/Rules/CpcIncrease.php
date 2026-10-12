<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * PF001: wzrost średniego CPC o co najmniej 30% przy ≥ 100 kliknięciach w obu okresach.
 */
final class CpcIncrease extends PerformanceTrendRule
{
	protected const ID = 'PF001';

	protected const NAME = 'Wzrost kosztu kliknięcia (CPC)';

	protected const DESCRIPTION = 'Średni koszt kliknięcia wyraźnie wzrósł względem poprzedniego okresu tej samej długości. Porównujemy tylko kampanie z co najmniej 100 kliknięciami w obu okresach.';

	protected function sample(): array
	{
		return ['clicks', 100];
	}

	protected function value(array $metrics): ?float
	{
		return ($metrics['cost_micros'] ?? null) === null || empty($metrics['clicks']) ? null : $metrics['cost_micros'] / $metrics['clicks'];
	}

	protected function threshold(): float
	{
		return 30.0;
	}

	protected function format(float $value, string $currency): string
	{
		return self::money((int) round($value), $currency);
	}

	protected function title(string $campaign, float $change): string
	{
		return sprintf('CPC w kampanii „%s” wzrósł o %s', $campaign, self::percent($change));
	}

	protected function recommendation(): string
	{
		return 'Sprawdź w Google Ads udział w wyświetleniach i konkurencję w aukcjach (Statystyki aukcji), zmiany strategii ustalania stawek oraz nowe słowa kluczowe. Wzrost CPC nie jest błędem, jeśli rośnie też wartość konwersji.';
	}
}
