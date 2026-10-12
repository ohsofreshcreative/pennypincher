<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Conversions\ConversionHealthCheck;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\GoogleAds\Changes\ResourceName;
use App\PennyPincher\Domain\GoogleAds\Changes\WriteMode;
use App\PennyPincher\Http\Controllers\ChangeCenterController;
use App\PennyPincher\Infrastructure\GoogleAds\Changes\StateQueries;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\GoogleAdsMutateClient;
use App\PennyPincher\Repositories\ChangeSetRepository;
use Tests\TestCase;

/**
 * Model zmian Google Ads bez bazy i sieci: tryby, walidacja operacji, budowanie MutateOperation, operacje
 * odwrotne, Health Check konwersji, bezpieczeństwo zapytań GAQL i parsowanie błędów API.
 */
class ChangeDomainTest extends TestCase
{
	private const C = '1234567890';

	public function testWriteModeTakesTheMostRestrictiveAndUnknownMeansReadOnly(): void
	{
		$this->assertSame(WriteMode::PrepareOnly, WriteMode::fromConfig(null));
		$this->assertSame(WriteMode::PrepareOnly, WriteMode::fromConfig(''));
		$this->assertSame(WriteMode::WriteEnabled, WriteMode::fromConfig(' WRITE_ENABLED '));
		$this->assertSame(WriteMode::ReadOnly, WriteMode::fromConfig('true'));
		$this->assertSame(WriteMode::ReadOnly, WriteMode::fromConfig('1'));
		$this->assertSame(WriteMode::PrepareOnly, WriteMode::min(WriteMode::WriteEnabled, WriteMode::PrepareOnly));
		$this->assertSame(WriteMode::ReadOnly, WriteMode::min(WriteMode::ReadOnly, WriteMode::WriteEnabled));
		$this->assertFalse(WriteMode::PrepareOnly->canWrite());
		$this->assertTrue(WriteMode::PrepareOnly->canPrepare());
		$this->assertFalse(WriteMode::ReadOnly->canPrepare());
	}

	public function testResourceNamesAreBoundToTheCustomer(): void
	{
		$this->assertTrue(ResourceName::isValid('customers/' . self::C . '/campaigns/111', self::C));
		$this->assertFalse(ResourceName::isValid('customers/9999999999/campaigns/111', self::C), 'Zasób innego konta.');
		$this->assertFalse(ResourceName::isValid('customers/' . self::C . '/campaigns/111/../x', self::C));
		$this->assertSame('333', ResourceName::childId('customers/' . self::C . '/adGroupCriteria/222~333'));
	}

	public function testGaqlStateQueriesNeverEmbedUnsafeText(): void
	{
		$this->assertThrows(\InvalidArgumentException::class, fn () => StateQueries::campaign("customers/1/campaigns/1' OR campaign.id > '0"));
		$this->assertThrows(\InvalidArgumentException::class, fn () => StateQueries::budget('customers/1/campaignBudgets/1 OR 1=1'));
		$query = StateQueries::languageByCode("pl' OR '1'='1");
		$this->assertSame(2, substr_count($query, "'"), 'Kod języka oczyszczony – tylko jedna para cudzysłowów.');
		$this->assertFalse(str_contains($query, ' OR '));
	}

	public function testBudgetValidationAndRisk(): void
	{
		$op = fn (int $amount, array $before = ['amount_micros' => 50_000_000, 'explicitly_shared' => false, 'reference_count' => 1]) => ['type' => 'campaign_budget.update', 'resource_name' => 'customers/' . self::C . '/campaignBudgets/444', 'payload' => ['amount_micros' => $amount], 'before_state' => $before];
		$context = ['customer_id' => self::C, 'currency' => 'PLN'];

		$this->assertSame([], OperationCatalog::validate($op(70_000_000), $context)['errors']);
		$this->assertTrue(OperationCatalog::validate($op(70_005_000), $context)['errors'] !== [], 'Kwota nie jest wielokrotnością 0,01.');
		$this->assertTrue(OperationCatalog::validate($op(0), $context)['errors'] !== []);
		$this->assertSame('high', OperationCatalog::risk($op(70_000_000)), 'Zwiększenie budżetu.');
		$this->assertSame('medium', OperationCatalog::risk($op(40_000_000)));
		$this->assertSame(20_000_000, OperationCatalog::budgetImpact($op(70_000_000)));

		$shared = $op(70_000_000, ['amount_micros' => 50_000_000, 'explicitly_shared' => true, 'reference_count' => 3]);
		$this->assertTrue(OperationCatalog::validate($shared, $context)['errors'] !== [], 'Budżet współdzielony wymaga potwierdzenia.');
		$shared['payload']['shared_ack'] = true;
		$this->assertSame([], OperationCatalog::validate($shared, $context)['errors']);

		// Zasób innego konta jest odrzucany już lokalnie.
		$foreign = $op(70_000_000);
		$foreign['resource_name'] = 'customers/9999999999/campaignBudgets/444';
		$this->assertTrue(OperationCatalog::validate($foreign, $context)['errors'] !== []);
	}

