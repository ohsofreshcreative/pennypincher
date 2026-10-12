<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * DQ004: połączenie wymaga ponownej autoryzacji albo ostatnia synchronizacja się nie powiodła.
 */
final class ConnectionProblem extends BaseRule
{
	protected const ID = 'DQ004';

	protected const NAME = 'Problem z połączeniem lub synchronizacją';

	protected const DESCRIPTION = 'Połączenie z Google Ads utraciło autoryzację albo ostatnia synchronizacja zakończyła się błędem.';

	protected const CATEGORY = Category::DATA_QUALITY;

	protected const DIMENSION = Dimension::Data;

	public function evaluate(AuditContext $context): array
	{
		$status = $context->connection['status'] ?? null;

		if ($status === 'reauth_required') {
			return [$this->finding(
				Severity::Critical,
				'Połączenie z Google Ads wymaga ponownej autoryzacji',
				'Google odrzucił zapisaną autoryzację (np. cofnięto dostęp albo zmieniono hasło). Dane nie są synchronizowane.',
				[['Konto', (string) ($context->connection['customer_name'] ?? $context->connection['customer_id'] ?? '—')], ['Ostatnia udana synchronizacja', Format::datetime($context->lastSyncAt)]],
				'Otwórz zakładkę „Google Ads” i połącz konto ponownie.',
				variant: 'reauth',
			)];
		}

		if ($status === 'error' || $context->lastRunStatus === 'failed') {
			return [$this->finding(
				Severity::Warning,
				'Ostatnia synchronizacja zakończyła się błędem',
				$this->description(),
				[['Status', $context->lastRunStatus ?? (string) $status], ['Błąd', $context->lastRunError !== null && $context->lastRunError !== '' ? $context->lastRunError : '—']],
				'Sprawdź szczegóły w historii synchronizacji i użyj „Ponów synchronizację”. Jeśli błąd się powtarza, zobacz docs/GOOGLE_ADS.md (sekcja „Rozwiązywanie problemów”).',
				variant: 'failed',
			)];
		}

		return [];
	}
}
