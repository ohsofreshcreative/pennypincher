<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * PF002: spadek CTR o co najmniej 25% przy ≥ 1000 wyświetleniach w obu okresach.
 */
final class CtrDrop extends PerformanceTrendRule
{
	protected const ID = 'PF002';

	protected const NAME = 'Spadek współczynnika klikalności (CTR)';

	protected const DESCRIPTION = 'CTR wyraźnie spadł względem poprzedniego okresu tej samej długości. Porównujemy tylko kampanie z co najmniej 1000 wyświetleniami w obu okresach.';

	protected function sample(): array
	{
		return ['impressions', 1000];
	}

	protected function value(array $metrics): ?float
	{
		return ($metrics['clicks'] ?? null) === null || empty($metrics['impressions']) ? null : $metrics['clicks'] / $metrics['impressions'] * 100;
	}

	protected function threshold(): float
	{
		return -25.0;
	}

	protected function format(float $value, string $currency): string
	{
		return Format::percent($value);
	}

	protected function title(string $campaign, float $change): string
	{
		return sprintf('CTR w kampanii „%s” spadł o %s', $campaign, self::percent(abs($change)));
	}

	protected function recommendation(): string
	{
		return 'Sprawdź, czy kampania nie zaczęła wyświetlać się na mniej trafne zapytania (raport wyszukiwanych haseł), czy nie zmieniły się reklamy, dopasowania słów kluczowych lub sieci.';
	}
}
