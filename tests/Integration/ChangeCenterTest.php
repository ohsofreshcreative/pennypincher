<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\WriteMode;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\GoogleAdsMutateClient;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\WriteBlockedException;
use App\PennyPincher\Services\Changes\ChangeCenterService;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use App\PennyPincher\Services\GoogleAds\WriteGuard;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Change Center i silnik wykonawczy na atrapie konta: tryby, przepływ zatwierdzania, wdrożenie, weryfikacja,
 * idempotencja, dryf, niejednoznaczny wynik (timeout) i uzgadnianie, limity, częściowe niepowodzenie.
 */
class ChangeCenterTest extends ChangeCenterTestCase
{
	// --- Tryby ---------------------------------------------------------------------------------------

	public function testDefaultModeIsPrepareOnlyAndMutateClientRefusesWrites(): void
	{
		$this->account('mode');
		$guard = $this->make(WriteGuard::class);

		$this->assertSame(WriteMode::PrepareOnly, $guard->serverMode(), 'Domyślny tryb serwera to PREPARE_ONLY.');
		$this->assertSame(WriteMode::PrepareOnly, $guard->mode($this->connection));

		$client = $this->make(GoogleAdsMutateClient::class);
		$token = $this->make(ConnectionService::class)->accessToken($this->connection);
		$operation = [['campaignOperation' => ['update' => ['resourceName' => 'customers/1234567890/campaigns/111', 'status' => 'PAUSED'], 'updateMask' => 'status']]];

		$this->assertThrows(WriteBlockedException::class, fn () => $client->mutate($token, '1234567890', null, $operation, false, false));
		$this->assertThrows(WriteBlockedException::class, fn () => $client->mutate($token, '1234567890', null, $operation, true, false), 'PP_GOOGLE_ADS_API_VALIDATION');
		$this->assertSame(0, $this->mock->count('mutate'), 'Żadne żądanie mutate nie wyszło z aplikacji.');

		putenv('PP_GOOGLE_ADS_WRITE_MODE=tak_wlacz');
		$this->assertSame(WriteMode::ReadOnly, $guard->serverMode(), 'Nieznana wartość konfiguracji = tylko odczyt.');
		putenv('PP_GOOGLE_ADS_WRITE_MODE=read_only');
		$this->assertFalse($guard->mode($this->connection)->canPrepare());
	}

