<?php

namespace App\PennyPincher\Domain\Import;

/**
 * Pola wspólne dla raportów Google Ads wraz z nazwami kolumn w polskiej i angielskiej wersji interfejsu.
 */
final class CommonFields
{
	public static function campaign(bool $required = true): FieldDefinition
	{
		return new FieldDefinition('campaign', 'Kampania', FieldKind::Text, ['Campaign', 'Kampania', 'Campaign name', 'Nazwa kampanii'], $required);
	}

	public static function campaignId(): FieldDefinition
	{
		return new FieldDefinition('campaign_id', 'Identyfikator kampanii', FieldKind::Id, ['Campaign ID', 'Identyfikator kampanii', 'ID kampanii']);
	}

	public static function adGroup(): FieldDefinition
	{
		return new FieldDefinition('ad_group', 'Grupa reklam', FieldKind::Text, ['Ad group', 'Grupa reklam', 'Ad group name', 'Nazwa grupy reklam']);
	}

	public static function adGroupId(): FieldDefinition
	{
		return new FieldDefinition('ad_group_id', 'Identyfikator grupy reklam', FieldKind::Id, ['Ad group ID', 'Identyfikator grupy reklam', 'ID grupy reklam']);
	}

	public static function date(): FieldDefinition
	{
		return new FieldDefinition('date', 'Dzień', FieldKind::Date, ['Day', 'Dzień', 'Date', 'Data']);
	}

	public static function currency(): FieldDefinition
	{
		return new FieldDefinition('currency', 'Waluta', FieldKind::Currency, ['Currency code', 'Kod waluty', 'Currency', 'Waluta']);
	}

	/**
	 * @return array<int, FieldDefinition>
	 */
	public static function performance(): array
	{
		return [
			new FieldDefinition('impressions', 'Wyświetlenia', FieldKind::Integer, ['Impr.', 'Impressions', 'Wyśw.', 'Wyświetlenia', 'Impr']),
			new FieldDefinition('clicks', 'Kliknięcia', FieldKind::Integer, ['Clicks', 'Kliknięcia']),
			new FieldDefinition('cost', 'Koszt', FieldKind::Money, ['Cost', 'Koszt']),
			self::conversions(),
			self::viewThroughConversions(),
			new FieldDefinition('conversions_value', 'Wartość konwersji', FieldKind::Money, ['Conv. value', 'Wartość konw.', 'Conversion value', 'Wartość konwersji', 'Total conv. value', 'Łączna wartość konw.']),
			self::allConversions(),
		];
	}

	public static function conversions(): FieldDefinition
	{
		return new FieldDefinition('conversions', 'Konwersje', FieldKind::Decimal, ['Conversions', 'Konwersje', 'Conv.', 'Konw.']);
	}

	/**
	 * Konwersje po obejrzeniu (view-through) to osobna metryka – nigdy nie dodajemy jej do „Konwersji”.
	 */
	public static function viewThroughConversions(): FieldDefinition
	{
		return new FieldDefinition('view_through_conversions', 'Konwersje po obejrzeniu', FieldKind::Decimal, [
			'View-through conv.', 'View-through conversions', 'View-through conv', 'Konw. po obejrzeniu', 'Konwersje po obejrzeniu',
		]);
	}

	public static function allConversions(): FieldDefinition
	{
		return new FieldDefinition('all_conversions', 'Wszystkie konwersje', FieldKind::Decimal, ['All conv.', 'Wszystkie konw.', 'All conversions', 'Wszystkie konwersje']);
	}

	/**
	 * Nazwy kolumny „Działanie powodujące konwersję” (liczba pojedyncza i mnoga – Edytor raportów używa mnogiej).
	 *
	 * @return array<int, string>
	 */
	public static function conversionActionAliases(): array
	{
		return [
			'Conversion action', 'Conversion actions', 'Conversion action name',
			'Działanie powodujące konwersję', 'Działania powodujące konwersję', 'Nazwa działania powodującego konwersję',
			'Conversion name', 'Nazwa konwersji',
		];
	}

	/**
	 * @return array<int, string>
	 */
	public static function searchTermAliases(): array
	{
		return ['Search term', 'Search terms', 'Search query', 'Wyszukiwane hasło', 'Wyszukiwane hasła', 'Zapytanie'];
	}

	/**
	 * @return array<int, string>
	 */
	public static function keywordAliases(): array
	{
		return ['Keyword', 'Słowo kluczowe', 'Search keyword', 'Słowo kluczowe w sieci wyszukiwania', 'Keyword text'];
	}
}