	public function testKeywordRulesFollowGoogleLimits(): void
	{
		$kw = fn (string $text, string $match = 'PHRASE') => ['type' => 'ad_group_criterion.create', 'resource_name' => null, 'payload' => ['ad_group' => 'customers/' . self::C . '/adGroups/222', 'text' => $text, 'match_type' => $match], 'before_state' => null];
		$context = ['customer_id' => self::C];

		$this->assertSame([], OperationCatalog::validate($kw('sofa narożna'), $context)['errors']);
		$this->assertTrue(OperationCatalog::validate($kw(str_repeat('a', 81)), $context)['errors'] !== [], 'Maks. 80 znaków.');
		$this->assertTrue(OperationCatalog::validate($kw('a b c d e f g h i j k'), $context)['errors'] !== [], 'Maks. 10 słów.');
		$this->assertTrue(OperationCatalog::validate($kw('sofa', 'BROAD_MODIFIED'), $context)['errors'] !== []);
		$this->assertTrue(OperationCatalog::validate($kw('sofa narożna'), $context + ['existing_keywords' => ['sofa narożna|PHRASE']])['errors'] !== [], 'Duplikat.');
		$this->assertSame([], OperationCatalog::validate($kw('sofa narożna', 'EXACT'), $context + ['existing_keywords' => ['sofa narożna|PHRASE']])['errors'], 'Inne dopasowanie to inne słowo.');
	}

	public function testNewCampaignIsAlwaysBuiltPausedWithNonSharedBudget(): void
	{
		$budget = OperationCatalog::build(['type' => 'campaign_budget.create', 'payload' => ['name' => 'B', 'amount_micros' => 30_000_000], 'temp_ref' => 'b'], self::C, ['ref:b' => 'customers/' . self::C . '/campaignBudgets/-1']);
		$this->assertSame(false, $budget['campaignBudgetOperation']['create']['explicitlyShared']);
		$this->assertSame('customers/' . self::C . '/campaignBudgets/-1', $budget['campaignBudgetOperation']['create']['resourceName']);
		$this->assertSame('30000000', $budget['campaignBudgetOperation']['create']['amountMicros'], 'int64 jako tekst (JSON REST).');

		$campaign = OperationCatalog::build(['type' => 'campaign.create', 'payload' => ['name' => 'K', 'budget' => 'ref:b', 'status' => 'ENABLED', 'eu_political' => 'no'], 'temp_ref' => 'c'], self::C, ['ref:b' => 'customers/' . self::C . '/campaignBudgets/-1', 'ref:c' => 'customers/' . self::C . '/campaigns/-2']);
		$create = $campaign['campaignOperation']['create'];
		$this->assertSame('PAUSED', $create['status'], 'Nawet jeśli ładunek prosi o ENABLED.');
		$this->assertSame('SEARCH', $create['advertisingChannelType']);
		$this->assertSame('customers/' . self::C . '/campaignBudgets/-1', $create['campaignBudget']);
		$this->assertSame('DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING', $create['containsEuPoliticalAdvertising']);
		$this->assertTrue(isset($create['manualCpc']));

		$this->assertThrows(\RuntimeException::class, fn () => OperationCatalog::build(['type' => 'campaign.create', 'payload' => ['name' => 'K', 'budget' => 'ref:x']], self::C, []), 'Nierozwiązane');
	}

