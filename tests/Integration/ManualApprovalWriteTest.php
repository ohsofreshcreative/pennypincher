<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\GoogleAdsMutateClient;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\WriteBlockedException;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use Tests\Support\GoogleAdsMock;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Model MANUAL APPROVAL, konto OhSoFresh, edycja istniejących kampanii, blokada emisji i Change Center –
 * wyłącznie na atrapie Google Ads API (bez sieci, bez prawdziwych mutacji).
 *
 * Konfiguracja jak docelowa: WRITE_ENABLED tylko w teście, allowlista = konto atrapy (w produkcji: 689-970-4535),
 * blokada emisji reklam WŁĄCZONA (PP_GOOGLE_ADS_ALLOW_SERVING nieustawione).
 */
class ManualApprovalWriteTest extends ChangeCenterTestCase
{
	private const OHSOFRESH = '6899704535';

	private function writeAccount(string $name, bool $pause = true): void
	{
		$this->account($name, true);

		if ($pause) {
			$this->pauseTestCampaign();
		}
	}

	private function executed(): int
	{
		return $this->fake->executedMutations();
	}

	private function recommendation(string $type, string $title, ?int $campaignId): int
	{
		$now = $this->db->now();

		return $this->db->insert('recommendations', [
			'project_id' => $this->project->id, 'fingerprint' => md5($type . $title . microtime()), 'source' => 'audit', 'type' => $type, 'title' => $title,
			'description' => $title, 'rationale' => '', 'evidence' => '[]', 'action' => $title, 'campaign_id' => $campaignId, 'ad_group_id' => null,
			'priority' => 'medium', 'priority_score' => 50, 'confidence' => 'high', 'status' => 'new', 'is_obsolete' => 0,
			'first_seen_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now,
		]);
	}

	// --- A. MANUAL APPROVAL ------------------------------------------------------------------------

