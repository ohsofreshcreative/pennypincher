<?php

namespace App\PennyPincher\Domain\GoogleAds;

/**
 * Wartości słownikowe Google Ads API i ich znaczenie w aplikacji.
 */
final class Enums
{
	public const CONVERSION_CATEGORIES = [
		'DEFAULT' => 'Inne',
		'PAGE_VIEW' => 'Wyświetlenie strony',
		'PURCHASE' => 'Zakup',
		'SIGNUP' => 'Rejestracja',
		'DOWNLOAD' => 'Pobranie',
		'ADD_TO_CART' => 'Dodanie do koszyka',
		'BEGIN_CHECKOUT' => 'Rozpoczęcie płatności',
		'SUBSCRIBE_PAID' => 'Płatna subskrypcja',
		'PHONE_CALL_LEAD' => 'Połączenie telefoniczne',
		'IMPORTED_LEAD' => 'Zaimportowany lead',
		'SUBMIT_LEAD_FORM' => 'Przesłanie formularza',
		'BOOK_APPOINTMENT' => 'Umówienie spotkania',
		'REQUEST_QUOTE' => 'Prośba o wycenę',
		'GET_DIRECTIONS' => 'Wskazówki dojazdu',
		'OUTBOUND_CLICK' => 'Kliknięcie linku wychodzącego',
		'CONTACT' => 'Kontakt',
		'ENGAGEMENT' => 'Zaangażowanie',
		'STORE_VISIT' => 'Wizyta w sklepie',
		'STORE_SALE' => 'Sprzedaż w sklepie',
		'QUALIFIED_LEAD' => 'Kwalifikowany lead',
		'CONVERTED_LEAD' => 'Przekonwertowany lead',
	];

	/**
	 * Kategorie oznaczające mikrokonwersję (zachowanie na stronie, a nie kontakt / sprzedaż).
	 */
	public const MICRO_CATEGORIES = ['PAGE_VIEW', 'ENGAGEMENT', 'OUTBOUND_CLICK', 'GET_DIRECTIONS', 'DOWNLOAD', 'ADD_TO_CART', 'BEGIN_CHECKOUT'];

	/**
	 * Kategorie odpowiadające wartościowemu kontaktowi lub sprzedaży.
	 */
	public const VALUABLE_CATEGORIES = [
		'SUBMIT_LEAD_FORM', 'CONTACT', 'PHONE_CALL_LEAD', 'BOOK_APPOINTMENT', 'REQUEST_QUOTE', 'QUALIFIED_LEAD',
		'CONVERTED_LEAD', 'IMPORTED_LEAD', 'PURCHASE', 'SIGNUP', 'SUBSCRIBE_PAID', 'STORE_SALE',
	];

	public const LEAD_CATEGORIES = [
		'SUBMIT_LEAD_FORM', 'CONTACT', 'PHONE_CALL_LEAD', 'BOOK_APPOINTMENT', 'REQUEST_QUOTE', 'QUALIFIED_LEAD', 'CONVERTED_LEAD', 'IMPORTED_LEAD',
	];

	public const SMART_BIDDING = ['TARGET_CPA', 'TARGET_ROAS', 'MAXIMIZE_CONVERSIONS', 'MAXIMIZE_CONVERSION_VALUE'];

	public const CHANNELS = [
		'SEARCH' => 'Sieć wyszukiwania',
		'DISPLAY' => 'Sieć reklamowa',
		'PERFORMANCE_MAX' => 'Performance Max',
		'VIDEO' => 'Wideo',
		'SHOPPING' => 'Produktowa',
		'DEMAND_GEN' => 'Demand Gen',
		'MULTI_CHANNEL' => 'Aplikacje',
		'LOCAL' => 'Lokalna',
		'SMART' => 'Inteligentna',
	];

	public const BIDDING = [
		'MANUAL_CPC' => 'Ręczny CPC',
		'MAXIMIZE_CLICKS' => 'Maksymalizacja liczby kliknięć',
		'TARGET_SPEND' => 'Maksymalizacja liczby kliknięć',
		'MAXIMIZE_CONVERSIONS' => 'Maksymalizacja liczby konwersji',
		'MAXIMIZE_CONVERSION_VALUE' => 'Maksymalizacja wartości konwersji',
		'TARGET_CPA' => 'Docelowy CPA',
		'TARGET_ROAS' => 'Docelowy ROAS',
		'TARGET_IMPRESSION_SHARE' => 'Docelowy udział w wyświetleniach',
		'MANUAL_CPM' => 'Ręczny CPM',
		'MANUAL_CPV' => 'Ręczny CPV',
	];

	public static function status(?string $value): ?string
	{
		return match ($value) {
			'ENABLED' => 'enabled',
			'PAUSED' => 'paused',
			'REMOVED' => 'removed',
			null, '' => null,
			default => 'other',
		};
	}

	public static function searchTermMatchType(?string $value): ?string
	{
		return match ($value) {
			'EXACT' => 'exact',
			'NEAR_EXACT' => 'exact_close_variant',
			'PHRASE' => 'phrase',
			'NEAR_PHRASE' => 'phrase_close_variant',
			'BROAD' => 'broad',
			null, '' => null,
			default => 'other',
		};
	}

	public static function keywordMatchType(?string $value): ?string
	{
		return match ($value) {
			'EXACT' => 'exact',
			'PHRASE' => 'phrase',
			'BROAD' => 'broad',
			null, '' => null,
			default => 'other',
		};
	}

	public static function categoryLabel(?string $code): ?string
	{
		return $code === null ? null : (self::CONVERSION_CATEGORIES[$code] ?? $code);
	}
}
