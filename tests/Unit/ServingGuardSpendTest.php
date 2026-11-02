<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\GoogleAds\Changes\ServingGuard;
use Tests\TestCase;

/**
 * Blokada emisji: rozpoznanie zmian, które mogą zwiększyć wydatki, i decyzja na podstawie stanu kampanii/grupy.
 */
class ServingGuardSpendTest extends TestCase
{
	public function testRecognisesSpendIncreasingOperations(): void
	{
		$this->assertTrue(ServingGuard::raisesSpend(['type' => 'campaign.update', 'payload' => ['target_content_network' => true]], ['target_content_network' => false]));
		$this->assertFalse(ServingGuard::raisesSpend(['type' => 'campaign.update', 'payload' => ['target_content_network' => true]], ['target_content_network' => true]), 'Już włączone – bez zmiany.');
		$this->assertFalse(ServingGuard::raisesSpend(['type' => 'campaign.update', 'payload' => ['target_content_network' => false, 'name' => 'X']], ['target_content_network' => true]));
		$this->assertTrue(ServingGuard::raisesSpend(['type' => 'campaign.update', 'payload' => ['target_search_network' => true]], null), 'Nieznany stan = najgorszy przypadek.');
		$this->assertTrue(ServingGuard::raisesSpend(['type' => 'campaign.bidding', 'payload' => ['strategy' => 'TARGET_SPEND']], []));
		$this->assertTrue(ServingGuard::raisesSpend(['type' => 'ad_group_criterion.update', 'payload' => ['cpc_bid_micros' => 3_000_000]], ['cpc_bid_micros' => 2_000_000]));
		$this->assertFalse(ServingGuard::raisesSpend(['type' => 'ad_group.update', 'payload' => ['cpc_bid_micros' => 1_000_000]], ['cpc_bid_micros' => 2_000_000]));
		$this->assertTrue(ServingGuard::raisesSpend(['type' => 'ad_group.update', 'payload' => ['cpc_bid_micros' => 1_000_000]], ['cpc_bid_micros' => null]));
		$this->assertFalse(ServingGuard::raisesSpend(['type' => 'ad_group.update', 'payload' => ['name' => 'Y']], []));
		$this->assertTrue(ServingGuard::raisesSpend(['type' => 'campaign_criterion.remove', 'payload' => []], ['negative' => true, 'type' => 'KEYWORD']));
		$this->assertTrue(ServingGuard::raisesSpend(['type' => 'campaign_criterion.remove', 'payload' => []], ['negative' => false, 'type' => 'LOCATION']));
		$this->assertFalse(ServingGuard::raisesSpend(['type' => 'ad_group_criterion.remove', 'payload' => []], ['negative' => false, 'type' => 'KEYWORD']), 'Usunięcie słowa ogranicza wydatki.');
		$this->assertTrue(ServingGuard::raisesSpend(['type' => 'ad_group_criterion.remove', 'payload' => []], ['negative' => true]));
	}

	public function testDecisionDependsOnServingParents(): void
	{
		$op = ['type' => 'ad_group.update', 'label' => 'Stawka'];
		$this->assertNotNull(ServingGuard::spendViolation($op, ['status' => 'ENABLED'], ['status' => 'ENABLED'], true));
		$this->assertNull(ServingGuard::spendViolation($op, ['status' => 'PAUSED'], ['status' => 'ENABLED'], true));
		$this->assertNull(ServingGuard::spendViolation($op, ['status' => 'ENABLED'], ['status' => 'PAUSED'], true));
		$this->assertNotNull(ServingGuard::spendViolation($op, ['status' => 'ENABLED'], null, true), 'Nieznana grupa = blokada.');
		$this->assertNotNull(ServingGuard::spendViolation(['type' => 'campaign.bidding'], null, null, false), 'Nieznana kampania = blokada.');
		$this->assertNull(ServingGuard::spendViolation(['type' => 'campaign.bidding'], ['status' => 'PAUSED'], null, false));
	}
}
