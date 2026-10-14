<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\GoogleAds\Changes\ServingGuard;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\GoogleAdsMutateClient;
use Tests\TestCase;

/**
 * Zabezpieczenia zapisu bez bazy i sieci: allowlista i konfigurowalna lista blokad kont, blokada emisji reklam,
 * kontrola Customer ID.
 */
class WriteSafetyTest extends TestCase
{
	private array $env = [];

	public function setUp(): void
	{
		parent::setUp();

		foreach (['PP_GOOGLE_ADS_WRITE_CUSTOMERS', 'PP_GOOGLE_ADS_BLOCKED_CUSTOMERS', 'PP_GOOGLE_ADS_ALLOW_SERVING'] as $name) {
			if (defined($name)) {
				throw new \Tests\SkipTest("Stała {$name} jest zdefiniowana – test wymaga zmiennych środowiskowych.");
			}

			$this->env[$name] = getenv($name);
			putenv($name);
		}
	}

	public function tearDown(): void
	{
		foreach ($this->env as $name => $value) {
			putenv($value === false ? $name : "{$name}={$value}");
		}

		parent::tearDown();
	}

	public function testAllowlistIsEmptyByDefaultAndNoAccountIsHardBlocked(): void
	{
		$config = new GoogleAdsConfig();
		$this->assertSame([], GoogleAdsConfig::BLOCKED_WRITE_CUSTOMERS, 'Brak niekonfigurowalnych blokad kont w kodzie.');
		$this->assertSame([], $config->writeCustomerAllowlist());
		$this->assertContains('allowliście', (string) $config->writeCustomerBlocker('689-970-4535'), 'Pusta allowlista = żadne konto, także OhSoFresh.');
		$this->assertContains('Brak identyfikatora', (string) $config->writeCustomerBlocker(null));

		putenv('PP_GOOGLE_ADS_WRITE_CUSTOMERS=689-970-4535; 123-456-7890 , 12345, abc');
		$this->assertSame(['6899704535', '1234567890'], $config->writeCustomerAllowlist(), 'Format z myślnikami, separatory, odrzucone niepełne ID.');
		$this->assertTrue($config->isWriteAllowedCustomer('6899704535'), 'Konto OhSoFresh dozwolone dopiero po jawnym dodaniu do allowlisty.');
		$this->assertNull($config->writeCustomerBlocker('689 970 4535'));
		$this->assertTrue($config->isWriteAllowedCustomer('06899704535'), 'Zera wiodące – to samo konto.');

		putenv('PP_GOOGLE_ADS_BLOCKED_CUSTOMERS=1234567890');
		$this->assertFalse($config->isWriteAllowedCustomer('1234567890'), 'Blokady konfigurowalne wygrywają z allowlistą.');
		$this->assertContains('PP_GOOGLE_ADS_BLOCKED_CUSTOMERS', (string) $config->writeCustomerBlocker('123-456-7890'));
		$this->assertTrue($config->isWriteBlockedCustomer('01234567890'), 'Zera wiodące nie omijają blokady.');
		$this->assertSame('689-970-4535', GoogleAdsConfig::formatCustomer('6899704535'));
	}

	public function testServingIsLockedUnlessExplicitlyAllowed(): void
	{
		$config = new GoogleAdsConfig();
		$this->assertFalse($config->servingAllowed());
		putenv('PP_GOOGLE_ADS_ALLOW_SERVING=tak');
		$this->assertFalse($config->servingAllowed(), 'Nieznana wartość nie zdejmuje blokady.');
		putenv('PP_GOOGLE_ADS_ALLOW_SERVING=1');
		$this->assertTrue($config->servingAllowed());
	}

