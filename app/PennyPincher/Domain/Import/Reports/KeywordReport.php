<?php

namespace App\PennyPincher\Domain\Import\Reports;

use App\PennyPincher\Domain\Import\CommonFields;
use App\PennyPincher\Domain\Import\FieldDefinition;
use App\PennyPincher\Domain\Import\FieldKind;
use App\PennyPincher\Domain\Import\ReportDefinition;

final class KeywordReport extends ReportDefinition
{
	public function type(): string
	{
		return self::KEYWORDS;
	}

	public function label(): string
	{
		return 'Słowa kluczowe';
	}

	public function description(): string
	{
		return 'Raport „Słowa kluczowe w sieci wyszukiwania” (Search keywords).';
	}

	public function primaryField(): string
	{
		return 'keyword';
	}

	public function fields(): array
	{
		return [
			new FieldDefinition('keyword', 'Słowo kluczowe', FieldKind::Text, CommonFields::keywordAliases(), true),
			new FieldDefinition('keyword_id', 'Identyfikator słowa kluczowego', FieldKind::Id, ['Keyword ID', 'Identyfikator słowa kluczowego', 'ID słowa kluczowego']),
			CommonFields::campaign(),
			CommonFields::campaignId(),
			CommonFields::adGroup(),
			CommonFields::adGroupId(),
			new FieldDefinition('match_type', 'Typ dopasowania', FieldKind::MatchType, ['Match type', 'Typ dopasowania', 'Keyword match type', 'Search keyword match type', 'Typ dopasowania słowa kluczowego']),
			new FieldDefinition('status', 'Stan', FieldKind::Status, ['Keyword state', 'Stan słowa kluczowego', 'Status', 'Stan', 'Keyword status']),
			new FieldDefinition('quality_score', 'Wynik jakości', FieldKind::Integer, ['Quality Score', 'Wynik jakości', 'Qual. Score']),
			new FieldDefinition('max_cpc', 'Maks. CPC', FieldKind::Money, ['Max. CPC', 'Maks. CPC', 'Max CPC', 'Default max. CPC', 'Domyślny maks. CPC']),
			CommonFields::date(),
			...CommonFields::performance(),
			CommonFields::currency(),
		];
	}

	public function titlePatterns(): array
	{
		return ['/(keyword report|search keyword|raport słów kluczowych|słowa kluczowe)/u'];
	}

	protected function foreignHeaders(): array
	{
		return [...CommonFields::searchTermAliases(), ...CommonFields::conversionActionAliases()];
	}
}