	public function testConnectionConsentAloneCannotEnableWrites(): void
	{
		$this->account('consent');
		// Zgoda zapisana w bazie (np. przez zmanipulowane żądanie) przy serwerze w trybie PREPARE_ONLY.
		$this->db->update('google_ads_connections', ['write_enabled' => 1], ['id' => (int) $this->connection['id']]);
		$this->make(\App\PennyPincher\Repositories\OrganizationRepository::class)->setExecutor($this->organization, $this->owner, true);
		$connection = $this->make(\App\PennyPincher\Repositories\GoogleAdsConnectionRepository::class)->currentForProject($this->project->id);
		$guard = $this->make(WriteGuard::class);

		$this->assertSame(WriteMode::PrepareOnly, $guard->mode($connection));
		$this->assertContains('wyłączony na serwerze', (string) $guard->executeBlocker($this->project, $connection, $this->owner));

		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['status' => 'PAUSED']);
		$this->approve($setId);
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status']);
		$this->assertThrows(WriteBlockedException::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'));
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status']);

		// Serwer pozwala, ale bez zgody dla konta – nadal zablokowane.
		putenv('PP_GOOGLE_ADS_WRITE_MODE=write_enabled');
		$this->db->update('google_ads_connections', ['write_enabled' => 0], ['id' => (int) $this->connection['id']]);
		$connection = $this->make(\App\PennyPincher\Repositories\GoogleAdsConnectionRepository::class)->currentForProject($this->project->id);
		$this->assertSame(WriteMode::PrepareOnly, $guard->mode($connection));
		$this->assertThrows(WriteBlockedException::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'));
		$this->assertSame(0, $this->fake->executedMutations());
	}

	public function testApiValidationRunsOnlyWithExplicitConsentAndNeverWrites(): void
	{
		$this->account('validation');
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | Marka 2026']);
		$message = $this->approve($setId);

		$this->assertContains('to nie jest potwierdzenie Google Ads', $message);
		$this->assertSame('skipped', $this->set($setId)['validation']['api']['status']);
		$this->assertSame(0, $this->mock->count('mutate'));

		$second = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | Marka B']);
		putenv('PP_GOOGLE_ADS_API_VALIDATION=1');
		$message = $this->approve($second);

		$this->assertContains('Google Ads API (bez zapisu)', $message);
		$this->assertSame('ok', $this->set($second)['validation']['api']['status']);
		$this->assertSame(1, $this->mock->count('mutate'));
		$this->assertTrue($this->fake->mutations[0]['validate_only']);
		$this->assertSame('Szukaj | Marka', $this->fake->value('campaign', $this->fake->name('campaign', '111'), 'name'), 'Walidacja niczego nie zmienia.');
		$this->assertSame(0, $this->fake->executedMutations());
	}

	public function testGoogleValidationRejectionReturnsSetToDraftWithoutWrites(): void
	{
		$this->account('valreject', true);
		$setId = $this->proposals()->keywordStatus($this->project, $this->owner, $this->keywordId(), 'PAUSED');
		$this->fake->reject = fn ($resource) => $resource === 'ad_group_criterion' ? 'Atrapa: walidacja odrzuciła operację.' : null;

		$message = $this->approve($setId);
		$this->assertContains('wrócił do szkicu', $message);
		$set = $this->set($setId);
		$this->assertSame(ChangeStatus::DRAFT, $set['status']);
		$this->assertNull($set['approved_hash']);
		$this->assertContains('walidacja odrzuciła', (string) $this->ops($setId)[0]['error_message']);
		$this->assertSame(0, $this->fake->sent());
	}

	// --- Przepływ i wdrożenie ----------------------------------------------------------------------------

	public function testBudgetChangeFlowFromDraftToVerifiedDeployment(): void
	{
		$this->account('budget', true);
		$setId = $this->proposals()->campaignBudget($this->project, $this->owner, $this->campaignId(), 70_000_000, false);
		$ops = $this->ops($setId);

		$this->assertCount(1, $ops);
		$this->assertSame('campaign_budget.update', $ops[0]['type']);
		$this->assertSame($this->budgetName, $ops[0]['resource_name']);
		$this->assertSame(50_000_000, (int) $ops[0]['before_state']['amount_micros'], 'Stan „przed” odczytany z Google Ads.');
		$this->assertSame('api', $ops[0]['before_source']);
		$this->assertSame(20_000_000, ChangeCenterService::budgetImpact($ops));
		$this->assertSame(ChangeStatus::DRAFT, $this->set($setId)['status']);

		$message = $this->approve($setId);
		$set = $this->set($setId);
		$this->assertSame(ChangeStatus::READY, $set['status']);
		$this->assertContains('Nic nie zostało jeszcze zmienione', $message);
		$this->assertSame(20_000_000, (int) $set['budget_impact_micros']);
		$this->assertSame('50000000', $this->fake->value('campaign_budget', $this->budgetName, 'amount_micros'), 'Zatwierdzenie nie wdraża.');

		$this->assertSame('high', $this->set($setId)['risk_level'], 'Zwiększenie budżetu to zmiana wysokiego ryzyka.');
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'), ChangeCenterService::CONFIRM_WORD);
		$queued = $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), ChangeCenterService::CONFIRM_WORD);
		$this->assertContains('To jeszcze nie jest wdrożenie', $queued);
		$this->assertSame(ChangeStatus::DEPLOYING, $this->set($setId)['status']);
		$this->assertSame(0, $this->fake->executedMutations(), 'Kontroler/serwis nie wysyła mutacji – tylko worker.');