	public function testUpdateUsesFieldMaskOnlyForChangedFields(): void
	{
		$built = OperationCatalog::build(['type' => 'campaign.update', 'resource_name' => 'customers/' . self::C . '/campaigns/111', 'payload' => ['target_search_network' => false, 'shared_ack' => true]], self::C, []);
		$this->assertSame('networkSettings.targetSearchNetwork', $built['campaignOperation']['updateMask']);
		$this->assertSame(false, $built['campaignOperation']['update']['networkSettings']['targetSearchNetwork']);
		$this->assertFalse(isset($built['campaignOperation']['update']['sharedAck']));

		$remove = OperationCatalog::build(['type' => 'ad_group_criterion.remove', 'resource_name' => 'customers/' . self::C . '/adGroupCriteria/222~333', 'payload' => []], self::C, []);
		$this->assertSame('customers/' . self::C . '/adGroupCriteria/222~333', $remove['adGroupCriterionOperation']['remove']);
	}

	public function testInverseOperationsOnlyWhereSafe(): void
	{
		$budget = ['type' => 'campaign_budget.update', 'resource_name' => 'customers/' . self::C . '/campaignBudgets/444', 'payload' => ['amount_micros' => 70_000_000], 'before_state' => ['amount_micros' => 50_000_000]];
		$this->assertSame(50_000_000, OperationCatalog::inverse($budget)['payload']['amount_micros']);
		$this->assertNull(OperationCatalog::inverse(['before_state' => null] + $budget), 'Bez znanego stanu „przed” nie ma cofnięcia.');

		$created = ['type' => 'ad_group_criterion.create', 'resource_name' => null, 'payload' => ['ad_group' => 'customers/' . self::C . '/adGroups/222', 'text' => 'x', 'match_type' => 'EXACT'], 'before_state' => null];
		$inverse = OperationCatalog::inverse($created, 'customers/' . self::C . '/adGroupCriteria/222~7000');
		$this->assertTrue($inverse !== null && in_array($inverse['type'], ['ad_group_criterion.remove', 'ad_group_criterion.update'], true));

		$removed = ['type' => 'ad_group_criterion.remove', 'resource_name' => 'customers/' . self::C . '/adGroupCriteria/222~333', 'payload' => [], 'before_state' => ['status' => 'ENABLED']];
		$this->assertNull(OperationCatalog::inverse($removed), 'Usuniętego słowa nie da się przywrócić z historią – brak obietnicy cofnięcia.');
	}

	public function testConversionEditabilityAndGoalConfirmation(): void
	{
		$this->assertSame([], OperationCatalog::conversionEditable(['type' => 'WEBPAGE', 'owner_customer' => null]), 'Działanie systemowe / innego właściciela.');
		$this->assertTrue(in_array('primary_for_goal', OperationCatalog::conversionEditable(['type' => 'WEBPAGE', 'owner_customer' => 'customers/' . self::C]), true));

		$op = ['type' => 'conversion_action.update', 'resource_name' => 'customers/' . self::C . '/conversionActions/901', 'payload' => ['primary_for_goal' => false], 'before_state' => ['type' => 'WEBPAGE', 'owner_customer' => 'customers/' . self::C, 'primary_for_goal' => true]];
		$this->assertTrue(OperationCatalog::validate($op, ['customer_id' => self::C])['errors'] !== [], 'Zmiana celu wymaga potwierdzenia.');
		$op['payload']['confirm_goal_change'] = true;
		$this->assertSame([], OperationCatalog::validate($op, ['customer_id' => self::C])['errors']);
		$this->assertTrue(OperationCatalog::affectsBidding($op));
		$this->assertSame('high', OperationCatalog::risk($op));
	}

