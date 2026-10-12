<?php

namespace App\PennyPincher\Infrastructure\GoogleAds\Changes;

/**
 * Zapytania GAQL odczytujące aktualny stan zasobów przed zmianą, przy wdrożeniu (wykrywanie zmian poza Penny Pincher),
 * przy uzgadnianiu i weryfikacji – oraz normalizacja wierszy REST (camelCase) do wspólnego formatu stanu.
 *
 * Pola sprawdzone z definicjami v25 (googleapis/googleapis, google/ads/googleads/v25) i dopisane do
 * tests/Support/GaqlSchemaV25.php (test `GaqlSchemaTest`). Do zapytań trafiają wyłącznie resource names
 * i kody o bezpiecznym alfabecie – nigdy tekst wpisany przez użytkownika.
 */
final class StateQueries
{
	public static function budget(string $resourceName): string
	{
		return 'SELECT campaign_budget.resource_name, campaign_budget.id, campaign_budget.name, campaign_budget.amount_micros,
			campaign_budget.explicitly_shared, campaign_budget.reference_count, campaign_budget.status, campaign_budget.period
			FROM campaign_budget WHERE campaign_budget.resource_name = ' . self::quote($resourceName);
	}

	public static function campaignsByBudget(string $budgetResourceName): string
	{
		return 'SELECT campaign.resource_name, campaign.id, campaign.name, campaign.status FROM campaign
			WHERE campaign.campaign_budget = ' . self::quote($budgetResourceName) . " AND campaign.status != 'REMOVED'";
	}

	public static function campaign(?string $resourceName = null): string
	{
		return 'SELECT campaign.resource_name, campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
			campaign.bidding_strategy_type, campaign.bidding_strategy, campaign.campaign_budget, campaign.network_settings.target_google_search,
			campaign.network_settings.target_search_network, campaign.network_settings.target_content_network,
			campaign.maximize_conversions.target_cpa_micros, campaign.target_spend.cpc_bid_ceiling_micros
			FROM campaign WHERE ' . ($resourceName !== null ? 'campaign.resource_name = ' . self::quote($resourceName) : "campaign.status != 'REMOVED'");
	}

	public static function campaignCriterion(string $resourceName): string
	{
		return self::criterionFields() . ' WHERE campaign_criterion.resource_name = ' . self::quote($resourceName);
	}

	public static function campaignCriteria(string $campaignResourceName): string
	{
		return self::criterionFields() . ' WHERE campaign_criterion.campaign = ' . self::quote($campaignResourceName)
			. " AND campaign_criterion.type IN ('LOCATION', 'LANGUAGE', 'KEYWORD') AND campaign_criterion.status != 'REMOVED'";
	}

	public static function adGroup(string $resourceName): string
	{
		return self::adGroupFields() . ' WHERE ad_group.resource_name = ' . self::quote($resourceName);
	}

	public static function adGroups(string $campaignResourceName): string
	{
		return self::adGroupFields() . ' WHERE ad_group.campaign = ' . self::quote($campaignResourceName) . " AND ad_group.status != 'REMOVED'";
	}

	public static function adGroupCriterion(string $resourceName): string
	{
		return self::adGroupCriterionFields() . ' WHERE ad_group_criterion.resource_name = ' . self::quote($resourceName);
	}

	public static function adGroupCriteria(string $adGroupResourceName): string
	{
		return self::adGroupCriterionFields() . ' WHERE ad_group_criterion.ad_group = ' . self::quote($adGroupResourceName)
			. " AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.status != 'REMOVED'";
	}

	public static function adGroupAd(string $resourceName): string
	{
		return self::adFields() . ' WHERE ad_group_ad.resource_name = ' . self::quote($resourceName);
	}

	public static function adGroupAds(string $adGroupResourceName): string
	{
		return self::adFields() . ' WHERE ad_group_ad.ad_group = ' . self::quote($adGroupResourceName) . " AND ad_group_ad.status != 'REMOVED'";
	}

