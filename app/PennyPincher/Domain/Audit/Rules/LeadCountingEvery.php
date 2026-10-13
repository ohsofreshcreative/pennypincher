<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\Conversions\ConversionFieldPolicy;
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
				$policy = ConversionFieldPolicy::field(['type' => $action['type'] ?? null, 'status' => strtoupper((string) ($action['status'] ?? ''))], 'counting_type');
				// Bez typu (dane z importu raportu) nie przesądzamy o API – zostaje ogólne zalecenie.
				$apiEditable = empty($action['type']) || in_array($policy['status'], [ConversionFieldPolicy::EDITABLE, ConversionFieldPolicy::CONFIRM], true);

				$findings[] = $this->finding(
					Severity::Warning,
					sprintf('„%s” liczy każdą konwersję', $action['name']),
					'Działanie typu lead ma ustawienie „Każda”. Jedna osoba wysyłająca kilka zapytań zwiększa liczbę konwersji i zaniża koszt pozyskania klienta.',
					[
						['Kategoria', Enums::categoryLabel($action['category_code']) ?? '—'],
						['Typ działania', (string) ($action['type'] ?? '—')],
						['Zliczanie', 'Każda (MANY_PER_CLICK)'],
						['Zmiana przez Google Ads API', empty($action['type']) ? 'nieznana (brak typu z API)' : ($apiEditable ? 'możliwa (Change Center)' : 'niedostępna – działanie ręczne')],
					],
					$apiEditable
						? 'Zmień zliczanie na „Jedna” w ustawieniach działania konwersji, chyba że każde zgłoszenie to osobna szansa sprzedaży.'
						: 'Działanie ręczne: ' . $policy['reason'] . ' Sprawdź w Google Ads (Cele → Konwersje), czy zliczanie da się zmienić; jeśli nie – rozważ ustawienie tego działania jako dodatkowego i mierzenie połączeń własnym działaniem (np. połączenia z reklam z minimalnym czasem trwania).',
					'conversion_action',
					(int) $action['id'],
				);
			}
		}

		return $findings;
	}
}
