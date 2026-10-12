<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * DQ007: waluta konta Google Ads różni się od waluty projektu.
 */
final class CurrencyMismatch extends BaseRule
{
	protected const ID = 'DQ007';

	protected const NAME = 'Niezgodna waluta';

	protected const DESCRIPTION = 'Waluta konta Google Ads jest inna niż waluta ustawiona w projekcie, więc budżet i kwoty mogą być porównywane niepoprawnie.';

	protected const CATEGORY = Category::DATA_QUALITY;

	protected const DIMENSION = Dimension::Data;

	public function evaluate(AuditContext $context): array
	{
		if ($context->projectCurrencyMismatch === null) {
			return [];
		}

		return [$this->finding(
			Severity::Warning,
			'Waluta konta różni się od waluty projektu',
			$this->description(),
			[['Waluta projektu', $context->currency], ['Waluta konta Google Ads', $context->projectCurrencyMismatch]],
			'Zmień walutę projektu w ustawieniach na walutę konta Google Ads i sprawdź budżet miesięczny.',
			variant: 'currency',
		)];
	}
}