	public static function conversionAction(?string $resourceName = null): string
	{
		return 'SELECT conversion_action.resource_name, conversion_action.id, conversion_action.name, conversion_action.type, conversion_action.origin,
			conversion_action.category, conversion_action.status, conversion_action.primary_for_goal, conversion_action.counting_type,
			conversion_action.include_in_conversions_metric, conversion_action.click_through_lookback_window_days,
			conversion_action.view_through_lookback_window_days, conversion_action.value_settings.default_value,
			conversion_action.value_settings.always_use_default_value, conversion_action.attribution_model_settings.attribution_model,
			conversion_action.owner_customer
			FROM conversion_action WHERE ' . ($resourceName !== null ? 'conversion_action.resource_name = ' . self::quote($resourceName) : "conversion_action.status != 'REMOVED'");
	}

	public static function customerGoals(): string
	{
		return 'SELECT customer_conversion_goal.resource_name, customer_conversion_goal.category, customer_conversion_goal.origin, customer_conversion_goal.biddable FROM customer_conversion_goal';
	}

	public static function campaignGoals(): string
	{
		return "SELECT campaign_conversion_goal.resource_name, campaign_conversion_goal.campaign, campaign_conversion_goal.category,
			campaign_conversion_goal.origin, campaign_conversion_goal.biddable, campaign.id, campaign.name, campaign.status
			FROM campaign_conversion_goal WHERE campaign.status != 'REMOVED'";
	}

	public static function customerGoal(string $resourceName): string
	{
		return self::customerGoals() . ' WHERE customer_conversion_goal.resource_name = ' . self::quote($resourceName);
	}

	public static function campaignGoal(string $resourceName): string
	{
		return 'SELECT campaign_conversion_goal.resource_name, campaign_conversion_goal.campaign, campaign_conversion_goal.category,
			campaign_conversion_goal.origin, campaign_conversion_goal.biddable, campaign.id, campaign.name, campaign.status
			FROM campaign_conversion_goal WHERE campaign_conversion_goal.resource_name = ' . self::quote($resourceName);
	}

	public static function languageByCode(string $code): string
	{
		return 'SELECT language_constant.resource_name, language_constant.code, language_constant.name FROM language_constant WHERE language_constant.code = '
			. self::quote(strtolower((string) preg_replace('/[^a-zA-Z_-]/', '', $code)));
	}

	/**
	 * Zapytanie stanu pojedynczego zasobu wg typu.
	 */
	public static function forResource(string $resource, string $resourceName): string
	{
		return match ($resource) {
			'campaign_budget' => self::budget($resourceName),
			'campaign' => self::campaign($resourceName),
			'campaign_criterion' => self::campaignCriterion($resourceName),
			'ad_group' => self::adGroup($resourceName),
			'ad_group_criterion' => self::adGroupCriterion($resourceName),
			'ad_group_ad' => self::adGroupAd($resourceName),
			'conversion_action' => self::conversionAction($resourceName),
			'customer_conversion_goal' => self::customerGoal($resourceName),
			'campaign_conversion_goal' => self::campaignGoal($resourceName),
			default => throw new \InvalidArgumentException('Nieobsługiwany zasób: ' . $resource),
		};
	}