	public function testHealthCheckRecognisesTheOhSoFreshMicroConversion(): void
	{
		$action = ['id' => 1, 'name' => '30 sekund - Bezpłatna konsultacja', 'status' => 'ENABLED', 'primary_for_goal' => true, 'category' => 'DEFAULT', 'counting_type' => 'ONE_PER_CLICK', 'editable' => ['primary_for_goal'], 'conversions_90' => 120.0, 'campaigns' => [['name' => 'Szukaj | Konsultacje', 'conversions' => 120.0]], 'effective_class' => 'unclassified', 'classification_source' => 'suggested'];
		$form = ['id' => 2, 'name' => 'GAds - Formularz kontaktowy', 'status' => 'ENABLED', 'primary_for_goal' => true, 'category' => 'SUBMIT_LEAD_FORM', 'counting_type' => 'ONE_PER_CLICK', 'editable' => ['primary_for_goal'], 'conversions_90' => 8.0, 'campaigns' => [], 'effective_class' => 'lead', 'classification_source' => 'suggested'];
		$issues = ConversionHealthCheck::check([$action, $form], ['customer' => null, 'campaigns' => null], ['reliable' => true, 'reason' => ''], true);
		$micro = array_values(array_filter($issues, fn ($i) => $i['kind'] === 'micro_primary'));

		$this->assertCount(1, $micro);
		$this->assertSame(1, $micro[0]['action_id']);
		$this->assertSame(['primary_for_goal' => false], $micro[0]['operations'][0]['payload'], 'Propozycja: ustawić jako dodatkową, nie usuwać.');
		$this->assertSame('Szukaj | Konsultacje', $micro[0]['campaigns'][0]['name']);
		$this->assertSame('likely', ConversionHealthCheck::isMicro(['name' => 'Scroll 75%']));
		$this->assertNull(ConversionHealthCheck::isMicro(['name' => 'Formularz kontaktowy', 'category' => 'SUBMIT_LEAD_FORM']));

		$readOnly = ConversionHealthCheck::check([['editable' => []] + $action], ['customer' => null, 'campaigns' => null], ['reliable' => true, 'reason' => ''], true);
		$issue = array_values(array_filter($readOnly, fn ($i) => $i['kind'] === 'micro_primary'))[0];
		$this->assertSame([], $issue['operations'], 'Bez możliwości edycji przez API – tylko wyjaśnienie, bez udawanej operacji.');
		$this->assertTrue($issue['not_editable'] !== null);
	}

	public function testMutateFailuresAreMappedToOperationIndexes(): void
	{
		$failures = GoogleAdsMutateClient::failures(['message' => 'x', 'details' => [['errors' => [
			['errorCode' => ['criterionError' => 'KEYWORD_HAS_INVALID_CHARS'], 'message' => 'Zły znak', 'location' => ['fieldPathElements' => [['fieldName' => 'mutate_operations', 'index' => 2], ['fieldName' => 'create']]]],
			['errorCode' => ['requestError' => 'X'], 'message' => 'Ogólny'],
		]]]]);

		$this->assertSame('criterionError:KEYWORD_HAS_INVALID_CHARS', $failures[2]['code']);
		$this->assertSame('Ogólny', $failures[-1]['message']);
	}

	public function testHistoryScrubsTokensAndFormInputIsParsedSafely(): void
	{
		$this->assertSame('Bearer [ukryto] i [ukryto] oraz [ukryto]', ChangeSetRepository::scrub('Bearer ya29.abc-def i ya29.zzz oraz 1//refresh-token'));
		$this->assertSame(70_000_000, ChangeCenterController::micros('70,00 zł'));
		$this->assertSame(1_234_560_000, ChangeCenterController::micros('1 234,56'));
		$this->assertNull(ChangeCenterController::micros('abc'));

		$payload = ChangeCenterController::payload(['amount_micros' => '80', 'status' => 'paused', 'evil' => 'x', 'resource_name' => 'customers/1/campaigns/2', 'shared_ack' => '1'], ['amount_micros' => 70_000_000, 'status' => 'ENABLED']);
		$this->assertSame(['amount_micros' => 80_000_000, 'status' => 'PAUSED', 'shared_ack' => true], $payload, 'Tylko pola istniejącego ładunku – bez podmiany zasobu.');
	}

	public function testStatusesDistinguishApprovalFromDeployment(): void
	{
		$this->assertFalse(in_array(ChangeStatus::APPROVED, ChangeStatus::DEPLOYED, true));
		$this->assertFalse(in_array(ChangeStatus::READY, ChangeStatus::DEPLOYED, true));
		$this->assertContains('nie oznacza', ChangeStatus::EXPLANATIONS[ChangeStatus::APPROVED] . ' nie oznacza');
		$this->assertContains('Nic nie zostało jeszcze zmienione', ChangeStatus::EXPLANATIONS[ChangeStatus::READY]);
		$this->assertContains('nie jest potwierdzenie', ChangeStatus::EXPLANATIONS[ChangeStatus::DEPLOYING]);
		$this->assertSame('high', ChangeStatus::maxRisk(['low', 'high', 'medium']));
	}
}