		$result = $this->execution()->work(30);
		$this->assertSame(1, $result['processed']);
		$set = $this->set($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertNotNull($set['verified_at']);
		$this->assertSame(70_000_000, (int) $this->fake->value('campaign_budget', $this->budgetName, 'amount_micros'));
		$op = $this->ops($setId)[0];
		$this->assertSame(ChangeStatus::OP_VERIFIED, $op['status']);
		$this->assertNotNull($op['applied_at']);
		$this->assertSame(1, $this->fake->executedMutations());

		$events = array_column($this->sets()->events($this->project->id, $setId), 'event');
		foreach (['created', 'operation_added', 'state_read', 'submitted', 'approved', 'validated', 'deploy_requested', 'deployed'] as $event) {
			$this->assertTrue(in_array($event, $events, true), "Brak zdarzenia {$event} w historii.");
		}

		// Historia bez sekretów.
		$serialized = wp_json_encode([$this->sets()->events($this->project->id, $setId), $this->sets()->attempts($this->project->id, $setId), $this->ops($setId)]);
		$this->assertFalse(str_contains($serialized, 'ya29.'), 'Token dostępu w historii.');
		$this->assertFalse(str_contains($serialized, '1//'), 'Token odświeżania w historii.');

		// Ponowny przebieg workera nic nie wysyła.
		$this->execution()->work(30);
		$this->assertSame(1, $this->fake->executedMutations());
	}

	public function testDoubleClickAndSecondWorkerDoNotSendTwice(): void
	{
		$this->account('double', true);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['status' => 'PAUSED']);
		$this->approve($setId);
		$key = wp_generate_uuid4();

		$this->center()->requestDeploy($this->project, $setId, $this->owner, $key, 'tak');
		$again = $this->center()->requestDeploy($this->project, $setId, $this->owner, $key, 'tak');
		$this->assertContains('już w kolejce', $again);
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'), 'Gotowy do wdrożenia');

		// Drugi worker przejął zestaw – ten proces go pomija.
		$this->assertTrue($this->sets()->claim($setId, wp_generate_uuid4()));
		$this->assertNull($this->execution()->process($setId));
		$this->assertSame(0, $this->fake->executedMutations());

