<?php

namespace Tests\Unit;

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
			'customer' => ConnectionService::CUSTOMER_QUERY,
			'customerClient' => ConnectionService::CLIENTS_QUERY,
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
		foreach (['campaignMetrics', 'keywordMetrics', 'searchTermMetrics', 'conversionMetrics'] as $name) {
			$this->assertTrue((bool) preg_match("/segments\.date BETWEEN '\d{4}-\d{2}-\d{2}' AND '\d{4}-\d{2}-\d{2}'$/", trim(self::queries()[$name])), $name);
		}
	}
}
