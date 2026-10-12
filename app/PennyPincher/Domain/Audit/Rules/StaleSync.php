<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * DQ003: dane z API są nieaktualne (ostatnia udana synchronizacja dawno temu albo nigdy).
 */
final class StaleSync extends BaseRule
{
	protected const ID = 'DQ003';

	protected const NAME = 'Nieaktualna synchronizacja';

	protected const DESCRIPTION = 'Ostatnia udana synchronizacja z Google Ads była dawno, więc wnioski audytu mogą nie odpowiadać stanowi konta.';

	protected const CATEGORY = Category::DATA_QUALITY;

	protected const DIMENSION = Dimension::Data;

	public const WARNING_HOURS = 48;

	public const CRITICAL_HOURS = 168;

	public function evaluate(AuditContext $context): array
	{
		if (($context->connection['status'] ?? null) !== 'active') {
			return [];
		}

		if ($context->lastSyncAt === null) {
			return [$this->finding(
				Severity::Warning,
				'Pierwsza synchronizacja jeszcze się nie zakończyła',
				'Konto jest połączone, ale nie ma jeszcze pełnych danych z API. Audyt opiera się na danych dostępnych w tej chwili.',
				[['Ostatnia synchronizacja', 'brak'], ['Status ostatniego uruchomienia', $context->lastRunStatus ?? '—']],
				'Poczekaj na zakończenie synchronizacji historycznej i uruchom audyt ponownie.',
				variant: 'never',
			)];
		}

		$hours = ($context->now - strtotime($context->lastSyncAt . ' UTC')) / 3600;

		if ($hours < self::WARNING_HOURS) {
			return [];
		}

		return [$this->finding(
			$hours >= self::CRITICAL_HOURS ? Severity::Critical : Severity::Warning,
			'Dane z Google Ads są nieaktualne',
			$this->description(),
			[['Ostatnia udana synchronizacja', Format::datetime($context->lastSyncAt)], ['Godzin temu', (string) (int) floor($hours)]],
			'Sprawdź w zakładce „Google Ads” historię synchronizacji i błędy, a na serwerze – czy działa zadanie cron uruchamiające `wp acorn pp:sync:work`.',
			variant: 'stale',
		)];
	}
}