		$this->sets()->release($setId);
		$this->assertSame(ChangeStatus::APPLIED, $this->execution()->process($setId));
		$this->assertNull($this->execution()->process($setId), 'Zakończony zestaw nie jest przetwarzany ponownie.');
		$this->assertSame(1, $this->fake->executedMutations());
		$this->assertSame('PAUSED', $this->fake->value('campaign', $this->fake->name('campaign', '111'), 'status'));
	}

	public function testDriftDetectedBeforeDeployStopsWithoutMutation(): void
	{
		$this->account('drift', true);
		$setId = $this->proposals()->campaignBudget($this->project, $this->owner, $this->campaignId(), 70_000_000, false);
		$this->approve($setId);
		$this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), $this->confirmation($setId));

		// Ktoś zmienia budżet w interfejsie Google Ads między zatwierdzeniem a wdrożeniem.
		$this->fake->set('campaign_budget', $this->budgetName, 'amount_micros', '60000000');
		$this->execution()->work(30);

		$set = $this->set($setId);
		$this->assertSame(ChangeStatus::REVIEW, $set['status'], 'Konflikt wraca do przeglądu.');
		$this->assertNull($set['approved_hash'], 'Zatwierdzenie unieważnione.');
		$this->assertSame(ChangeStatus::OP_CONFLICT, $this->ops($setId)[0]['status']);
		$this->assertSame(0, $this->fake->executedMutations());
		$this->assertSame('60000000', $this->fake->value('campaign_budget', $this->budgetName, 'amount_micros'));
	}

	public function testChangedOperationsAfterApprovalAreNotDeployed(): void
	{
		$this->account('hash', true);
		$setId = $this->proposals()->campaignBudget($this->project, $this->owner, $this->campaignId(), 70_000_000, false);
		$this->approve($setId);
		$op = $this->ops($setId)[0];
		// Podmiana treści operacji z pominięciem aplikacji (np. bezpośrednio w bazie).
		$this->sets()->updateOperation((int) $op['id'], ['payload' => ['amount_micros' => 900_000_000]]);

		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'), 'zatwierdz');
		$this->assertSame(0, $this->fake->executedMutations());

		// Nawet gdyby zestaw trafił do kolejki – worker sprawdza odcisk zatwierdzonej wersji.
		$this->sets()->transition($setId, [ChangeStatus::READY], ChangeStatus::DEPLOYING, ['deploy_requested_by' => $this->owner, 'deploy_requested_at' => $this->db->now()]);
		$this->execution()->work(30);
		$this->assertSame(0, $this->fake->executedMutations());
		$this->assertTrue(in_array($this->set($setId)['status'], [ChangeStatus::FAILED, ChangeStatus::REVIEW, ChangeStatus::DRAFT], true), $this->set($setId)['status']);
	}

	public function testTimeoutAfterGoogleAppliedNeedsReconciliationAndIsNeverResent(): void
	{
		$this->account('timeout', true);
		$setId = $this->proposals()->campaignBudget($this->project, $this->owner, $this->campaignId(), 70_000_000, false);
		$this->fake->failNext = ['mode' => 'timeout', 'apply' => true];
		$set = $this->deploy($setId);

		$this->assertSame(ChangeStatus::RECONCILE, $set['status']);
		$this->assertSame(ChangeStatus::OP_RECONCILE, $this->ops($setId)[0]['status']);
		$this->assertSame(1, $this->fake->sent());

		$this->execution()->work(30);
		$this->assertSame(1, $this->fake->sent(), 'Brak automatycznego ponowienia po niejednoznacznym wyniku.');
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'));

		$this->execution()->reconcile($this->project, $setId, $this->owner);
		$set = $this->set($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertTrue(in_array($this->ops($setId)[0]['status'], ChangeStatus::OP_DONE, true));
		$this->assertSame(1, $this->fake->sent(), 'Uzgodnienie tylko czyta stan.');
	}

	public function testTimeoutBeforeGoogleAppliedCanBeConsciouslyRedeployed(): void
	{
		$this->account('timeout2', true);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['status' => 'PAUSED']);
		$this->fake->failNext = ['mode' => 'http500', 'apply' => false];
		$this->assertSame(ChangeStatus::RECONCILE, $this->deploy($setId)['status']);

		$this->execution()->reconcile($this->project, $setId, $this->owner);
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status'], 'Operacja nie dotarła – można świadomie wdrożyć ponownie.');
		$this->assertSame('ENABLED', $this->fake->value('campaign', $this->fake->name('campaign', '111'), 'status'));

		$this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak');
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::APPLIED, $this->set($setId)['status']);
		$this->assertSame('PAUSED', $this->fake->value('campaign', $this->fake->name('campaign', '111'), 'status'));
		$this->assertSame(1, $this->fake->executedMutations());
	}

	public function testQuotaErrorIsRetriedLaterWithoutDuplicates(): void
	{
		$this->account('quota', true);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['status' => 'PAUSED']);
		$this->fake->failNext = ['mode' => 'quota', 'apply' => false];
		$set = $this->deploy($setId);

		$this->assertSame(ChangeStatus::DEPLOYING, $set['status'], 'Limit zapytań – zestaw czeka w kolejce.');
		$this->assertNotNull($set['next_attempt_at']);
		$this->assertSame(ChangeStatus::OP_PENDING, $this->ops($setId)[0]['status']);
		$this->execution()->work(30);
		$this->assertSame(1, $this->fake->sent(), 'Przed upływem przerwy nie ma ponowienia.');

		$this->sets()->update($setId, ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::APPLIED, $this->set($setId)['status']);
		$this->assertSame(1, $this->fake->executedMutations());
	}

	public function testStaleWorkerLeadsToReconciliationNotRetry(): void
	{
		$this->account('stale', true);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['status' => 'PAUSED']);
		$this->approve($setId);
		$this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak');
		// Worker przejął zestaw i „umarł” po wysłaniu operacji.
		$this->sets()->claim($setId, wp_generate_uuid4());
		$this->sets()->update($setId, ['deploy_started_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
		$this->sets()->updateOperation((int) $this->ops($setId)[0]['id'], ['status' => ChangeStatus::OP_SENDING]);

		$result = $this->execution()->work(30);
		$this->assertSame(1, $result['stale']);
		$this->assertSame(ChangeStatus::RECONCILE, $this->set($setId)['status']);
		$this->assertSame(0, $this->fake->sent());
	}

	public function testPartialFailureRecordsResultPerOperationAndRetryDraftContainsOnlyFailures(): void
	{
		$this->account('partial', true);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['name' => 'Szukaj | Marka – nowa']);
		$this->proposals()->keywordStatus($this->project, $this->owner, $this->keywordId(), 'PAUSED', $setId);
		$this->approve($setId);
		$this->fake->reject = fn ($resource) => $resource === 'ad_group_criterion' ? 'Atrapa: odrzucono słowo.' : null;

		$set = $this->queueAndWork($setId);
		$this->assertSame(ChangeStatus::PARTIAL, $set['status']);
		$statuses = array_column($this->ops($setId), 'status', 'type');
		$this->assertTrue(in_array($statuses['campaign.update'], ChangeStatus::OP_DONE, true));
		$this->assertSame(ChangeStatus::OP_FAILED, $statuses['ad_group_criterion.update']);
		$failed = array_values(array_filter($this->ops($setId), fn ($op) => $op['status'] === ChangeStatus::OP_FAILED))[0];
		$this->assertContains('odrzucono słowo', (string) $failed['error_message']);
		$this->assertSame('ENABLED', $this->fake->value('ad_group_criterion', $this->fake->name('ad_group_criterion', '222~333'), 'status'));

		$retry = $this->center()->retryFailed($this->project, $setId, $this->owner);
		$retryOps = $this->ops($retry);
		$this->assertCount(1, $retryOps);
		$this->assertSame('ad_group_criterion.update', $retryOps[0]['type']);
		$this->assertSame(ChangeStatus::DRAFT, $this->set($retry)['status'], 'Ponowienie to nowy szkic – wymaga zatwierdzenia.');
	}

	public function testMatchTypeChangeIsAtomicReplacement(): void
	{
		$this->account('match', true);
		$setId = $this->proposals()->keywordReplaceMatch($this->project, $this->owner, $this->keywordId(), 'EXACT');
		$ops = $this->ops($setId);

		$this->assertSame(['ad_group_criterion.create', 'ad_group_criterion.remove'], array_column($ops, 'type'), 'Zmiana dopasowania = nowe słowo + usunięcie starego.');
		$this->assertNotNull($ops[0]['atomic_group']);
		$this->assertSame($ops[0]['atomic_group'], $ops[1]['atomic_group']);

		// Google odrzuca nowe słowo – stare nie może zostać usunięte.
		$this->approve($setId);
		$this->fake->reject = fn ($resource, $action) => $resource === 'ad_group_criterion' && $action === 'create' ? 'Atrapa: niedozwolone słowo.' : null;
		$set = $this->queueAndWork($setId);
		$this->assertSame(ChangeStatus::FAILED, $set['status']);
		$this->assertSame('ENABLED', $this->fake->value('ad_group_criterion', $this->fake->name('ad_group_criterion', '222~333'), 'status'));
		$this->assertCount(1, $this->fake->all('ad_group_criterion'));
	}

	public function testNewCampaignIsCreatedPausedWithOwnBudgetInOneRequest(): void
	{
		$this->account('newcamp', true);
		$setId = $this->proposals()->newCampaign($this->project, $this->owner, [
			'name' => 'Szukaj | Sofy', 'budget_micros' => 30_000_000, 'bidding' => 'MANUAL_CPC', 'geo_ids' => [2616], 'languages' => ['pl'], 'eu_political' => 'no',
		]);
		$types = array_column($this->ops($setId), 'type');
		$this->assertSame('campaign_budget.create', $types[0]);
		$this->assertSame('campaign.create', $types[1]);

		$set = $this->deploy($setId);
		$this->assertSame(ChangeStatus::APPLIED, $set['status'], (string) $set['last_error']);
		$this->assertSame(1, $this->fake->executedMutations(), 'Budżet, kampania i kierowanie w jednym żądaniu (tymczasowe ID).');
		$created = array_values(array_filter($this->fake->all('campaign'), fn ($c) => $c['campaign.name'] === 'Szukaj | Sofy'))[0];
		$this->assertSame('PAUSED', $created['campaign.status'], 'Nowa kampania zawsze wstrzymana.');
		$budget = $this->fake->get('campaign_budget', $created['campaign.campaign_budget']);
		$this->assertFalse($budget['campaign_budget.explicitly_shared']);
		$this->assertSame('30000000', $budget['campaign_budget.amount_micros']);
		$criteria = array_filter($this->fake->all('campaign_criterion'), fn ($c) => $c['campaign_criterion.campaign'] === $this->fake->name('campaign', $created['campaign.id']));
		$this->assertCount(2, $criteria, 'Lokalizacja i język.');
	}

	// --- Uprawnienia i izolacja ----------------------------------------------------------------------

	public function testRolesSeparatePreparingApprovingAndExecuting(): void
	{
		$this->account('roles', true);
		[$memberId, $memberProject] = $this->member('roles-member', Role::Member);
		[$adminId, $adminProject] = $this->member('roles-admin', Role::Admin);

		$setId = $this->proposals()->campaignUpdate($memberProject, $memberId, $this->campaignId(), ['status' => 'PAUSED']);
		$this->center()->refreshState($memberProject, $setId, $memberId);
		$this->center()->submit($memberProject, $setId, $memberId);
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->approve($memberProject, $setId, $memberId, false, false), 'administrator');

		$this->center()->approve($adminProject, $setId, $adminId, false, false);
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status']);

		// Członek i administrator bez uprawnienia „wykonawca” nie mogą wdrożyć.
		$this->assertThrows(\Throwable::class, fn () => $this->center()->requestDeploy($memberProject, $setId, $memberId, wp_generate_uuid4(), 'tak'));
		$this->assertThrows(WriteBlockedException::class, fn () => $this->center()->requestDeploy($adminProject, $setId, $adminId, wp_generate_uuid4(), 'tak'), 'wykonawca');

		$this->make(\App\PennyPincher\Repositories\OrganizationRepository::class)->setExecutor($this->organization, $memberId, true);
		$this->assertFalse($this->make(\App\PennyPincher\Repositories\OrganizationRepository::class)->isExecutor($this->organization, $memberId), 'Członek nie może zostać wykonawcą.');

		$this->make(\App\PennyPincher\Repositories\OrganizationRepository::class)->setExecutor($this->organization, $adminId, true);
		$this->center()->requestDeploy($this->projectFor($adminId), $setId, $adminId, wp_generate_uuid4(), 'tak');
		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::APPLIED, $this->set($setId)['status']);
		$this->assertSame($adminId, (int) $this->set($setId)['deploy_requested_by']);
	}

	public function testOtherOrganizationCannotSeeOrActOnChangeSets(): void
	{
		$this->account('iso', true);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['status' => 'PAUSED']);

		$stranger = $this->user('iso-stranger');
		$other = $this->organizationWithProject($stranger, 'Obca organizacja')['project'];

		$this->assertNull($this->sets()->find($other->id, $setId));
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->find($other, $setId), 'Nie znaleziono');
		$this->assertThrows(\Throwable::class, fn () => $this->center()->submit($other, $setId, $stranger));
		$this->assertThrows(\Throwable::class, fn () => $this->proposals()->campaignUpdate($other, $stranger, $this->campaignId(), ['status' => 'PAUSED']), 'kampan');
		$this->assertSame(ChangeStatus::DRAFT, $this->set($setId)['status']);
	}

	public function testChangeSetIsBoundToTheAccountItWasPreparedFor(): void
	{
		$this->account('bound', true);
		$setId = $this->proposals()->campaignUpdate($this->project, $this->owner, $this->campaignId(), ['status' => 'PAUSED']);
		$this->approve($setId);

		// Projekt przełączony na inne konto po zatwierdzeniu.
		$this->db->update('google_ads_connections', ['customer_id' => '5555555555'], ['id' => (int) $this->connection['id']]);
		$this->assertThrows(\Throwable::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'));
		$this->execution()->work(30);
		$this->assertSame(0, $this->fake->sent());
	}

	public function testHighRiskRequiresExtraConfirmations(): void
	{
		$this->account('risk', true);
		$setId = $this->proposals()->campaignBidding($this->project, $this->owner, $this->campaignId(), 'MANUAL_CPC', null, null);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$this->center()->submit($this->project, $setId, $this->owner);

		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->approve($this->project, $setId, $this->owner, false, true), 'wysokiego ryzyka');
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->approve($this->project, $setId, $this->owner, true, false), 'Smart Bidding');
		$risk = $this->set($setId)['risk_level'];
		$this->center()->approve($this->project, $setId, $this->owner, true, true);

		if ($risk === 'high' || $this->set($setId)['risk_level'] === 'high') {
			$this->assertThrows(ValidationFailed::class, fn () => $this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak'), ChangeCenterService::CONFIRM_WORD);
			$this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'wdróż');
		} else {
			$this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), 'tak');
		}

		$this->execution()->work(30);
		$this->assertSame(ChangeStatus::APPLIED, $this->set($setId)['status']);
		$this->assertSame('MANUAL_CPC', $this->fake->value('campaign', $this->fake->name('campaign', '111'), 'bidding_strategy_type'));
	}

	public function testRollbackIsANewDraftWithInverseOperation(): void
	{
		$this->account('rollback', true);
		$setId = $this->proposals()->campaignBudget($this->project, $this->owner, $this->campaignId(), 70_000_000, false);
		$this->deploy($setId);
		$rollback = $this->center()->prepareRollback($this->project, $setId, $this->owner);

		$set = $this->set($rollback);
		$this->assertSame(ChangeStatus::DRAFT, $set['status']);
		$this->assertSame('rollback', $set['source_type']);
		$this->assertSame($setId, (int) $set['rollback_of']);
		$op = $this->ops($rollback)[0];
		$this->assertSame('campaign_budget.update', $op['type']);
		$this->assertSame(50_000_000, (int) $op['payload']['amount_micros']);
		$this->assertSame(70_000_000, (int) $this->fake->value('campaign_budget', $this->budgetName, 'amount_micros'), 'Przygotowanie cofnięcia niczego nie zmienia.');
	}

	public function testReadStateMarksMissingResourceAndReportsReadFailuresReadably(): void
	{
		$this->account('readstate', true);
		$setId = $this->proposals()->keywordStatus($this->project, $this->owner, $this->keywordId(), 'PAUSED');
		$validation = $this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId));
		$this->assertTrue($validation['ok'], 'Brak odczytu stanu nie jest błędem walidacji – osobna kategoria.');

		// Słowo usunięte w Google Ads poza aplikacją: odczyt się udaje, ale walidacja blokuje operację.
		unset($this->fake->store['ad_group_criterion'][$this->fake->name('ad_group_criterion', '222~333')]);
		$this->assertSame(1, $this->center()->refreshState($this->project, $setId, $this->owner));
		$op = $this->ops($setId)[0];
		$this->assertSame('api', $op['before_source']);
		$this->assertNull($op['before_state']);
		$this->assertTrue($op['current_state']['context']['missing']);
		$this->assertTrue($op['verified_at'] === null && $op['status'] === ChangeStatus::OP_PENDING, 'Odczyt nie oznacza operacji jako zweryfikowanej.');
		$validation = $this->center()->validateLocally($this->project, $this->set($setId), [$op]);
		$this->assertFalse($validation['ok']);
		$this->assertContains('nie zwrócił tego zasobu', implode(' ', $validation['errors'][$op['id']]));

		// Błąd API przy odczycie: czytelny komunikat walidacji (nie wyjątek HTTP), próba zapisana w historii.
		$this->mock->on('ad_group_criterion', [403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']]]);
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->refreshState($this->project, $setId, $this->owner), 'Nie udało się odczytać stanu konta Google Ads');
		$this->assertSame('error', $this->sets()->attempts($this->project->id, $setId)[0]['outcome']);
		$this->assertSame(0, $this->fake->sent());
	}

	public function testConfirmationsAreSeparatedFromConfigurationErrors(): void
	{
		$this->account('confirm');
		$setId = $this->proposals()->conversionUpdate($this->project, $this->owner, $this->conversionId('901'), ['primary_for_goal' => false, 'confirm_goal_change' => false]);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$op = $this->ops($setId)[0];
		$validation = $this->center()->validateLocally($this->project, $this->set($setId), [$op]);

		$this->assertFalse($validation['ok']);
		$this->assertSame($validation['errors'][$op['id']], $validation['confirmations'][$op['id']], 'Jedyny problem to brak potwierdzenia – nie błąd konfiguracji.');
		$this->center()->updateOperation($this->project, $setId, (int) $op['id'], ['primary_for_goal' => false, 'confirm_goal_change' => true], $this->owner);
		$this->assertTrue($this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId))['ok']);
	}
}
