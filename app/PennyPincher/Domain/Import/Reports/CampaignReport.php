<?php

namespace App\PennyPincher\Domain\Import\Reports;

use App\PennyPincher\Domain\Import\CommonFields;
use App\PennyPincher\Domain\Import\FieldDefinition;
use App\PennyPincher\Domain\Import\FieldKind;
use App\PennyPincher\Domain\Import\ReportDefinition;

final class CampaignReport extends ReportDefinition
{
	public function type(): string
	{
		return self::CAMPAIGNS;
	}

	public function label(): string
	{
		return 'Skuteczność kampanii';
	}

	public function description(): string
	{
		return 'Raport „Kampanie” (Campaigns). Najlepiej z kolumną Dzień – wtedy dashboard pokaże wykresy dzienne.';
	}

	public function primaryField(): string
	{
		return 'campaign';
	}

	public function fields(): array
	{
		return [
			CommonFields::campaign(),
			CommonFields::campaignId(),
			CommonFields::date(),
			new FieldDefinition('status', 'Stan kampanii', FieldKind::Status, ['Campaign state', 'Stan kampanii', 'Campaign status', 'Status kampanii', 'Status', 'Stan']),
			new FieldDefinition('campaign_type', 'Typ kampanii', FieldKind::Text, ['Campaign type', 'Typ kampanii', 'Advertising channel type', 'Channel type']),
			new FieldDefinition('budget', 'Budżet', FieldKind::Money, ['Budget', 'Budżet', 'Campaign budget', 'Budżet kampanii']),
			new FieldDefinition('budget_period', 'Typ budżetu', FieldKind::Text, ['Budget type', 'Typ budżetu', 'Budget period', 'Okres budżetu', 'Budget name']),
			new FieldDefinition('bidding_strategy', 'Strategia ustalania stawek', FieldKind::Text, ['Bid strategy type', 'Typ strategii ustalania stawek', 'Bid strategy', 'Strategia ustalania stawek', 'Bidding strategy type']),
			...CommonFields::performance(),
			CommonFields::currency(),
		];
	}

	public function titlePatterns(): array
	{
		return ['/(campaign report|campaigns report|raport kampanii|^kampanie\b|^campaigns\b)/u'];
	}

	protected function foreignHeaders(): array
	{
		return [
			'Search term', 'Wyszukiwane hasło', 'Search query',
			'Conversion action', 'Działanie powodujące konwersję',
			'Keyword', 'Słowo kluczowe', 'Search keyword', 'Słowo kluczowe w sieci wyszukiwania',
		];
	}
}
