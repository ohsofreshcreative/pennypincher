<?php

namespace App\PennyPincher\Infrastructure\GoogleAds\Sync;

/**
 * Zapytania GAQL (Google Ads API v25). Tylko odczyt.
 * Daty w zapytaniach pochodzą z planu synchronizacji (format Y-m-d), nie od użytkownika.
 *
 * Pola są sprawdzane testem `GaqlSchemaTest` względem listy pól v25 (tests/Support/GaqlSchemaV25.php).
 * Uwaga: od v23 `campaign.start_date`/`campaign.end_date` nie istnieją – zastąpiły je
 * `campaign.start_date_time`/`campaign.end_date_time` (format „yyyy-MM-dd HH:mm:ss”).
 */
final class Gaql
{
	public static function campaigns(): string
	{
		return 'SELECT campaign.id, campaign.name, campaign.status, campaign.serving_status, campaign.advertising_channel_type,
			campaign.advertising_channel_sub_type, campaign.bidding_strategy_type, campaign.start_date_time, campaign.end_date_time,
			campaign.network_settings.target_google_search, campaign.network_settings.target_search_network,
			campaign.network_settings.target_content_network, campaign.network_settings.target_partner_search_network,
			campaign.geo_target_type_setting.positive_geo_target_type, campaign.geo_target_type_setting.negative_geo_target_type,
			campaign.target_cpa.target_cpa_micros, campaign.maximize_conversions.target_cpa_micros,
			campaign.target_roas.target_roas, campaign.maximize_conversion_value.target_roas,
			campaign_budget.amount_micros, campaign_budget.period, campaign_budget.explicitly_shared
			FROM campaign';
	}

	public static function locations(): string
	{
		return "SELECT campaign.id, campaign_criterion.criterion_id, campaign_criterion.type, campaign_criterion.negative,
			campaign_criterion.location.geo_target_constant, campaign_criterion.proximity.radius, campaign_criterion.proximity.radius_units
			FROM campaign_criterion
			WHERE campaign_criterion.type IN ('LOCATION', 'PROXIMITY') AND campaign.status != 'REMOVED'";
	}

	/**
	 * @param array<int, string> $resourceNames geoTargetConstants/…
	 */
	public static function geoTargets(array $resourceNames): string
	{
		$quoted = implode(', ', array_map(fn ($name) => "'" . preg_replace('/[^a-zA-Z0-9\/]/', '', $name) . "'", $resourceNames));

		return "SELECT geo_target_constant.resource_name, geo_target_constant.name, geo_target_constant.canonical_name, geo_target_constant.target_type
			FROM geo_target_constant WHERE geo_target_constant.resource_name IN ({$quoted})";
	}

	public static function adGroups(): string
	{
		return 'SELECT ad_group.id, ad_group.name, ad_group.status, campaign.id FROM ad_group';
	}

	public static function keywords(): string
	{
		return "SELECT ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type,
			ad_group_criterion.status, ad_group_criterion.quality_info.quality_score, ad_group_criterion.effective_cpc_bid_micros,
			ad_group.id, campaign.id
			FROM ad_group_criterion
			WHERE ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.negative = FALSE";
	}

	public static function conversionActions(): string
	{
		return 'SELECT conversion_action.id, conversion_action.name, conversion_action.category, conversion_action.status,
			conversion_action.type, conversion_action.origin, conversion_action.primary_for_goal, conversion_action.counting_type,
			conversion_action.include_in_conversions_metric, conversion_action.click_through_lookback_window_days,
			conversion_action.value_settings.default_value, conversion_action.value_settings.always_use_default_value
			FROM conversion_action';
	}

	private const METRICS = 'metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value, metrics.all_conversions';

	public static function campaignMetrics(string $from, string $to): string
	{
		return 'SELECT campaign.id, segments.date, ' . self::METRICS . ", metrics.view_through_conversions FROM campaign WHERE segments.date BETWEEN '{$from}' AND '{$to}'";
	}

	public static function keywordMetrics(string $from, string $to): string
	{
		return 'SELECT ad_group_criterion.criterion_id, ad_group.id, campaign.id, segments.date, ' . self::METRICS
			. " FROM keyword_view WHERE segments.date BETWEEN '{$from}' AND '{$to}'";
	}

	public static function searchTermMetrics(string $from, string $to): string
	{
		return 'SELECT search_term_view.search_term, search_term_view.status, segments.search_term_match_type, segments.keyword.info.text,
			campaign.id, ad_group.id, segments.date, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions,
			metrics.conversions_value, metrics.all_conversions'
			. " FROM search_term_view WHERE segments.date BETWEEN '{$from}' AND '{$to}'";
	}

	public static function conversionMetrics(string $from, string $to): string
	{
		return 'SELECT segments.conversion_action, segments.date, metrics.conversions, metrics.all_conversions,
			metrics.conversions_value, metrics.all_conversions_value'
			. " FROM customer WHERE segments.date BETWEEN '{$from}' AND '{$to}'";
	}
}
