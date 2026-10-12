<?php

namespace Tests\Unit;

use App\PennyPincher\Infrastructure\GoogleAds\Changes\StateQueries;
use App\PennyPincher\Infrastructure\GoogleAds\Sync\Gaql;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use Tests\Support\GaqlSchemaV25;
use Tests\TestCase;

require_once dirname(__DIR__) . '/Support/GaqlSchemaV25.php';

/**
 * Regresja: każde zapytanie GAQL używa wyłącznie pól istniejących w Google Ads API v25
 * (błąd produkcyjny: UNRECOGNIZED_FIELD 'campaign.start_date', 'campaign.end_date').
 */
class GaqlSchemaTest extends TestCase
{
	/**
	 * @return array<string, string>
	 */
	public static function queries(): array
	{
		return [
			'campaigns' => Gaql::campaigns(),
			'locations' => Gaql::locations(),
			'geoTargets' => Gaql::geoTargets(['geoTargetConstants/2616', 'geoTargetConstants/1011154']),
			'adGroups' => Gaql::adGroups(),
			'keywords' => Gaql::keywords(),
			'conversionActions' => Gaql::conversionActions(),
			'campaignMetrics' => Gaql::campaignMetrics('2026-09-01', '2026-09-30'),
			'keywordMetrics' => Gaql::keywordMetrics('2026-09-01', '2026-09-30'),
			'searchTermMetrics' => Gaql::searchTermMetrics('2026-09-01', '2026-09-30'),
			'conversionMetrics' => Gaql::conversionMetrics('2026-09-01', '2026-09-30'),
			'campaignConversionMetrics' => Gaql::campaignConversionMetrics('2026-09-01', '2026-09-30'),
			'campaignNegatives' => Gaql::campaignNegativeKeywords(),
			'adGroupNegatives' => Gaql::adGroupNegativeKeywords(),
			'sharedNegatives' => Gaql::sharedNegativeKeywords(),
			'customer' => ConnectionService::CUSTOMER_QUERY,
			'customerClient' => ConnectionService::CLIENTS_QUERY,
			// Change Center – odczyt stanu przed zmianą i weryfikacja po zmianie.
			'state.budget' => StateQueries::budget('customers/1234567890/campaignBudgets/5'),
			'state.campaignsByBudget' => StateQueries::campaignsByBudget('customers/1234567890/campaignBudgets/5'),
			'state.campaign' => StateQueries::campaign('customers/1234567890/campaigns/111'),
			'state.campaigns' => StateQueries::campaign(),
			'state.campaignCriterion' => StateQueries::campaignCriterion('customers/1234567890/campaignCriteria/111~5'),
			'state.campaignCriteria' => StateQueries::campaignCriteria('customers/1234567890/campaigns/111'),
			'state.adGroup' => StateQueries::adGroup('customers/1234567890/adGroups/222'),
			'state.adGroups' => StateQueries::adGroups('customers/1234567890/campaigns/111'),
			'state.adGroupCriterion' => StateQueries::adGroupCriterion('customers/1234567890/adGroupCriteria/222~3'),
			'state.adGroupCriteria' => StateQueries::adGroupCriteria('customers/1234567890/adGroups/222'),
			'state.adGroupAd' => StateQueries::adGroupAd('customers/1234567890/adGroupAds/222~9'),
			'state.adGroupAds' => StateQueries::adGroupAds('customers/1234567890/adGroups/222'),
			'state.conversionAction' => StateQueries::conversionAction('customers/1234567890/conversionActions/901'),
			'state.conversionActions' => StateQueries::conversionAction(),
			'state.customerGoals' => StateQueries::customerGoals(),
			'state.campaignGoals' => StateQueries::campaignGoals(),
			'state.customerGoal' => StateQueries::customerGoal('customers/1234567890/customerConversionGoals/PURCHASE~WEBSITE'),
			'state.campaignGoal' => StateQueries::campaignGoal('customers/1234567890/campaignConversionGoals/111~PURCHASE~WEBSITE'),
			'state.language' => StateQueries::languageByCode('pl'),
		];
	}

	public function testAllQueriesUseOnlyV25Fields(): void
	{
		foreach (self::queries() as $name => $query) {
			[$resource, $fields] = GaqlSchemaV25::parse($query);
			$this->assertTrue(isset(GaqlSchemaV25::FIELDS[$resource]), "{$name}: nieznany zasób {$resource}");

			foreach ($fields as $field) {
				$this->assertFalse(isset(GaqlSchemaV25::REMOVED[$field]), "{$name}: pole {$field} zostało usunięte z API (" . (GaqlSchemaV25::REMOVED[$field] ?? '') . ')');
				$this->assertTrue(in_array($field, GaqlSchemaV25::FIELDS[$resource], true), "{$name}: pole {$field} nie jest na liście pól v25 dla FROM {$resource}");
			}
		}
	}

	public function testConversionActionSegmentOnlyWithConversionMetrics(): void
	{
		foreach (self::queries() as $name => $query) {
			[, $fields] = GaqlSchemaV25::parse($query);

			if (in_array('segments.conversion_action', $fields, true)) {
				$this->assertSame([], array_values(array_intersect($fields, GaqlSchemaV25::NOT_WITH_CONVERSION_ACTION)), "{$name}: segment conversion_action z niedozwoloną metryką");
			}
		}
	}

	public function testRemovedCampaignDateFieldsAreNotUsed(): void
	{
		$campaigns = preg_replace('/\s+/', ' ', Gaql::campaigns());

		$this->assertFalse((bool) preg_match('/campaign\.(start|end)_date\s*(,|FROM)/', $campaigns), 'campaign.start_date / end_date nie istnieją od v23.');
		$this->assertContains('campaign.start_date_time', $campaigns);
		$this->assertContains('campaign.end_date_time', $campaigns);
	}

	public function testSchemaGuardDetectsUnsupportedFields(): void
	{
		[$resource, $fields] = GaqlSchemaV25::parse('SELECT campaign.id, campaign.start_date FROM campaign WHERE campaign.end_date > \'2026-01-01\'');

		$this->assertSame('campaign', $resource);
		$this->assertTrue(in_array('campaign.start_date', $fields, true));
		$this->assertTrue(in_array('campaign.end_date', $fields, true), 'Pola z WHERE też są sprawdzane.');
		$this->assertTrue(isset(GaqlSchemaV25::REMOVED['campaign.start_date']));
	}

	public function testDateRangesAreSafeLiterals(): void
	{
		foreach (['campaignMetrics', 'keywordMetrics', 'searchTermMetrics', 'conversionMetrics', 'campaignConversionMetrics'] as $name) {
			$this->assertTrue((bool) preg_match("/segments\.date BETWEEN '\d{4}-\d{2}-\d{2}' AND '\d{4}-\d{2}-\d{2}'$/", trim(self::queries()[$name])), $name);
		}
	}
}
