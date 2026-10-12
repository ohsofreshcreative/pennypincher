<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\GoogleAds\Enums;

/**
 * CT003: leady liczone „każda” zamiast „jedna”.
 */
final class LeadCountingEvery extends BaseRule
{
	public function id(): string
	{
		return 'CT003';
	}

	public function name(): string
	{
		return 'Leady liczone jako „każda konwersja”';
	}

	public function description(): string
	{
		return 'Dla działań typu lead zwykle należy liczyć jedną konwersję na kliknięcie – inaczej kilka formularzy jednej osoby wygląda jak kilku klientów.';
	}

	public function category(): string
	{
		return Category::CONVERSION_TRACKING;
	}

	protected function dimension(): Dimension
	{
		return Dimension::Measurement;
	}

	public function evaluate(AuditContext $context): array
	{
		$findings = [];

		foreach ($context->conversionActions as $action) {
			if (
				AuditContext::isPrimary($action)
				&& ($action['status'] ?? null) === 'enabled'
				&& ($action['counting_type'] ?? null) === 'MANY_PER_CLICK'
				&& in_array($action['category_code'] ?? '', Enums::LEAD_CATEGORIES, true)
			) {
				$findings[] = $this->finding(
					Severity::Warning,
					sprintf('„%s” liczy każdą konwersję', $action['name']),
					'Działanie typu lead ma ustawienie „Każda”. Jedna osoba wysyłająca kilka zapytań zwiększa liczbę konwersji i zaniża koszt pozyskania klienta.',
					[
						['Kategoria', Enums::categoryLabel($action['category_code']) ?? '—'],
						['Zliczanie', 'Każda (MANY_PER_CLICK)'],
					],
					'Zmień zliczanie na „Jedna” w ustawieniach działania konwersji, chyba że każde zgłoszenie to osobna szansa sprzedaży.',
					'conversion_action',
					(int) $action['id'],
				);
			}
		}

		return $findings;
	}
}
