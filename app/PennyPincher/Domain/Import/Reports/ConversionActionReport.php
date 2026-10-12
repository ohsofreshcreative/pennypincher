<?php

namespace App\PennyPincher\Domain\Import\Reports;

use App\PennyPincher\Domain\Import\CommonFields;
use App\PennyPincher\Domain\Import\FieldDefinition;
use App\PennyPincher\Domain\Import\FieldKind;
use App\PennyPincher\Domain\Import\ReportDefinition;

final class ConversionActionReport extends ReportDefinition
{
	public function type(): string
	{
		return self::CONVERSION_ACTIONS;
	}

	public function label(): string
	{
		return 'Działania powodujące konwersję';
	}

	public function description(): string
	{
		return 'Lista „Konwersje” (Conversions → Summary) albo raport z segmentem „Działanie powodujące konwersję”. Importujemy tylko to, co jest w eksporcie.';
	}

	public function primaryField(): string
	{
		return 'conversion_action';
	}

	public function requiresOneOf(): array
	{
		return ['conversions', 'view_through_conversions', 'all_conversions', 'conversions_value', 'all_conversions_value'];
	}

	public function fields(): array
	{
		return [
			new FieldDefinition('conversion_action', 'Działanie powodujące konwersję', FieldKind::Text, CommonFields::conversionActionAliases(), true),
			new FieldDefinition('conversion_action_id', 'Identyfikator działania', FieldKind::Id, ['Conversion action ID', 'Identyfikator działania powodującego konwersję']),
			new FieldDefinition('category', 'Kategoria / cel', FieldKind::Text, ['Conversion category', 'Conversion action category', 'Kategoria konwersji', 'Kategoria działania powodującego konwersję', 'Action optimization', 'Category', 'Kategoria', 'Goal', 'Cel', 'Conversion goal', 'Cel konwersji']),
			new FieldDefinition('source', 'Źródło', FieldKind::Text, ['Conversion source', 'Źródło konwersji', 'Source', 'Źródło']),
			new FieldDefinition('status', 'Stan', FieldKind::Status, ['Status', 'Stan', 'Tracking status', 'Stan śledzenia']),
			new FieldDefinition('include_in_conversions', 'Uwzględniane w „Konwersjach”', FieldKind::Boolean, ['Include in "Conversions"', 'Include in conversions', 'Uwzględnij w „Konwersjach”', 'Uwzględnij w konwersjach', 'Primary action', 'Działanie główne']),
			CommonFields::campaign(false),
			CommonFields::campaignId(),
			CommonFields::date(),
			CommonFields::conversions(),
			CommonFields::viewThroughConversions(),
			CommonFields::allConversions(),
			new FieldDefinition('conversions_value', 'Wartość konwersji', FieldKind::Money, ['Conv. value', 'Wartość konw.', 'Conversion value', 'Wartość konwersji']),
			new FieldDefinition('all_conversions_value', 'Wartość wszystkich konwersji', FieldKind::Money, ['All conv. value', 'Wartość wszystkich konw.', 'All conversions value', 'Wartość wszystkich konwersji']),
			CommonFields::currency(),
		];
	}

	public function titlePatterns(): array
	{
		return ['/(conversion action|działania powodujące konwersję|konwersje|conversions)/u'];
	}

	protected function foreignHeaders(): array
	{
		return CommonFields::searchTermAliases();
	}
}
