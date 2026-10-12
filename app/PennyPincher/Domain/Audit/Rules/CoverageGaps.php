<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * DQ005: dane obejmują tylko część okresu audytu.
 */
final class CoverageGaps extends BaseRule
{
	protected const ID = 'DQ005';

	protected const NAME = 'Niepełne dane w okresie audytu';

	protected const DESCRIPTION = 'Dane kampanii obejmują tylko część okresu audytu. Brakujących dni nie traktujemy jak zera, ale wnioski są mniej pewne.';

	protected const CATEGORY = Category::DATA_QUALITY;

	protected const DIMENSION = Dimension::Data;

	public function evaluate(AuditContext $context): array
	{
		$coverage = $context->coverage;

		if ($coverage->isEmpty() || $coverage->isComplete() || $coverage->totalDays === 0) {
			return [];
		}

		$share = min($coverage->coveredDays, $coverage->totalDays) / $coverage->totalDays;

		return [$this->finding(
			$share < 0.8 ? Severity::Warning : Severity::Info,
			'Dane obejmują tylko część okresu audytu',
			$this->description(),
			[['Okres audytu', Format::period($context->range->fromString(), $context->range->toString())], ['Dni z danymi', sprintf('%d z %d (%s)', min($coverage->coveredDays, $coverage->totalDays), $coverage->totalDays, self::percent($share * 100))]],
			'Uzupełnij brakujące dni: uruchom synchronizację z Google Ads albo zaimportuj raport z kolumną „Dzień” za brakujący okres.',
			variant: 'gaps',
		)];
	}
}
