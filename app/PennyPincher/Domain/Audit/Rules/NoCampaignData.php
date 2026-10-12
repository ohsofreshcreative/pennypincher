<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * DQ001: brak danych o kampaniach w okresie audytu.
 */
final class NoCampaignData extends BaseRule
{
	protected const ID = 'DQ001';

	protected const NAME = 'Brak danych o kampaniach';

	protected const DESCRIPTION = 'W okresie audytu nie ma żadnych danych o wydatkach ani wyświetleniach kampanii.';

	protected const CATEGORY = Category::DATA_QUALITY;

	protected const DIMENSION = Dimension::Data;

	public function evaluate(AuditContext $context): array
	{
		if ($context->hasCampaignData()) {
			return [];
		}

		return [$this->finding(
			Severity::Critical,
			'Brak danych o kampaniach w okresie audytu',
			$this->description() . ' Większość reguł nie może zostać sprawdzona, dlatego Audit Score nie jest wyliczany.',
			[['Okres audytu', Format::period($context->range->fromString(), $context->range->toString())], ['Kampanie w projekcie', (string) count($context->campaigns)]],
			'Połącz konto Google Ads i uruchom synchronizację albo zaimportuj raport kampanii obejmujący ostatnie 30 dni.',
			variant: 'no_data',
			penalty: (float) Dimension::Data->maxPoints(),
		)];
	}
}
