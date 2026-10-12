<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\AuditScore;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * DQ008: dane za okres są (synchronizacja lub import), ale kampanie nie miały wydatków ani wyświetleń.
 * To nie jest błąd połączenia ani pomiaru – informujemy bez kary i bez sugerowania ponownego łączenia.
 */
final class NoActivity extends BaseRule
{
	protected const ID = 'DQ008';

	protected const NAME = 'Brak aktywności reklamowej w okresie';

	protected const DESCRIPTION = 'W wybranym okresie kampanie nie generowały wydatków ani wyświetleń.';

	protected const CATEGORY = Category::DATA_QUALITY;

	protected const DIMENSION = Dimension::Data;

	public function evaluate(AuditContext $context): array
	{
		if ($context->dataState() !== 'no_activity') {
			return [];
		}

		$activity = $context->activity;

		return [$this->finding(
			Severity::Info,
			'Brak aktywności reklamowej w wybranym okresie',
			AuditScore::NO_ACTIVITY,
			[
				['Okres', Format::period($context->range->fromString(), $context->range->toString())],
				['Pierwszy dzień z danymi (cały projekt)', Format::date($activity['first_day'] ?? null)],
				['Ostatni dzień z danymi (cały projekt)', Format::date($activity['last_day'] ?? null)],
				['Ostatni dzień z aktywnością', Format::date($activity['last_active_day'] ?? null)],
				['Źródło danych', (string) ($activity['source_label'] ?? '—')],
			],
			'Wybierz okres, w którym kampanie były aktywne (np. „Ostatnie 12 miesięcy”), albo sprawdź w Google Ads, czy wyłączenie kampanii było zamierzone.',
			variant: 'no_activity',
			penalty: 0.0,
		)];
	}
}