	/**
	 * Wiersz REST → stan znormalizowany (nazwy pól jak w OperationCatalog). Brak pola bool = false (JSON pomija wartości domyślne).
	 */
	public static function normalize(string $resource, array $row): ?array
	{
		$int = fn ($v) => $v === null || $v === '' ? null : (int) $v;

		switch ($resource) {
			case 'campaign_budget':
				$b = $row['campaignBudget'] ?? null;

				return $b === null ? null : [
					'resource_name' => (string) ($b['resourceName'] ?? ''), 'id' => (string) ($b['id'] ?? ''), 'name' => $b['name'] ?? null,
					'amount_micros' => $int($b['amountMicros'] ?? null), 'explicitly_shared' => (bool) ($b['explicitlyShared'] ?? false),
					'reference_count' => (int) ($b['referenceCount'] ?? 0), 'status' => $b['status'] ?? null, 'period' => $b['period'] ?? 'DAILY',
				];

			case 'campaign':
				$c = $row['campaign'] ?? null;

				return $c === null ? null : [
					'resource_name' => (string) ($c['resourceName'] ?? ''), 'id' => (string) ($c['id'] ?? ''), 'name' => $c['name'] ?? null,
					'status' => $c['status'] ?? null, 'channel_type' => $c['advertisingChannelType'] ?? null,
					'bidding_strategy_type' => $c['biddingStrategyType'] ?? null, 'portfolio' => ! empty($c['biddingStrategy']),
					'budget' => $c['campaignBudget'] ?? null,
					'target_google_search' => (bool) ($c['networkSettings']['targetGoogleSearch'] ?? false),
					'target_search_network' => (bool) ($c['networkSettings']['targetSearchNetwork'] ?? false),
					'target_content_network' => (bool) ($c['networkSettings']['targetContentNetwork'] ?? false),
					'target_cpa_micros' => $int($c['maximizeConversions']['targetCpaMicros'] ?? null) ?: null,
					'cpc_bid_ceiling_micros' => $int($c['targetSpend']['cpcBidCeilingMicros'] ?? null) ?: null,
				];

			case 'campaign_criterion':
				$c = $row['campaignCriterion'] ?? null;

				return $c === null ? null : [
					'resource_name' => (string) ($c['resourceName'] ?? ''), 'criterion_id' => (string) ($c['criterionId'] ?? ''), 'type' => $c['type'] ?? null,
					'negative' => (bool) ($c['negative'] ?? false), 'status' => $c['status'] ?? 'ENABLED', 'campaign' => $c['campaign'] ?? null,
					'geo_target_constant' => $c['location']['geoTargetConstant'] ?? null, 'language_constant' => $c['language']['languageConstant'] ?? null,
					'keyword_text' => isset($c['keyword']['text']) ? mb_strtolower((string) $c['keyword']['text']) : null, 'match_type' => $c['keyword']['matchType'] ?? null,
				];

			case 'ad_group':
				$g = $row['adGroup'] ?? null;

				return $g === null ? null : [
					'resource_name' => (string) ($g['resourceName'] ?? ''), 'id' => (string) ($g['id'] ?? ''), 'name' => $g['name'] ?? null,
					'status' => $g['status'] ?? null, 'type' => $g['type'] ?? null, 'cpc_bid_micros' => $int($g['cpcBidMicros'] ?? null), 'campaign' => $g['campaign'] ?? null,
				];

			case 'ad_group_criterion':
				$k = $row['adGroupCriterion'] ?? null;

				return $k === null ? null : [
					'resource_name' => (string) ($k['resourceName'] ?? ''), 'criterion_id' => (string) ($k['criterionId'] ?? ''), 'status' => $k['status'] ?? null,
					'negative' => (bool) ($k['negative'] ?? false), 'type' => $k['type'] ?? null, 'ad_group' => $k['adGroup'] ?? null,
					'keyword_text' => isset($k['keyword']['text']) ? mb_strtolower((string) $k['keyword']['text']) : null, 'match_type' => $k['keyword']['matchType'] ?? null,
					'cpc_bid_micros' => $int($k['cpcBidMicros'] ?? null),
				];

			case 'ad_group_ad':
				$a = $row['adGroupAd'] ?? null;
				$rsa = $a['ad']['responsiveSearchAd'] ?? [];

				return $a === null ? null : [
					'resource_name' => (string) ($a['resourceName'] ?? ''), 'status' => $a['status'] ?? null, 'ad_group' => $a['adGroup'] ?? null,
					'ad_id' => (string) ($a['ad']['id'] ?? ''), 'ad_type' => $a['ad']['type'] ?? null, 'final_urls' => array_values((array) ($a['ad']['finalUrls'] ?? [])),
					'headlines' => array_values(array_map(fn ($h) => (string) ($h['text'] ?? ''), (array) ($rsa['headlines'] ?? []))),
					'descriptions' => array_values(array_map(fn ($d) => (string) ($d['text'] ?? ''), (array) ($rsa['descriptions'] ?? []))),
					'path1' => $rsa['path1'] ?? null, 'path2' => $rsa['path2'] ?? null, 'approval_status' => $a['policySummary']['approvalStatus'] ?? null,
				];

			case 'conversion_action':
				$c = $row['conversionAction'] ?? null;

				return $c === null ? null : [
					'resource_name' => (string) ($c['resourceName'] ?? ''), 'id' => (string) ($c['id'] ?? ''), 'name' => $c['name'] ?? null,
					'type' => $c['type'] ?? null, 'origin' => $c['origin'] ?? null, 'category' => $c['category'] ?? null, 'status' => $c['status'] ?? null,
					'primary_for_goal' => (bool) ($c['primaryForGoal'] ?? false), 'counting_type' => $c['countingType'] ?? null,
					'include_in_conversions_metric' => (bool) ($c['includeInConversionsMetric'] ?? false),
					'click_through_lookback_window_days' => $int($c['clickThroughLookbackWindowDays'] ?? null),
					'view_through_lookback_window_days' => $int($c['viewThroughLookbackWindowDays'] ?? null),
					'default_value' => isset($c['valueSettings']['defaultValue']) ? (float) $c['valueSettings']['defaultValue'] : null,
					'always_use_default_value' => (bool) ($c['valueSettings']['alwaysUseDefaultValue'] ?? false),
					'attribution_model' => $c['attributionModelSettings']['attributionModel'] ?? null,
					'owner_customer' => $c['ownerCustomer'] ?? null,
				];

			case 'customer_conversion_goal':
				$g = $row['customerConversionGoal'] ?? null;

				return $g === null ? null : [
					'resource_name' => (string) ($g['resourceName'] ?? ''), 'category' => $g['category'] ?? null, 'origin' => $g['origin'] ?? null, 'biddable' => (bool) ($g['biddable'] ?? false),
				];

			case 'campaign_conversion_goal':
				$g = $row['campaignConversionGoal'] ?? null;

				return $g === null ? null : [
					'resource_name' => (string) ($g['resourceName'] ?? ''), 'campaign' => $g['campaign'] ?? null, 'category' => $g['category'] ?? null,
					'origin' => $g['origin'] ?? null, 'biddable' => (bool) ($g['biddable'] ?? false), 'campaign_name' => $row['campaign']['name'] ?? null,
					'campaign_status' => $row['campaign']['status'] ?? null,
				];
		}

		return null;
	}