	public function testRecommendationDraftAndApprovalNeverWriteOnlyManualDeployDoes(): void
	{
		$this->writeAccount('ma-flow');
		$rec = $this->recommendation('audit_cs001', 'Partnerzy wyszukiwania bez konwersji', $this->campaignId());

		$setId = $this->proposals()->fromRecommendation($this->project, $this->owner, $rec);
		$this->assertSame(0, $this->mock->count('mutate'), 'Przygotowanie zmiany nie wysyła nic do Google Ads.');
		$this->assertSame(ChangeStatus::DRAFT, $this->set($setId)['status']);

		$this->approve($setId);
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status']);
		$this->assertSame(0, $this->executed(), 'Zatwierdzenie nie wdraża (najwyżej walidacja validate_only bez zapisu).');

		$this->assertSame(0, $this->execution()->work(30)['processed'], 'Worker bez polecenia wdrożenia nie robi nic.');
		$this->assertSame(0, $this->executed());

		$this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), $this->confirmation($setId));
		$this->execution()->work(30);
		$this->assertSame(1, $this->executed(), 'Dopiero ręczne „Wdróż do Google Ads”.');
		$this->assertSame(ChangeStatus::APPLIED, $this->set($setId)['status']);
		$this->assertFalse($this->fake->value('campaign', $this->fake->name('campaign', '111'), 'network_settings.target_search_network'));
	}

	public function testWorkerExecutesOnlySetsWithValidManualCommandForApprovedVersion(): void
	{
		$this->writeAccount('ma-command');
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | Marka X']);
		$this->approve($setId);

		// W kolejce bez ręcznego polecenia (np. błąd albo próba z crona) – zatrzymany przed wysłaniem.
		$this->sets()->transition($setId, [ChangeStatus::READY], ChangeStatus::DEPLOYING, []);
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status']);
		$this->assertContains('ręcznego polecenia', (string) $this->set($setId)['last_error']);

		// Polecenie sprzed zatwierdzenia tej wersji – nieważne.
		$approvedAt = (string) $this->set($setId)['approved_at'];
		$this->sets()->transition($setId, [ChangeStatus::READY], ChangeStatus::DEPLOYING, ['deploy_key' => wp_generate_uuid4(), 'deploy_requested_by' => $this->owner, 'deploy_requested_at' => gmdate('Y-m-d H:i:s', strtotime($approvedAt . ' UTC') - 3600)]);
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status']);
		$this->assertSame(0, $this->executed());
	}

	public function testEditingInvalidatesApprovalAndRedeployNeverDuplicates(): void
	{
		$this->writeAccount('ma-version');
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | V1']);
		$this->approve($setId);
		$this->assertSame((int) $this->set($setId)['version'], (int) $this->set($setId)['approved_version'], 'Zatwierdzona konkretna wersja.');
		$approvedVersion = (int) $this->set($setId)['version'];

		$this->center()->reject($this->project, $setId, $this->owner, 'Poprawka nazwy');
		$this->center()->updateOperation($this->project, $setId, (int) $this->ops($setId)[0]['id'], ['name' => 'Szukaj | V2'], $this->owner);
		$set = $this->set($setId);
		$this->assertNull($set['approved_hash'], 'Edycja unieważnia zatwierdzenie.');
		$this->assertTrue((int) $set['version'] > $approvedVersion, 'Edycja podnosi wersję.');
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'));

		$this->approve($setId);
		$key = wp_generate_uuid4();
		$this->center()->requestDeploy($this->project, $setId, $this->owner, $key, $this->confirmation($setId));
		$this->assertContains('zignorowane', $this->center()->requestDeploy($this->project, $setId, $this->owner, $key, $this->confirmation($setId)), 'Podwójne kliknięcie.');
		$this->execution()->work(30);
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::APPLIED, $this->set($setId)['status']);
		$this->assertSame(1, $this->executed());
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'), 'Gotowy do wdrożenia');
		$this->assertSame('Szukaj | V2', $this->fake->value('campaign', $this->fake->name('campaign', '111'), 'name'));
	}

	public function testMissingPermissionsBlockDeployment(): void
	{
		$this->writeAccount('ma-roles');
		[$memberId, $memberProject] = $this->member('ma-roles-member', Role::Member);
		[$adminId, $adminProject] = $this->member('ma-roles-admin', Role::Admin);
		$setId = $this->proposals()->campaignUpdate($memberProject, $memberId, $this->campaignId(), ['name' => 'Szukaj | Członek']);
		$this->approve($setId);

		$this->assertThrows(\Throwable::class, fn () => $this->center()->requestDeploy($memberProject, $setId, $memberId, wp_generate_uuid4(), 'tak'));
		$this->assertThrows(WriteBlockedException::class, fn () => $this->center()->requestDeploy($adminProject, $setId, $adminId, wp_generate_uuid4(), 'tak'), 'wykonawca');
		$this->assertSame(0, $this->executed());
	}

	// --- B. KONTO OHSOFRESH --------------------------------------------------------------------------

	public function testOhSoFreshNeedsAllowlistWriteModeAndConsent(): void
	{
		$this->writeAccount('oh-gates');
		$config = $this->make(GoogleAdsConfig::class);
		$this->assertFalse($config->isWriteBlockedCustomer(self::OHSOFRESH), 'Brak twardej blokady w kodzie.');

		$this->db->update('google_ads_connections', ['customer_id' => self::OHSOFRESH, 'write_enabled' => 0], ['id' => (int) $this->connection['id']]);
		$connection = fn () => $this->make(GoogleAdsConnectionRepository::class)->currentForProject($this->project->id);
		$guard = $this->make(WriteGuard::class);

		putenv('PP_GOOGLE_ADS_WRITE_MODE');
		$this->assertContains('wyłączony na serwerze', (string) $guard->executeBlocker($this->project, $connection(), $this->owner), 'Brak WRITE_ENABLED.');

		putenv('PP_GOOGLE_ADS_WRITE_MODE=write_enabled');
		putenv('PP_GOOGLE_ADS_WRITE_CUSTOMERS');
		$this->assertContains('allowliście', (string) $guard->executeBlocker($this->project, $connection(), $this->owner), 'Pusta allowlista.');

		putenv('PP_GOOGLE_ADS_WRITE_CUSTOMERS=689-970-4535');
		$this->assertContains('nie włączył zapisu', (string) $guard->executeBlocker($this->project, $connection(), $this->owner), 'Sam wpis na allowliście nie włącza zapisu.');

		$this->db->update('google_ads_connections', ['write_enabled' => 1], ['id' => (int) $this->connection['id']]);
		$this->assertNull($guard->executeBlocker($this->project, $connection(), $this->owner), 'Wszystkie warunki spełnione.');
		$this->assertTrue($guard->mode($connection())->canWrite());

		putenv('PP_GOOGLE_ADS_WRITE_CUSTOMERS');
		$client = $this->make(GoogleAdsMutateClient::class);
		$token = $this->make(ConnectionService::class)->accessToken($this->connection);
		$op = [['campaignOperation' => ['update' => ['resourceName' => 'customers/' . self::OHSOFRESH . '/campaigns/1', 'name' => 'x'], 'updateMask' => 'name']]];
		$this->assertThrows(WriteBlockedException::class, fn () => $client->mutate($token, self::OHSOFRESH, null, $op, false, false), 'allowliście');
		$this->assertSame(0, $this->mock->count('mutate'));
	}

	public function testCustomerIdCannotBeSwapped(): void
	{
		$this->writeAccount('oh-swap');
		$other = '1111111111';
		putenv('PP_GOOGLE_ADS_WRITE_CUSTOMERS=' . GoogleAdsMock::CUSTOMER . ',' . $other);

		$set = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | A']);
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->addOperation($this->project, $set, ['type' => 'campaign.update', 'resource_name' => 'customers/' . $other . '/campaigns/111', 'payload' => ['name' => 'x']], $this->owner), 'nie należy');

		$this->approve($set);
		$this->center()->requestDeploy($this->project, $set, $this->owner, wp_generate_uuid4(), $this->confirmation($set));
		$this->sets()->update($set, ['customer_id' => $other]);
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::READY, $this->set($set)['status']);
		$this->assertContains('innego konta', (string) $this->set($set)['last_error']);

		$client = $this->make(GoogleAdsMutateClient::class);
		$token = $this->make(ConnectionService::class)->accessToken($this->connection);
		$mixed = [['campaignOperation' => ['update' => ['resourceName' => 'customers/' . $other . '/campaigns/5', 'name' => 'x'], 'updateMask' => 'name']]];
		$requests = $this->mock->count('mutate');
		$this->assertThrows(WriteBlockedException::class, fn () => $client->mutate($token, GoogleAdsMock::CUSTOMER, null, $mixed, false, false), 'innego konta');
		$this->assertThrows(WriteBlockedException::class, fn () => $client->mutate($token, GoogleAdsMock::CUSTOMER, null, $mixed, true, false), 'innego konta');
		$this->assertSame($requests, $this->mock->count('mutate'));
		$this->assertSame(0, $this->executed());
	}

	// --- C. ISTNIEJĄCE KAMPANIE --------------------------------------------------------------------

	public function testEditingExistingCampaignStructure(): void
	{
		$this->writeAccount('ex-edit');
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | Marka 2026', 'target_search_network' => false, 'target_content_network' => false]);
		$this->proposals()->keywordsAdd($this->project, $this->owner, $this->adGroupId(), ['meble na wymiar'], 'PHRASE', 'ENABLED', $setId);
		$this->proposals()->keywordRemove($this->project, $this->owner, $this->keywordId(), $setId);
		$this->proposals()->campaignCriterionAdd($this->project, $this->owner, $this->campaignId(), 'negative_keyword', ['phrases' => 'praca', 'match_type' => 'PHRASE'], $setId);
		$set = $this->deploy($setId);

		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertNotNull($set['verified_at'], 'Wdrożone i zweryfikowane odczytem.');
		$campaign = $this->fake->get('campaign', $this->fake->name('campaign', '111'));
		$this->assertSame('Szukaj | Marka 2026', $campaign['campaign.name']);
		$this->assertFalse($campaign['campaign.network_settings.target_search_network']);
		$this->assertSame('REMOVED', $this->fake->value('ad_group_criterion', $this->fake->name('ad_group_criterion', '222~333'), 'status'), 'Usunięte słowo.');
		$texts = array_map(fn ($k) => ($k['ad_group_criterion.keyword.text'] ?? '') . '|' . ($k['ad_group_criterion.status'] ?? ''), $this->fake->all('ad_group_criterion'));
		$this->assertTrue(in_array('meble na wymiar|ENABLED', $texts, true), 'Dodane słowo (kampania wstrzymana – bez emisji).');
		$negatives = array_filter($this->fake->all('campaign_criterion'), fn ($n) => ($n['campaign_criterion.keyword.text'] ?? '') === 'praca' && ! empty($n['campaign_criterion.negative']));
		$this->assertCount(1, $negatives, 'Wykluczenie kampanii.');
	}

	public function testRsaNewVersionAndRemoval(): void
	{
		$this->writeAccount('ex-rsa');
		$old = $this->fake->name('ad_group_ad', '222~9001');
		$this->fake->set('ad_group_ad', $old, 'status', 'PAUSED');
		$this->fake->set('ad_group_ad', $old, 'ad_group', $this->fake->name('ad_group', '222'));
		$this->fake->set('ad_group_ad', $old, 'ad.id', '9001');

		// „Edycja” RSA = nowa wersja (treść reklamy jest niezmienna) + wstrzymanie starej w jednym atomowym żądaniu.
		$values = ['headlines' => ['Meble na wymiar', 'Sofy i narożniki', 'Darmowy projekt'], 'descriptions' => ['Zaprojektujemy meble do Twojego wnętrza.', 'Umów konsultację.'], 'final_url' => 'https://example.test/meble'];
		$setId = $this->proposals()->adCreate($this->project, $this->owner, $this->adGroupId(), $values, null, $old);
		$diff = OperationCatalog::diff($this->ops($setId)[0]);
		$this->assertTrue(array_filter($diff, fn ($r) => $r['field'] === 'headlines') !== [], 'Podgląd treści przed zatwierdzeniem.');
		$this->assertSame(ChangeStatus::APPLIED, $this->deploy($setId)['status']);
		$new = array_values(array_filter($this->fake->all('ad_group_ad'), fn ($a) => ($a['ad_group_ad.ad.final_urls'] ?? null) === ['https://example.test/meble']))[0];
		$this->assertSame('PAUSED', $new['ad_group_ad.status'], 'Nowa wersja powstaje jako wstrzymana (blokada emisji).');

		$remove = $this->proposals()->adRemove($this->project, $this->owner, $this->adGroupId(), $old);
		$op = $this->ops($remove)[0];
		$this->assertSame('high', $op['risk']);
		$this->assertNull(OperationCatalog::inverse($op, $old), 'Usunięcia nie da się cofnąć automatycznie.');
		$this->assertSame(ChangeStatus::APPLIED, $this->deploy($remove)['status']);
		$this->assertSame('REMOVED', $this->fake->value('ad_group_ad', $old, 'status'));
	}

	public function testBudgetAndConversionChangesArePreparedWithRiskAndPercent(): void
	{
		$this->writeAccount('ex-budget', false); // kampania 111 AKTYWNA

		$setId = $this->proposals()->campaignBudget($this->project, $this->owner, $this->campaignId(), 75_000_000, false);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$op = $this->ops($setId)[0];
		$this->assertSame('high', $op['risk'], 'Zwiększenie budżetu = wysokie ryzyko.');
		$this->assertSame(25_000_000, OperationCatalog::budgetImpact($op), 'Różnica dzienna +25 zł (+50%).');
		$local = $this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId));
		$this->assertTrue($local['ok'], 'Zmianę budżetu aktywnej kampanii można zatwierdzić: ' . json_encode($local['errors'], JSON_UNESCAPED_UNICODE));
		$this->assertContains('aktywną kampanię', implode(' ', $local['serving']));

		$this->approve($setId);
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status']);
		$blocker = $this->center()->deployBlocker($this->project, $this->set($setId), $this->ops($setId), $this->connection, $this->owner);
		$this->assertContains('trybie bezpieczeństwa', (string) $blocker, 'Przycisk „Wdróż” niedostępny.');
		$this->assertThrows(WriteBlockedException::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'WDRÓŻ'), 'trybie bezpieczeństwa');
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status'], 'Zatwierdzenie zostaje – gotowe do późniejszego użycia.');

		$conv = $this->proposals()->conversionUpdate($this->project, $this->owner, $this->conversionId('902'), ['counting_type' => 'ONE_PER_CLICK']);
		$this->assertSame(ChangeStatus::APPLIED, $this->deploy($conv)['status']);
		$this->assertSame('ONE_PER_CLICK', $this->fake->value('conversion_action', $this->fake->name('conversion_action', '902'), 'counting_type'));
		$this->assertSame(50_000_000, (int) $this->fake->value('campaign_budget', $this->budgetName, 'amount_micros'), 'Budżet aktywnej kampanii bez zmian.');
	}

	// --- D. BLOKADA EMISJI ---------------------------------------------------------------------------

	public function testServingLockAllowsPreparingButBlocksDeployment(): void
	{
		$this->writeAccount('sv-lock');

		$new = $this->proposals()->newCampaign($this->project, $this->owner, ['name' => 'Nowa | Sofy', 'budget_micros' => 10_000_000, 'bidding' => 'MANUAL_CPC', 'languages' => ['pl'], 'eu_political' => 'no']);
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->addOperation($this->project, $new, ['type' => 'campaign.create', 'payload' => ['name' => 'Aktywna', 'budget' => 'ref:x', 'status' => 'ENABLED']], $this->owner), 'tylko jako wstrzymana');
		$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->adGroupCreate($this->project, $this->owner, $this->campaignId(), ['name' => 'G', 'status' => 'ENABLED']), 'tylko jako wstrzymana');
		$this->assertSame(ChangeStatus::APPLIED, $this->deploy($new)['status']);
		$created = array_values(array_filter($this->fake->all('campaign'), fn ($c) => $c['campaign.name'] === 'Nowa | Sofy'))[0];
		$this->assertSame('PAUSED', $created['campaign.status']);

		$this->fake->set('ad_group', $this->fake->name('ad_group', '222'), 'status', 'PAUSED');
		$ad = $this->fake->name('ad_group_ad', '222~9001');
		$this->fake->set('ad_group_ad', $ad, 'status', 'PAUSED');
		$this->fake->set('ad_group_ad', $ad, 'ad_group', $this->fake->name('ad_group', '222'));
		$this->fake->set('ad_group_ad', $ad, 'ad.id', '9001');
		$enable = $this->proposals()->adGroupUpdate($this->project, $this->owner, $this->adGroupId(), ['status' => 'ENABLED']);
		$this->proposals()->adStatus($this->project, $this->owner, $this->adGroupId(), $this->fake->name('ad_group_ad', '222~9001'), 'ENABLED', $enable);
		$this->approve($enable);
		$this->assertSame(ChangeStatus::READY, $this->set($enable)['status'], 'Włączenie można przygotować i zatwierdzić.');
		$this->assertCount(2, $this->center()->servingBlocks($this->ops($enable)));
		$this->assertThrows(WriteBlockedException::class, fn () => $this->center()->requestDeploy($this->project, $enable, $this->owner, wp_generate_uuid4(), $this->confirmation($enable)), 'trybie bezpieczeństwa');

		$this->sets()->transition($enable, [ChangeStatus::READY], ChangeStatus::DEPLOYING, ['deploy_key' => wp_generate_uuid4(), 'deploy_requested_by' => $this->owner, 'deploy_requested_at' => $this->db->now()]);
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::READY, $this->set($enable)['status']);
		$this->assertContains('Blokada emisji', (string) $this->set($enable)['last_error']);
		$client = $this->make(GoogleAdsMutateClient::class);
		$token = $this->make(ConnectionService::class)->accessToken($this->connection);
		$c = GoogleAdsMock::CUSTOMER;
		$this->assertThrows(WriteBlockedException::class, fn () => $client->mutate($token, $c, null, [['adGroupOperation' => ['update' => ['resourceName' => "customers/{$c}/adGroups/222", 'status' => 'ENABLED'], 'updateMask' => 'status']]], false, false), 'Blokada emisji');
		$this->assertSame('PAUSED', $this->fake->value('ad_group', $this->fake->name('ad_group', '222'), 'status'));
		$this->assertSame(1, $this->executed(), 'Wykonano tylko utworzenie wstrzymanej kampanii.');
	}

	// --- E. CHANGE CENTER -----------------------------------------------------------------------------

	public function testHistoryRequestIdApiErrorsAndPartialSuccess(): void
	{
		$this->writeAccount('cc-partial');
		[$adminId, $adminProject] = $this->member('cc-admin', Role::Admin);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | Częściowo']);
		$this->proposals()->keywordStatus($this->project, $this->owner, $this->keywordId(), 'PAUSED', $setId);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$this->center()->submit($this->project, $setId, $this->owner);
		$this->center()->approve($adminProject, $setId, $adminId, false, false);

		$this->fake->reject = fn ($resource) => $resource === 'ad_group_criterion' ? 'Atrapa: słowo zablokowane przez zasady reklamowe (POLICY_FINDING).' : null;
		$set = $this->queueAndWork($setId);
		$this->fake->reject = null;

		$this->assertSame(ChangeStatus::PARTIAL, $set['status']);
		$byType = array_column($this->ops($setId), null, 'type');
		$this->assertSame(ChangeStatus::OP_VERIFIED, $byType['campaign.update']['status'], 'Wdrożona i zweryfikowana odczytem.');
		$this->assertSame(ChangeStatus::OP_FAILED, $byType['ad_group_criterion.update']['status']);
		$this->assertContains('POLICY_FINDING', (string) $byType['ad_group_criterion.update']['error_message'], 'Konkretny powód odrzucenia.');

		$this->assertSame($this->owner, (int) $set['created_by']);
		$this->assertSame($adminId, (int) $set['approved_by']);
		$this->assertNotNull($set['approved_at']);
		$this->assertSame($this->owner, (int) $set['deploy_requested_by']);
		$this->assertNotNull($set['deploy_requested_at']);
		$events = array_column($this->sets()->events($this->project->id, $setId), 'event');

		foreach (['created', 'submitted', 'approved', 'deploy_requested', 'deployed'] as $event) {
			$this->assertTrue(in_array($event, $events, true), 'Zdarzenie w historii: ' . $event . ' (' . implode(', ', $events) . ')');
		}

		$attempts = $this->sets()->attempts($this->project->id, $setId);
		$execute = array_values(array_filter($attempts, fn ($a) => $a['kind'] === 'execute'))[0];
		$this->assertSame('partial', $execute['outcome']);
		$this->assertSame('fake-request', $execute['request_id']);
		$this->assertTrue(in_array('verify', array_column($attempts, 'kind'), true), 'Weryfikacja po zapisie zapisana w historii.');

		$retry = $this->center()->retryFailed($this->project, $setId, $this->owner);
		$this->assertSame(ChangeStatus::DRAFT, $this->set($retry)['status'], 'Ponowienie nieudanych = nowy szkic do przeglądu.');
		$this->assertSame(['ad_group_criterion.update'], array_column($this->ops($retry), 'type'));
		$this->assertSame(1, $this->executed());
	}

	public function testConflictAfterDirectChangeInGoogleAds(): void
	{
		$this->writeAccount('cc-conflict');
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | Nowa']);
		$this->approve($setId);
		$this->fake->set('campaign', $this->fake->name('campaign', '111'), 'name', 'Zmienione ręcznie w Google Ads');
		$set = $this->queueAndWork($setId);

		$this->assertSame(ChangeStatus::REVIEW, $set['status']);
		$this->assertSame(ChangeStatus::OP_CONFLICT, $this->ops($setId)[0]['status']);
		$this->assertNull($set['approved_hash'], 'Konflikt unieważnia zatwierdzenie.');
		$this->assertSame(0, $this->executed());
	}

	public function testWorkerRestartNeedsReconciliationAndNeverResends(): void
	{
		$this->writeAccount('cc-restart');
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | Po restarcie']);
		$this->approve($setId);
		$this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), $this->confirmation($setId));

		$this->assertTrue($this->sets()->claim($setId, 'worker-1'));
		$this->assertNull($this->execution()->process($setId), 'Podwójne uruchomienie – zestaw pominięty.');

		$op = $this->ops($setId)[0];
		$this->sets()->updateOperation((int) $op['id'], ['status' => ChangeStatus::OP_SENDING]);
		$this->sets()->update($setId, ['deploy_started_at' => gmdate('Y-m-d H:i:s', time() - 40 * MINUTE_IN_SECONDS)]);
		$this->fake->set('campaign', $this->fake->name('campaign', '111'), 'name', 'Szukaj | Po restarcie');

		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::RECONCILE, $this->set($setId)['status']);
		$this->execution()->reconcile($this->project, $setId, $this->owner);
		$this->assertSame(ChangeStatus::APPLIED, $this->set($setId)['status']);
		$this->assertSame(0, $this->executed(), 'Wynik niepewny – bez automatycznego ponowienia; uzgodnienie tylko czyta konto.');
	}

	public function testDisablingWritesStopsQueuedAndRunningDeployments(): void
	{
		$this->writeAccount('cc-disable');
		$first = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | A']);
		$this->approve($first);
		$this->center()->requestDeploy($this->project, $first, $this->owner, wp_generate_uuid4(), $this->confirmation($first));
		putenv('PP_GOOGLE_ADS_WRITE_MODE=prepare_only');
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::READY, $this->set($first)['status']);
		$this->assertSame(0, $this->executed());
		putenv('PP_GOOGLE_ADS_WRITE_MODE=write_enabled');

		$this->center()->requestDeploy($this->project, $first, $this->owner, wp_generate_uuid4(), $this->confirmation($first));
		$this->assertSame(1, $this->sets()->stopQueued($this->project->id, (int) $this->connection['id'], $this->owner, 'Zapis wyłączony'));
		$this->assertSame(ChangeStatus::READY, $this->set($first)['status']);

		$set = $this->proposals()->newCampaign($this->project, $this->owner, ['name' => 'Dwie części', 'budget_micros' => 5_000_000, 'bidding' => 'MANUAL_CPC', 'languages' => ['pl'], 'eu_political' => 'no']);
		$this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | B'], $set);
		$this->approve($set);
		$connectionId = (int) $this->connection['id'];
		$this->fake->reject = function () use ($connectionId) {
			$this->db->update('google_ads_connections', ['write_enabled' => 0], ['id' => $connectionId]);

			return null;
		};
		$done = $this->queueAndWork($set);
		$this->fake->reject = null;

		$this->assertSame(1, $this->executed(), 'Po wyłączeniu zgody kolejne żądanie nie zostało wysłane.');
		$this->assertSame(ChangeStatus::PARTIAL, $done['status']);
	}
}