	public function testServingGuardRules(): void
	{
		$this->assertContains('włączenie kampanii', (string) ServingGuard::operationViolation(['type' => 'campaign.update', 'payload' => ['status' => 'ENABLED']]));
		$this->assertContains('tylko jako wstrzymana', (string) ServingGuard::operationViolation(['type' => 'campaign.create', 'payload' => ['status' => 'ENABLED']]));
		$this->assertNotNull(ServingGuard::operationViolation(['type' => 'ad_group.create', 'payload' => ['status' => 'ENABLED']]));
		$this->assertNotNull(ServingGuard::operationViolation(['type' => 'ad_group_ad.update', 'payload' => ['status' => 'ENABLED']]));
		$this->assertNull(ServingGuard::operationViolation(['type' => 'ad_group.create', 'payload' => []]), 'Tworzenie domyślnie wstrzymane.');
		$this->assertNull(ServingGuard::operationViolation(['type' => 'campaign.update', 'payload' => ['status' => 'PAUSED']]));
		$this->assertNull(ServingGuard::operationViolation(['type' => 'campaign.update', 'payload' => ['name' => 'x']]));

		$budget = ['type' => 'campaign_budget.update', 'payload' => ['amount_micros' => 1]];
		$this->assertNotNull(ServingGuard::budgetViolation($budget, null), 'Nieznane kampanie = blokada.');
		$this->assertNotNull(ServingGuard::budgetViolation($budget, [['name' => 'A', 'status' => 'PAUSED'], ['name' => 'B', 'status' => 'ENABLED']]));
		$this->assertNull(ServingGuard::budgetViolation($budget, [['name' => 'A', 'status' => 'PAUSED']]));

		$keyword = ['type' => 'ad_group_criterion.create', 'payload' => ['ad_group' => 'customers/1/adGroups/2', 'text' => 'x']];
		$this->assertTrue(ServingGuard::activatesKeyword($keyword), 'Brak statusu = ENABLED.');
		$this->assertFalse(ServingGuard::activatesKeyword(['payload' => ['ad_group' => 'ref:g', 'text' => 'x']] + $keyword), 'Grupa z tego zestawu jest wstrzymana.');
		$this->assertFalse(ServingGuard::activatesKeyword(['payload' => ['ad_group' => 'customers/1/adGroups/2', 'negative' => true]] + $keyword), 'Wykluczenie nie emituje.');
		$this->assertNotNull(ServingGuard::keywordViolation($keyword, ['status' => 'ENABLED'], ['status' => 'ENABLED']));
		$this->assertNull(ServingGuard::keywordViolation($keyword, ['status' => 'ENABLED'], ['status' => 'PAUSED']));
		$this->assertNotNull(ServingGuard::keywordViolation($keyword, null, null), 'Nieznany rodzic = blokada.');

		$scan = ServingGuard::scanMutate([
			['campaignOperation' => ['create' => ['name' => 'Bez statusu']]],
			['adGroupOperation' => ['update' => ['resourceName' => 'x', 'status' => 'ENABLED'], 'updateMask' => 'status']],
			['adGroupAdOperation' => ['create' => ['status' => 'PAUSED']]],
			['adGroupCriterionOperation' => ['create' => ['status' => 'ENABLED']]],
		]);
		$this->assertSame([0, 1], array_keys($scan));

		// Enum w postaci liczbowej (REST przyjmuje 2 = ENABLED) i małe litery – też odrzucone; PAUSED/REMOVED dozwolone.
		$numeric = ServingGuard::scanMutate([
			['campaignOperation' => ['update' => ['resourceName' => 'x', 'status' => 2], 'updateMask' => 'status']],
			['adGroupAdOperation' => ['update' => ['resourceName' => 'x', 'status' => 'enabled'], 'updateMask' => 'status']],
			['adGroupOperation' => ['create' => ['status' => 3]]],
			['campaignOperation' => ['update' => ['resourceName' => 'x', 'status' => 'REMOVED'], 'updateMask' => 'status']],
			['campaignOperation' => ['update' => ['resourceName' => 'x', 'name' => 'bez statusu'], 'updateMask' => 'name']],
		]);
		$this->assertSame([0, 1], array_keys($numeric));
	}

	public function testForeignCustomerDetection(): void
	{
		$ops = [['adGroupCriterionOperation' => ['create' => ['adGroup' => 'customers/1234567890/adGroups/-2']]], ['campaignOperation' => ['update' => ['resourceName' => 'customers/6899704535/campaigns/1']]]];
		$this->assertSame(['6899704535'], GoogleAdsMutateClient::foreignCustomers($ops, '1234567890'));
		$this->assertSame([], GoogleAdsMutateClient::foreignCustomers([$ops[0], ['campaignCriterionOperation' => ['create' => ['language' => ['languageConstant' => 'languageConstants/1030']]]]], '1234567890'));
	}
}
