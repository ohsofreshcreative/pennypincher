<?php

namespace App\PennyPincher\Domain\Import\Reports;

use App\PennyPincher\Domain\Import\CommonFields;
use App\PennyPincher\Domain\Import\FieldDefinition;
use App\PennyPincher\Domain\Import\FieldKind;
use App\PennyPincher\Domain\Import\ReportDefinition;

final class SearchTermReport extends ReportDefinition
{
	public function type(): string
	{
		return self::SEARCH_TERMS;
	}

	public function label(): string
	{
		return 'Wyszukiwane hasła';
	}

	public function description(): string
	{
		return 'Raport „Wyszukiwane hasła” (Search terms). Koszty z tego raportu nie są sumowane z raportem kampanii.';
	}

	public function primaryField(): string
	{
		return 'search_term';
	}

	public function fields(): array
	{
		return [
			new FieldDefinition('search_term', 'Wyszukiwane hasło', FieldKind::Text, ['Search term', 'Wyszukiwane hasło', 'Search query', 'Zapytanie'], true),
			CommonFields::campaign(),
			CommonFields::campaignId(),
			CommonFields::adGroup(),
			CommonFields::adGroupId(),
			new FieldDefinition('match_type', 'Typ dopasowania', FieldKind::MatchType, ['Match type', 'Typ dopasowania', 'Search term match type', 'Typ dopasowania wyszukiwanego hasła']),
			new FieldDefinition('keyword', 'Słowo kluczowe', FieldKind::Text, ['Keyword', 'Słowo kluczowe', 'Search keyword', 'Słowo kluczowe w sieci wyszukiwania']),
			new FieldDefinition('added_excluded', 'Dodane / wykluczone', FieldKind::Text, ['Added/Excluded', 'Added / Excluded', 'Dodane/wykluczone', 'Dodane / wykluczone']),
			CommonFields::date(),
			...CommonFields::performance(),
			CommonFields::currency(),
		];
	}

	public function titlePatterns(): array
	{
		return ['/(search terms? report|raport wyszukiwanych haseł|wyszukiwane hasła|search terms)/u'];
	}

	protected function foreignHeaders(): array
	{
		return ['Conversion action', 'Działanie powodujące konwersję'];
	}
}