	private static function criterionFields(): string
	{
		return 'SELECT campaign_criterion.resource_name, campaign_criterion.criterion_id, campaign_criterion.type, campaign_criterion.negative,
			campaign_criterion.status, campaign_criterion.campaign, campaign_criterion.location.geo_target_constant,
			campaign_criterion.language.language_constant, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type
			FROM campaign_criterion';
	}

	private static function adGroupFields(): string
	{
		return 'SELECT ad_group.resource_name, ad_group.id, ad_group.name, ad_group.status, ad_group.type, ad_group.cpc_bid_micros, ad_group.campaign FROM ad_group';
	}

	private static function adGroupCriterionFields(): string
	{
		return 'SELECT ad_group_criterion.resource_name, ad_group_criterion.criterion_id, ad_group_criterion.status, ad_group_criterion.negative,
			ad_group_criterion.type, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.cpc_bid_micros,
			ad_group_criterion.ad_group FROM ad_group_criterion';
	}

	private static function adFields(): string
	{
		return 'SELECT ad_group_ad.resource_name, ad_group_ad.status, ad_group_ad.ad_group, ad_group_ad.ad.id, ad_group_ad.ad.type,
			ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions,
			ad_group_ad.ad.responsive_search_ad.path1, ad_group_ad.ad.responsive_search_ad.path2, ad_group_ad.policy_summary.approval_status
			FROM ad_group_ad';
	}

	/**
	 * Resource name w cudzysłowie – tylko bezpieczne znaki (cyfry, litery, „/”, „~”, „-”, „_”).
	 */
	private static function quote(string $value): string
	{
		if (! preg_match('#^[A-Za-z0-9/~_-]+$#', $value)) {
			throw new \InvalidArgumentException('Nieprawidłowy identyfikator zasobu.');
		}

		return "'" . $value . "'";
	}
}
