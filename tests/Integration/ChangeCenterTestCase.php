<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\Changes\ChangeCenterService;
use App\PennyPincher\Services\Changes\ChangeProposalService;
use App\PennyPincher\Services\Changes\ExecutionService;
use App\PennyPincher\Services\GoogleAds\SyncService;
use Tests\Support\FakeGoogleAdsAccount;

require_once __DIR__ . '/GoogleAdsTestCase.php';
require_once dirname(__DIR__) . '/Support/FakeGoogleAdsAccount.php';

/**
 * Wspólne przygotowanie testów Change Center: projekt połączony z atrapą konta (synchronizacja z atrapy API,
 * więc kampanie i słowa mają identyfikatory Google Ads), atrapa konta w pamięci do odczytu stanu i mutacji.
 *
 * Wszystkie operacje zapisu trafiają wyłącznie do atrapy – żadne żądanie nie wychodzi do sieci.
 */
abstract class ChangeCenterTestCase extends GoogleAdsTestCase
{
	protected FakeGoogleAdsAccount $fake;

	protected Project $project;

	protected array $connection;

	protected int $owner;

	protected int $organization;

	protected string $budgetName;

	/**
	 * Projekt z kontem: kampania 111 „Szukaj | Marka” (budżet 50 zł, własny), grupa 222 „Marka”, słowo 333 „sklep meble” (do wyrażenia),
	 * wykluczenie kampanii „darmowe”, konwersje 901 (główna, wyświetlenie strony) i 902 (formularz, dodatkowa).
	 */
	protected function account(string $name, bool $write = false): void
	{
		$this->owner = $this->user($name);
		$created = $this->organizationWithProject($this->owner, 'Zmiany ' . $name);
		$this->organization = $created['organization'];
		$this->mock->withDefaultAccount();
		$this->connection = $this->connect($created['project'], $this->owner);
		$sync = $this->make(SyncService::class);
		$run = $sync->process($sync->enqueue($this->connection, 'backfill'), time() + 300);
		$this->assertSame('completed', $run['status'], (string) $run['last_error']);

		$this->fake = (new FakeGoogleAdsAccount())->install($this->mock);
		$this->budgetName = $this->fake->budget('444', 50_000_000);
		$this->fake->campaign('111', 'Szukaj | Marka', $this->budgetName);
		$this->fake->adGroup('222', '111', 'Marka');
		$this->fake->keyword('222', '333', 'sklep meble', 'PHRASE');
		$this->fake->campaignNegative('111', '501', 'darmowe', 'PHRASE');
		$this->fake->conversionAction('901', 'Wyświetlenie strony kontakt', ['category' => 'PAGE_VIEW']);
		$this->fake->conversionAction('902', 'Formularz', ['primary_for_goal' => false, 'counting_type' => 'MANY_PER_CLICK']);

		if ($write) {
			$this->enableWrites();
		}

		$this->project = $this->projectFor($this->owner);
	}

	/**
	 * Kampania testowa 111 wstrzymana w atrapie – zmiany budżetu i słów są wtedy dozwolone przy blokadzie emisji
	 * (domyślna konfiguracja), więc test sprawdza przepływ w trybie, w jakim działa konto Development.
	 */
	protected function pauseTestCampaign(): void
	{
		$this->fake->set('campaign', $this->fake->name('campaign', '111'), 'status', 'PAUSED');
	}

	/**
	 * Tryb produkcyjny z dozwoloną emisją (PP_GOOGLE_ADS_ALLOW_SERVING=1) – tylko dla testów, które sprawdzają
	 * świadome włączanie zasobów; domyślnie blokada emisji jest włączona.
	 */
	protected function allowServing(): void
	{
		putenv('PP_GOOGLE_ADS_ALLOW_SERVING=1');
	}

