<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * PF004: kampania wydaje budżet, ale nie przynosi żadnych konwersji.
 * Próg: większy z 100 jednostek waluty i 10% budżetu miesięcznego; krytyczne od 25% budżetu.
 */
final class SpendWithoutConversions extends BaseRule
{
	protected const ID = 'PF004';

	protected const NAME = 'Wydatki bez konwersji';

	protected const DESCRIPTION = 'Kampania wydała znaczną kwotę w okresie audytu i nie zarejestrowała żadnej konwersji.';

	protected const CATEGORY = Category::PERFORMANCE;

	protected const DIMENSION = Dimension::Risk;

	public const MIN_SPEND_MICROS = 100_000_000;

	public function evaluate(AuditContext $context): array
	{
		$budget = $context->monthlyBudgetMicros;
		$threshold = max(self::MIN_SPEND_MICROS, $budget ? (int) round($budget * 0.1) : 0);
		$findings = [];

		foreach ($context->campaigns as $campaign) {
			$current = $campaign['current'] ?? [];
			$cost = $current['cost_micros'] ?? null;

			// Brak danych o konwersjach (null) ≠ zero konwersji – wtedy nie zgłaszamy.
			if ($cost === null || $cost < $threshold || ($current['conversions'] ?? null) === null || (float) $current['conversions'] > 0) {
				continue;
			}

			$critical = $budget && $cost >= $budget * 0.25;

			$findings[] = $this->finding(
				$critical ? Severity::Critical : Severity::Warning,
				sprintf('Kampania „%s” wydała %s bez żadnej konwersji', $campaign['name'], self::money((int) $cost, $context->currency)),
				$this->description() . ' Może to oznaczać nieskuteczne kierowanie albo brak pomiaru konwersji dla tej kampanii.',
				array_values(array_filter([
					['Kampania', (string) $campaign['name']],
					['Wydatki w okresie audytu', self::money((int) $cost, $context->currency)],
					['Kliknięcia', Format::number($current['clicks'] ?? null)],
					['Konwersje', '0'],
					$budget ? ['Udział w budżecie miesięcznym', self::percent($cost / $budget * 100)] : null,
				])),
				'Sprawdź, czy konwersje tej kampanii są mierzone (cele konwersji kampanii), przejrzyj wyszukiwane hasła i rozważ ograniczenie budżetu do czasu wyjaśnienia.',
				'campaign',
				(int) $campaign['id'],
			);
		}

		return $findings;
	}
}
