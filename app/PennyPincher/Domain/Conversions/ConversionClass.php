<?php

namespace App\PennyPincher\Domain\Conversions;

/**
 * Biznesowa klasyfikacja działania powodującego konwersję – niezależna od ustawień w Google Ads
 * (których aplikacja nie zmienia). Pozwala oddzielić leady od kliknięć w kontakt i mikrokonwersji.
 */
enum ConversionClass: string
{
	case Lead = 'lead';
	case PotentialLead = 'potential_lead';
	case ConfirmedLead = 'confirmed_lead';
	case Sale = 'sale';
	case Micro = 'micro';
	case Other = 'other';
	case Unclassified = 'unclassified';

	public function label(): string
	{
		return match ($this) {
			self::Lead => 'Lead',
			self::PotentialLead => 'Potencjalny kontakt',
			self::ConfirmedLead => 'Potwierdzony lead (np. import z CRM)',
			self::Sale => 'Sprzedaż',
			self::Micro => 'Mikrokonwersja',
			self::Other => 'Inna',
			self::Unclassified => 'Nieokreślona',
		};
	}

	public function hint(): string
	{
		return match ($this) {
			self::Lead => 'Zgłoszenie z danymi kontaktowymi, np. wysłany formularz.',
			self::PotentialLead => 'Kliknięcie e-maila lub numeru telefonu, połączenie – może, ale nie musi być zapytaniem.',
			self::ConfirmedLead => 'Lead potwierdzony poza Google Ads (zaimportowany, zakwalifikowany).',
			self::Sale => 'Zakup lub opłacone zamówienie.',
			self::Micro => 'Zachowanie na stronie (czas, przewinięcie, wyświetlenie podstrony).',
			self::Other => 'Inne działanie.',
			self::Unclassified => 'Jeszcze nie sklasyfikowane.',
		};
	}

	/** Klasy liczone jako leady (bez potencjalnych kontaktów). */
	public function isLead(): bool
	{
		return in_array($this, [self::Lead, self::ConfirmedLead], true);
	}

	/**
	 * Klasa proponowana na podstawie kategorii z Google Ads – tylko propozycja, oznaczona w UI jako automatyczna.
	 */
	public static function suggestFromCategory(?string $category): self
	{
		return match ($category) {
			'SUBMIT_LEAD_FORM', 'REQUEST_QUOTE', 'BOOK_APPOINTMENT', 'IMPORTED_LEAD' => self::Lead,
			'QUALIFIED_LEAD', 'CONVERTED_LEAD' => self::ConfirmedLead,
			'PHONE_CALL_LEAD', 'CONTACT' => self::PotentialLead,
			'PURCHASE', 'STORE_SALE', 'SUBSCRIBE_PAID' => self::Sale,
			'PAGE_VIEW', 'ENGAGEMENT', 'OUTBOUND_CLICK', 'GET_DIRECTIONS', 'DOWNLOAD', 'ADD_TO_CART', 'BEGIN_CHECKOUT', 'STORE_VISIT' => self::Micro,
			'SIGNUP', 'DEFAULT' => self::Other,
			default => self::Unclassified,
		};
	}

	/**
	 * @return array<string, string>
	 */
	public static function options(): array
	{
		$options = [];

		foreach (self::cases() as $case) {
			$options[$case->value] = $case->label();
		}

		return $options;
	}
}
