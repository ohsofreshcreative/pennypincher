<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * BP002: suma budżetów dziennych aktywnych kampanii względem budżetu miesięcznego.
 * Google Ads może w miesiącu wydać średnio do 30,4 × budżet dzienny.
 */
final class DailyBudgetsExceedMonthly extends BaseRule
{
	protected const ID = 'BP002';

	protected const NAME = 'Budżety dzienne kampanii a budżet miesięczny';

	protected const DESCRIPTION = 'Sprawdza, czy suma budżetów dziennych aktywnych kampanii pozwala Google Ads wydać więcej niż budżet miesięczny projektu.';

	protected const CATEGORY = Category::BUDGET_PACING;

	protected const DIMENSION = Dimension::Configuration;

	public const DAYS_PER_MONTH = 30.4;

	public function evaluate(AuditContext $context): array
	{
		if (! $context->monthlyBudgetMicros) {
			return [];
		}

		$daily = 0;
		$counted = [];
		$shared = false;

		foreach ($context->campaigns as $campaign) {
			if (($campaign['status'] ?? null) !== 'enabled' || empty($campaign['budget_micros']) || ! in_array($campaign['budget_period'] ?? 'DAILY', ['DAILY', null, ''], true)) {
				continue;
			}

			$daily += (int) $campaign['budget_micros'];
			$counted[] = (string) $campaign['name'];
			$shared = $shared || ! empty($campaign['settings']['budget_shared']);
		}

		if ($counted === []) {
			return [];
		}

		$maxMonthly = (int) round($daily * self::DAYS_PER_MONTH);
		$ratio = $maxMonthly / $context->monthlyBudgetMicros;

		if ($ratio < 1.25) {
			return [];
		}

		$evidence = [
			['Suma budżetów dziennych', self::money($daily, $context->currency) . ' (' . count($counted) . ' kampanii)'],
			['Maksymalne wydatki miesięczne (× 30,4)', self::money($maxMonthly, $context->currency)],
			['Budżet miesięczny projektu', self::money($context->monthlyBudgetMicros, $context->currency)],
		];

		if ($shared) {
			$evidence[] = ['Uwaga', 'Część kampanii korzysta z budżetu wspólnego – suma może być zawyżona.'];
		}

		return [$this->finding(
			$ratio >= 2 ? Severity::Warning : Severity::Info,
			'Budżety dzienne kampanii pozwalają przekroczyć budżet miesięczny',
			$this->description() . ' Nie oznacza to, że budżet zostanie przekroczony, ale nic w ustawieniach konta przed tym nie chroni.',
			$evidence,
			'Dopasuj budżety dzienne do budżetu miesięcznego albo regularnie kontroluj tempo wydatków (reguła BP001).',
			variant: 'daily_sum',
		)];
	}
}