	/**
	 * Zapis na atrapie: tryb serwera write_enabled (zmienna środowiskowa tylko na czas testu), zgoda dla konta, wykonawca.
	 */
	protected function enableWrites(?int $executor = null): void
	{
		putenv('PP_GOOGLE_ADS_WRITE_MODE=write_enabled');
		// Atrapa konta (123-456-7890) na allowliście zapisu – jak konto „Penny Pincher – Development”.
		putenv('PP_GOOGLE_ADS_WRITE_CUSTOMERS=' . \Tests\Support\GoogleAdsMock::CUSTOMER);
		$this->db->update('google_ads_connections', ['write_enabled' => 1, 'write_enabled_by' => $this->owner, 'write_enabled_at' => $this->db->now()], ['id' => (int) $this->connection['id']]);
		$this->make(OrganizationRepository::class)->setExecutor($this->organization, $executor ?? $this->owner, true);
		$this->connection = $this->make(\App\PennyPincher\Repositories\GoogleAdsConnectionRepository::class)->currentForProject($this->connection['project_id']);
	}

	protected function projectFor(int $userId): Project
	{
		return $this->make(ProjectRepository::class)->findForUser($userId, (int) $this->connection['project_id']);
	}

	protected function member(string $name, Role $role): array
	{
		$user = $this->user($name);
		$this->addMember($this->organization, $user, $role);

		return [$user, $this->projectFor($user)];
	}

	protected function campaignId(string $external = '111'): int
	{
		return (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? AND external_id = ?', [$this->project->id, $external]);
	}

	protected function adGroupId(string $external = '222'): int
	{
		return (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('ad_groups') . ' WHERE project_id = ? AND external_id = ?', [$this->project->id, $external]);
	}

	protected function keywordId(string $external = '333'): int
	{
		return (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('keywords') . ' WHERE project_id = ? AND external_id = ?', [$this->project->id, $external]);
	}

	protected function conversionId(string $external): int
	{
		return (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('conversion_actions') . ' WHERE project_id = ? AND external_id = ?', [$this->project->id, $external]);
	}

	protected function center(): ChangeCenterService
	{
		return $this->make(ChangeCenterService::class);
	}

	protected function proposals(): ChangeProposalService
	{
		return $this->make(ChangeProposalService::class);
	}

	protected function execution(): ExecutionService
	{
		return $this->make(ExecutionService::class);
	}

	protected function sets(): ChangeSetRepository
	{
		return $this->make(ChangeSetRepository::class);
	}

	protected function set(int $id): array
	{
		return $this->sets()->find($this->project->id, $id);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function ops(int $setId): array
	{
		return $this->sets()->operations($setId);
	}

	/**
	 * Szkic → odczyt stanu → przegląd → zatwierdzenie (właściciel). Zwraca komunikat zatwierdzenia.
	 */
	protected function approve(int $setId, ?bool $highRisk = null, ?bool $bidding = null): string
	{
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$this->center()->submit($this->project, $setId, $this->owner);
		$ops = $this->ops($setId);
		// Domyślnie potwierdzamy dokładnie to, czego wymaga zestaw (testy potwierdzeń przekazują wartości jawnie).
		$highRisk ??= \App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus::maxRisk(array_column($ops, 'risk')) === 'high';
		$bidding ??= array_filter($ops, fn ($op) => \App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog::affectsBidding($op)) !== [];

		return $this->center()->approve($this->project, $setId, $this->owner, $highRisk, $bidding);
	}

	/**
	 * Zatwierdzenie + zlecenie wdrożenia (wykonawca = właściciel) + przebieg workera.
	 */
	protected function deploy(int $setId, ?bool $highRisk = null, ?bool $bidding = null): array
	{
		$this->approve($setId, $highRisk, $bidding);

		return $this->queueAndWork($setId);
	}

	/**
	 * Zlecenie wdrożenia zatwierdzonego zestawu (z wymaganym potwierdzeniem) i przebieg workera.
	 */
	protected function queueAndWork(int $setId): array
	{
		$this->center()->requestDeploy($this->project, $setId, $this->owner, wp_generate_uuid4(), $this->confirmation($setId));
		$this->execution()->work(30);

		return $this->set($setId);
	}

	protected function confirmation(int $setId): string
	{
		return $this->set($setId)['risk_level'] === 'high' ? ChangeCenterService::CONFIRM_WORD : 'tak';
	}
}
