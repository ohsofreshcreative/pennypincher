<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * PF003: wzrost CPA o co najmniej 30% przy ≥ 10 konwersjach w obu okresach.
 * Pomijamy, gdy konwersje główne to mikrokonwersje – CPA byłby wtedy mylący (zgłasza to CT001).
 */
final class CpaIncrease extends PerformanceTrendRule
{
	protected const ID = 'PF003';

	protected const NAME = 'Wzrost kosztu konwersji (CPA)';

	protected const DESCRIPTION = 'Koszt pozyskania konwersji wyraźnie wzrósł względem poprzedniego okresu tej samej długości. Porównujemy tylko kampanie z co najmniej 10 konwersjami w obu okresach.';

	protected function skip(AuditContext $context): bool
	{
		return $context->primaryConversionsAreMicro();
	}

	protected function sample(): array
	{
		return ['conversions', 10];
	}

	protected function value(array $metrics): ?float
	{
		return ($metrics['cost_micros'] ?? null) === null || empty($metrics['conversions']) ? null : $metrics['cost_micros'] / $metrics['conversions'];
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
		return sprintf('CPA w kampanii „%s” wzrósł o %s', $campaign, self::percent($change));
	}

	protected function recommendation(): string
	{
		return 'Sprawdź, czy nie zmieniły się ustawienia konwersji (opóźnienie konwersji też zaniża ostatnie dni), strategia stawek, budżet lub strona docelowa. Porównaj wyszukiwane hasła z obu okresów.';
	}
}
