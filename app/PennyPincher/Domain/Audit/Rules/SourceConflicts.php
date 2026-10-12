<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * DQ006: nakładające się źródła danych (API vs import, raporty okresowe vs dzienne).
 * Konflikty są rozwiązywane automatycznie – informujemy, które dane pominęliśmy.
 */
final class SourceConflicts extends BaseRule
{
	protected const ID = 'DQ006';

	protected const NAME = 'Nakładające się źródła danych';

	protected const DESCRIPTION = 'Część danych z importów pokrywa się z danymi z API lub z innymi raportami. Aby nie liczyć wydatków dwa razy, pominęliśmy dane o niższym priorytecie.';

	protected const CATEGORY = Category::DATA_QUALITY;

	protected const DIMENSION = Dimension::Data;

	public function evaluate(AuditContext $context): array
	{
		$coverage = $context->coverage;
		$excludedPeriods = count($coverage->excludedPeriods);

		if ($coverage->overriddenByApi === 0 && $excludedPeriods === 0 && $coverage->excludedDays === 0) {
			return [];
		}

		return [$this->finding(
			Severity::Info,
			'Pominięto nakładające się dane z importów',
			$this->description() . ' Kolejność: dane dzienne z API → dzienne z importu → raporty okresowe.',
			[
				['Okresy z importu zastąpione danymi z API', (string) $coverage->overriddenByApi],
				['Pominięte raporty okresowe', (string) $excludedPeriods],
				['Pominięte dni danych dziennych', (string) $coverage->excludedDays],
			],
			'Nie musisz nic robić. Jeśli wyniki wyglądają podejrzanie, porównaj źródła na stronie kampanii.',
			variant: 'conflicts',
			penalty: 0.0,
		)];
	}
}
