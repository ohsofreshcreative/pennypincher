<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * DQ002: projekt nie jest połączony z Google Ads API – dane pochodzą wyłącznie z importów.
 */
final class NoApiConnection extends BaseRule
{
	protected const ID = 'DQ002';

	protected const NAME = 'Brak połączenia z Google Ads API';

	protected const DESCRIPTION = 'Bez połączenia z API część ustawień (sieci, lokalizacje, kategorie konwersji) nie jest znana, a dane trzeba importować ręcznie.';

	protected const CATEGORY = Category::DATA_QUALITY;

	protected const DIMENSION = Dimension::Data;

	public function evaluate(AuditContext $context): array
	{
		$status = $context->connection['status'] ?? null;

		if (in_array($status, ['active', 'reauth_required', 'error'], true)) {
			return [];
		}

		return [$this->finding(
			Severity::Info,
			'Projekt nie jest połączony z Google Ads API',
			$this->description() . ' Reguły wymagające tych ustawień są pomijane.',
			[['Status połączenia', $status === 'pending_account' ? 'nie wybrano konta' : 'brak połączenia'], ['Ustawienia konwersji znane', $context->hasConversionSettings() ? 'tak' : 'nie']],
			'Połącz projekt z kontem Google Ads w zakładce „Google Ads”. Połączenie ma dostęp tylko do odczytu – Penny Pincher nie zmienia kampanii.',
			variant: 'no_connection',
			penalty: 2.0,
		)];
	}
}
