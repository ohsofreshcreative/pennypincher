<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\GoogleAds\Enums;

/**
 * CT001: mikrokonwersja ustawiona jako konwersja główna.
 *
 * Ocena opiera się na ustawieniach z Google Ads (kategoria działania i flaga „główne”), nie na nazwie.
 * Nazwa jest tylko dodatkowym sygnałem dla kategorii „Inne”, wtedy z niższą ważnością.
 */
final class MicroConversionAsPrimary extends BaseRule
{
	private const NAME_HINTS = '/(scroll|przewij|czas na stronie|time on (site|page)|engag|zaangaż|page[_ ]?view|odsłon|wyświetlen|session|sesj|klik w|kliknięci|click|realizacj|video|wideo)/iu';

	public function id(): string
	{
		return 'CT001';
	}

	public function name(): string
	{
		return 'Mikrokonwersja jako konwersja główna';
	}

	public function description(): string
	{
		return 'Działanie typu odsłona, zaangażowanie lub kliknięcie liczone jako konwersja główna zawyża liczbę konwersji i uczy strategie stawek optymalizacji pod mało wartościowe zachowania.';
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

		$total = array_sum(array_map(fn ($a) => AuditContext::isPrimary($a) ? (float) ($a['conversions'] ?? 0) : 0, $context->conversionActions));
		$findings = [];

		foreach ($context->conversionActions as $action) {
			if (! AuditContext::isPrimary($action) || ($action['status'] ?? null) === 'removed') {
				continue;
			}

			$code = (string) ($action['category_code'] ?? '');
			$micro = in_array($code, Enums::MICRO_CATEGORIES, true);
			$nameHint = $code === 'DEFAULT' && preg_match(self::NAME_HINTS, (string) $action['name']);

			if (! $micro && ! $nameHint) {
				continue;
			}

			$conversions = (float) ($action['conversions'] ?? 0);
			$share = $total > 0 ? $conversions / $total * 100 : null;
			$severity = match (true) {
				$micro && $share !== null && $share >= 30 => Severity::Critical,
				$micro => Severity::Warning,
				default => Severity::Info,
			};

			$findings[] = $this->finding(
				$severity,
				sprintf('„%s” jest konwersją główną, choć mierzy %s', $action['name'], $micro ? 'mikrokonwersję' : 'prawdopodobnie zachowanie na stronie'),
				$micro
					? 'Kategoria działania wskazuje na zachowanie na stronie, a nie kontakt lub sprzedaż. Liczenie go w kolumnie „Konwersje” zawyża wyniki i może kierować budżet do niewłaściwych użytkowników.'
					: 'Działanie ma kategorię „Inne”, a jego nazwa sugeruje mikrokonwersję. Sprawdź, co faktycznie mierzy.',
				[
					['Kategoria', Enums::categoryLabel($code) ?? '—'],
					['Typ działania', (string) ($action['type'] ?? '—')],
					['Uwzględniane w „Konwersjach”', AuditContext::isPrimary($action) ? 'Tak (główne)' : 'Nie'],
					['Konwersje w okresie', number_format($conversions, 1, ',', ' ')],
					['Udział w konwersjach głównych', self::percent($share)],
				],
				'Ustaw to działanie jako dodatkowe (secondary) i pozostaw jako główne tylko konwersje oznaczające kontakt lub sprzedaż (formularz, telefon, e-mail).',
				'conversion_action',
				(int) $action['id'],
			);
		}

		return $findings;
	}
}
