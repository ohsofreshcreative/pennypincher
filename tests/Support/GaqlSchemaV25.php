<?php

namespace Tests\Support;

/**
 * Pola GAQL używane przez Penny Pincher, sprawdzone ze schematem Google Ads API v25 (12.10.2026):
 * pliki .proto v25 (googleapis/googleapis, google/ads/googleads/v25) i narzędzie różnic v23/v24/v25.
 *
 * Każde nowe pole w zapytaniu musi zostać najpierw sprawdzone w dokumentacji i dopisane tutaj –
 * test `GaqlSchemaTest` odrzuca pola spoza tej listy oraz pola usunięte z API.
 */
final class GaqlSchemaV25
{
	/** Pola usunięte z API (nigdy nie mogą wrócić do zapytań). */
	public const REMOVED = [
		'campaign.start_date' => 'v23: zastąpione przez campaign.start_date_time',
		'campaign.end_date' => 'v23: zastąpione przez campaign.end_date_time',
		'campaign.video_brand_safety_suitability' => 'v24: usunięte',
	];

	/** Dozwolone pola według zasobu FROM (z polami zasobów powiązanych, segmentami i metrykami). */
	public const FIELDS = [
		'campaign' => [
			'campaign.id', 'campaign.name', 'campaign.status', 'campaign.serving_status', 'campaign.advertising_channel_type',
			'campaign.advertising_channel_sub_type', 'campaign.bidding_strategy_type', 'campaign.start_date_time', 'campaign.end_date_time',
			'campaign.network_settings.target_google_search', 'campaign.network_settings.target_search_network',
			'campaign.network_settings.target_content_network', 'campaign.network_settings.target_partner_search_network',
			'campaign.geo_target_type_setting.positive_geo_target_type', 'campaign.geo_target_type_setting.negative_geo_target_type',
			'campaign.target_cpa.target_cpa_micros', 'campaign.maximize_conversions.target_cpa_micros',
			'campaign.target_roas.target_roas', 'campaign.maximize_conversion_value.target_roas',
			'campaign_budget.amount_micros', 'campaign_budget.period', 'campaign_budget.explicitly_shared',
			'segments.date', 'metrics.impressions', 'metrics.clicks', 'metrics.cost_micros', 'metrics.conversions',
			'metrics.conversions_value', 'metrics.all_conversions', 'metrics.view_through_conversions',
		],
		'campaign_criterion' => [
			'campaign.id', 'campaign.status', 'campaign_criterion.criterion_id', 'campaign_criterion.type', 'campaign_criterion.negative',
			'campaign_criterion.location.geo_target_constant', 'campaign_criterion.proximity.radius', 'campaign_criterion.proximity.radius_units',
		],
		'geo_target_constant' => [
			'geo_target_constant.resource_name', 'geo_target_constant.name', 'geo_target_constant.canonical_name', 'geo_target_constant.target_type',
		],
		'ad_group' => ['ad_group.id', 'ad_group.name', 'ad_group.status', 'campaign.id'],
		'ad_group_criterion' => [
			'ad_group_criterion.criterion_id', 'ad_group_criterion.keyword.text', 'ad_group_criterion.keyword.match_type',
			'ad_group_criterion.status', 'ad_group_criterion.quality_info.quality_score', 'ad_group_criterion.effective_cpc_bid_micros',
			'ad_group_criterion.type', 'ad_group_criterion.negative', 'ad_group.id', 'campaign.id',
		],
		'keyword_view' => [
			'ad_group_criterion.criterion_id', 'ad_group.id', 'campaign.id', 'segments.date',
			'metrics.impressions', 'metrics.clicks', 'metrics.cost_micros', 'metrics.conversions', 'metrics.conversions_value', 'metrics.all_conversions',
		],
		'search_term_view' => [
			'search_term_view.search_term', 'search_term_view.status', 'segments.search_term_match_type', 'segments.keyword.info.text',
			'campaign.id', 'ad_group.id', 'segments.date',
			'metrics.impressions', 'metrics.clicks', 'metrics.cost_micros', 'metrics.conversions', 'metrics.conversions_value', 'metrics.all_conversions',
		],
		'conversion_action' => [
			'conversion_action.id', 'conversion_action.name', 'conversion_action.category', 'conversion_action.status',
			'conversion_action.type', 'conversion_action.origin', 'conversion_action.primary_for_goal', 'conversion_action.counting_type',
			'conversion_action.include_in_conversions_metric', 'conversion_action.click_through_lookback_window_days',
			'conversion_action.value_settings.default_value', 'conversion_action.value_settings.always_use_default_value',
		],
		'customer' => [
			'customer.id', 'customer.descriptive_name', 'customer.currency_code', 'customer.time_zone', 'customer.manager', 'customer.test_account',
			// Segment conversion_action: dozwolone tylko metryki konwersji.
			'segments.conversion_action', 'segments.date',
			'metrics.conversions', 'metrics.all_conversions', 'metrics.conversions_value', 'metrics.all_conversions_value',
		],
		'customer_client' => [
			'customer_client.id', 'customer_client.descriptive_name', 'customer_client.currency_code', 'customer_client.time_zone',
			'customer_client.manager', 'customer_client.test_account', 'customer_client.status', 'customer_client.level',
		],
	];

	/**
	 * Zasób FROM i wszystkie pola użyte w SELECT i WHERE.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	public static function parse(string $query): array
	{
		$query = preg_replace('/\s+/', ' ', trim($query));

		if (! preg_match('/^SELECT (.+?) FROM (\w+)(?: WHERE (.+?))?(?: ORDER BY .+)?(?: LIMIT \d+)?$/i', $query, $m)) {
			throw new \InvalidArgumentException('Nie rozpoznano zapytania GAQL: ' . $query);
		}

		$fields = array_map('trim', explode(',', $m[1]));
		$where = preg_replace("/'[^']*'/", "''", $m[3] ?? '');
		preg_match_all('/\b([a-z_]+(?:\.[a-z_]+)+)\b/', $where, $conditions);

		return [$m[2], array_values(array_unique([...$fields, ...$conditions[1]]))];
	}
}
