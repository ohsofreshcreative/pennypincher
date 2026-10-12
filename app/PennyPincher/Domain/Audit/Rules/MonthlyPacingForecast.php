<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * BP001: prognoza wydatków do końca miesiąca względem budżetu miesięcznego projektu.
 * Prognoza jest szacunkiem liniowym (średnia dzienna × dni miesiąca) i tak ją opisujemy.
 */
final class MonthlyPacingForecast extends BaseRule
{
	protected const ID = 'BP001';

	protected const NAME = 'Tempo wydawania budżetu miesięcznego';

	protected const DESCRIPTION = 'Porównuje wydatki od początku miesiąca i ich prognozę na koniec miesiąca z budżetem miesięcznym projektu.';

	protected const CATEGORY = Category::BUDGET_PACING;

	protected const DIMENSION = Dimension::Risk;

	/** Minimalna liczba dni z danymi, od której liczymy prognozę. */
	public const MIN_DAYS = 3;

	public function evaluate(AuditContext $context): array
	{
		$mtd = $context->monthToDate;

		if ($context->monthlyBudgetMicros === null || $context->monthlyBudgetMicros <= 0) {
			return [$this->finding(
				Severity::Info,
				'Projekt nie ma ustawionego budżetu miesięcznego',
				'Bez budżetu miesięcznego nie można ocenić tempa wydatków ani ostrzec przed przekroczeniem budżetu.',
				[['Budżet miesięczny', 'nie ustawiono']],
				'Ustaw budżet miesięczny w ustawieniach projektu.',
				variant: 'no_budget',
				penalty: 0.0,
			)];
		}

		if ($mtd === null || $mtd['spend'] === null || $mtd['elapsed_days'] < 1) {
			return [];
		}

		$budget = $context->monthlyBudgetMicros;
		$spend = (int) $mtd['spend'];
		$evidence = [
			['Miesiąc', (string) $mtd['month']],
			['Budżet miesięczny', self::money($budget, $context->currency)],
			['Wydatki od początku miesiąca', self::money($spend, $context->currency)],
			['Dni z danymi', sprintf('%d z %d zakończonych dni', $mtd['covered_days'], $mtd['elapsed_days'])],
			['Pozostało dni w miesiącu', (string) max(0, $mtd['days_in_month'] - $mtd['elapsed_days'])],
		];

		if ($spend > $budget) {
			return [$this->finding(
				Severity::Critical,
				'Budżet miesięczny został już przekroczony',
				'Wydatki od początku miesiąca są wyższe niż budżet miesięczny projektu.',
				array_merge($evidence, [['Przekroczenie', self::money($spend - $budget, $context->currency)]]),
				'Sprawdź budżety dzienne kampanii w Google Ads i zdecyduj, czy wstrzymać lub ograniczyć wydatki do końca miesiąca.',
				variant: 'over',
			)];
		}

		// Przy niepełnych danych nie prognozujemy – brakujące dni nie są zerami (raportuje to DQ005).
		if (! $mtd['complete'] || $mtd['covered_days'] < self::MIN_DAYS) {
			return [];
		}

		$forecast = (int) round($spend / $mtd['covered_days'] * $mtd['days_in_month']);
		$ratio = $forecast / $budget;
		$evidence[] = ['Prognoza na koniec miesiąca (szacunek)', self::money($forecast, $context->currency) . ' (' . self::percent($ratio * 100) . ' budżetu)'];

		if ($ratio >= 1.1) {
			return [$this->finding(
				$ratio >= 1.3 ? Severity::Critical : Severity::Warning,
				'Prognozowane wydatki przekroczą budżet miesięczny',
				'Przy obecnym średnim dziennym tempie wydatki na koniec miesiąca będą wyższe niż budżet. To szacunek liniowy – nie uwzględnia sezonowości ani zmian w kampaniach.',
				$evidence,
				sprintf('Aby zmieścić się w budżecie, średnie dzienne wydatki do końca miesiąca nie powinny przekraczać %s.', self::money(self::remainingDaily($budget, $spend, $mtd), $context->currency)),
				variant: 'forecast_over',
			)];
		}

		if ($ratio <= 0.7 && $mtd['elapsed_days'] >= 7) {
			return [$this->finding(
				Severity::Info,
				'Budżet miesięczny może nie zostać wykorzystany',
				'Przy obecnym tempie wydatki na koniec miesiąca będą wyraźnie niższe niż budżet. To szacunek liniowy.',
				$evidence,
				'Sprawdź, czy kampanie nie są ograniczone stawkami, kierowaniem lub odrzuconymi reklamami – albo czy niższe wydatki są zamierzone.',
				variant: 'forecast_under',
				penalty: 0.0,
			)];
		}

		return [];
	}

	private static function remainingDaily(int $budget, int $spend, array $mtd): int
	{
		$left = max(1, $mtd['days_in_month'] - $mtd['elapsed_days']);

		return (int) max(0, floor(($budget - $spend) / $left));
	}
}
