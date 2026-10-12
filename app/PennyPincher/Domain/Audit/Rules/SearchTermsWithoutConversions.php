<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * PF005: wyszukiwane hasła z ≥ 10 kliknięciami i bez konwersji, które łącznie pochłaniają ≥ 10% wydatków na hasła.
 */
final class SearchTermsWithoutConversions extends BaseRule
{
	protected const ID = 'PF005';

	protected const NAME = 'Wyszukiwane hasła bez konwersji';

	protected const DESCRIPTION = 'Część wydatków trafia na wyszukiwane hasła, które mają co najmniej 10 kliknięć i żadnej konwersji.';

	protected const CATEGORY = Category::PERFORMANCE;

	protected const DIMENSION = Dimension::Risk;

	public function evaluate(AuditContext $context): array
	{
		$terms = $context->searchTermsWithoutConversions;
		$total = $context->searchTermsTotalCostMicros;

		if ($terms === [] || ! $total) {
			return [];
		}

		$wasted = array_sum(array_column($terms, 'cost_micros'));
		$share = $wasted / $total * 100;

		if ($share < 10) {
			return [];
		}

		usort($terms, fn ($a, $b) => $b['cost_micros'] <=> $a['cost_micros']);
		$evidence = [
			['Hasła bez konwersji (≥ 10 kliknięć)', (string) count($terms)],
			['Ich koszt', self::money($wasted, $context->currency) . ' (' . self::percent($share) . ' wydatków na hasła)'],
		];

		foreach (array_slice($terms, 0, 5) as $term) {
			$evidence[] = ['„' . $term['term'] . '”', sprintf('%s · %s kliknięć · %s', self::money((int) $term['cost_micros'], $context->currency), Format::number($term['clicks']), $term['campaign'])];
		}

		return [$this->finding(
			$share >= 25 ? Severity::Warning : Severity::Info,
			sprintf('%s wydatków na wyszukiwane hasła nie przyniosło konwersji', self::percent($share)),
			$this->description(),
			$evidence,
			'Przejrzyj listę haseł i dodaj nietrafne jako wykluczające słowa kluczowe. Hasła trafne, ale bez konwersji, sprawdź pod kątem strony docelowej.',
			variant: 'terms',
		)];
	}
}
