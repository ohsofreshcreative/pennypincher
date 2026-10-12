<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\GoogleAds\Enums;

/**
 * CT002: brak głównej konwersji oznaczającej kontakt lub sprzedaż.
 */
final class NoValuablePrimaryConversion extends BaseRule
{
	public function id(): string
	{
		return 'CT002';
	}

	public function name(): string
	{
		return 'Brak wartościowej konwersji głównej';
	}

	public function description(): string
	{
		return 'Żadne aktywne działanie oznaczające kontakt, lead lub sprzedaż nie jest ustawione jako konwersja główna.';
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
		if (! $context->hasConversionSettings()) {
			return [];
		}

		foreach ($context->conversionActions as $action) {
			if (AuditContext::isPrimary($action) && ($action['status'] ?? null) === 'enabled' && in_array($action['category_code'] ?? '', Enums::VALUABLE_CATEGORIES, true)) {
				return [];
			}
		}

		$primary = array_values(array_filter($context->conversionActions, fn ($a) => AuditContext::isPrimary($a) && ($a['status'] ?? null) !== 'removed'));

		return [$this->finding(
			Severity::Critical,
			'Brak głównej konwersji oznaczającej kontakt lub sprzedaż',
			'Kampanie są oceniane i optymalizowane na podstawie konwersji głównych. Jeśli żadna z nich nie oznacza zapytania, telefonu ani sprzedaży, liczba konwersji nie mówi nic o pozyskanych klientach.',
			[
				['Aktywne konwersje główne', $primary === [] ? 'brak' : implode(', ', array_map(fn ($a) => $a['name'] . ' (' . (Enums::categoryLabel($a['category_code'] ?? null) ?? '—') . ')', array_slice($primary, 0, 5)))],
				['Wszystkie działania konwersji', (string) count($context->conversionActions)],
			],
			'Skonfiguruj (lub oznacz jako główne) działania dla formularza kontaktowego, kliknięć w telefon i e-mail; dla B2B rozważ import kwalifikowanych leadów z CRM.',
		)];
	}
}
