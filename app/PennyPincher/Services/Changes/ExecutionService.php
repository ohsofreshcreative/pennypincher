<?php

namespace App\PennyPincher\Services\Changes;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\GoogleAds\Changes\StateQueries;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\AmbiguousMutateException;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\GoogleAdsMutateClient;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\WriteBlockedException;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use App\PennyPincher\Services\GoogleAds\StateReader;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use Throwable;

/**
 * Silnik wykonywania zmian (worker `pp:sync:work` / `pp:changes:work`). Kontrolery i widoki nigdy nie wysyłają
 * mutacji – tylko kolejkują zatwierdzone zestawy.
 *
 * Przebieg zestawu: przejęcie (jeden worker) → blokada konta → ponowna kontrola trybu, wykonawcy, konta i odcisku
 * zatwierdzenia → ponowny odczyt stanu (zmiana poza Penny Pincher = konflikt i powrót do przeglądu) → żądania
 * googleAds:mutate (grupy atomowe / partial failure) → zapis wyniku każdej operacji → weryfikacja odczytem →
 * status końcowy. Brak odpowiedzi po wysłaniu = „Wymaga uzgodnienia” (bez automatycznego ponowienia).
 */
class ExecutionService
{
	public const MAX_QUOTA_RETRIES = 3;

	public function __construct(
		private Db $db,
		private ChangeSetRepository $sets,
		private ChangeCenterService $center,
		private GoogleAdsConnectionRepository $connections,
		private ProjectRepository $projects,
		private OrganizationRepository $organizations,
		private WriteGuard $guard,
		private StateReader $reader,
		private GoogleAdsMutateClient $mutate,
		private ConnectionService $connectionService,
		private RecommendationRepository $recommendations,
	) {
	}

	/**
	 * Praca workera: porzucone przebiegi → uzgodnienie, potem zestawy z kolejki.
	 *
	 * @return array{processed: int, stale: int}
	 */
	public function work(int $seconds = 60): array
	{
		$deadline = time() + max(5, $seconds);
		$stale = 0;

		foreach ($this->sets->stale() as $id) {
			$set = $this->sets->findForWorker($id);

			if ($set === null) {
				continue;
			}

			// Worker przerwany w trakcie (restart, limit czasu) – nie wiemy, co dotarło do Google. Bez ponawiania.
			foreach ($this->sets->operations($id) as $op) {
				if ($op['status'] === ChangeStatus::OP_SENDING) {
					$this->sets->updateOperation($op['id'], ['status' => ChangeStatus::OP_RECONCILE, 'error_message' => 'Przetwarzanie przerwane po wysłaniu – wynik nieznany.']);
				}
			}

			$this->sets->transition($id, [ChangeStatus::DEPLOYING], ChangeStatus::RECONCILE, ['worker_token' => null, 'last_error' => 'Wdrożenie przerwane (worker nie zakończył pracy) – wymagane uzgodnienie stanu.']);
			$this->sets->addEvent($id, (int) $set['project_id'], 'stale', null, ChangeStatus::DEPLOYING, ChangeStatus::RECONCILE, 'Przetwarzanie przerwane – sprawdź stan konta przed ponowieniem.');
			$stale++;
		}

		$processed = 0;

		foreach ($this->sets->due() as $id) {
			if (time() >= $deadline) {
				break;
			}

			if ($this->process($id) !== null) {
				$processed++;
			}
		}

		return ['processed' => $processed, 'stale' => $stale];
	}

	/**
	 * Przetworzenie jednego zestawu z kolejki. Zwraca status końcowy albo null, gdy zestaw przejął inny proces.
	 */
	public function process(int $setId): ?string
	{
		$token = wp_generate_uuid4();

		if (! $this->sets->claim($setId, $token)) {
			return null; // inny worker albo podwójne uruchomienie
		}

		$set = $this->sets->findForWorker($setId);
		$lock = 'pp_gads_write_' . preg_replace('/\D/', '', (string) $set['customer_id']);

		if ((int) $this->db->scalar('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
			$this->sets->update($setId, ['worker_token' => null, 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 60)]);

			return null;
		}

		try {
			return $this->execute($set);
		} catch (Throwable $e) {
			error_log('[Penny Pincher] Wdrożenie zestawu #' . $setId . ': ' . ChangeSetRepository::scrub($e->getMessage()));
			$this->stop($set, ChangeStatus::READY, 'Nieoczekiwany błąd przed wysłaniem zmian – nic nie zostało wysłane. Szczegóły w logu serwera.');

			return ChangeStatus::READY;
		} finally {
			$this->db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
		}
	}

	private function execute(array $set): string
	{
		$setId = (int) $set['id'];
		$projectId = (int) $set['project_id'];
		$connection = $this->connections->currentForProject($projectId);
		$requester = (int) $set['deploy_requested_by'];
		// Uprawnienia sprawdzamy ponownie w imieniu osoby, która zleciła wdrożenie (rola mogła się zmienić od kliknięcia).
		$project = $this->projects->findForUser($requester, $projectId);

		if ($project === null || $project->organizationId !== (int) $set['organization_id']) {
			return $this->stop($set, ChangeStatus::READY, 'Osoba, która zleciła wdrożenie, nie ma już dostępu do projektu – nic nie wysłano.');
		}

		$blocker = $this->guard->executeBlocker($project, $connection, $requester, $set);

		if ($blocker !== null) {
			return $this->stop($set, ChangeStatus::READY, 'Wdrożenie wstrzymane przed wysłaniem: ' . $blocker);
		}

		$operations = $this->sets->operations($setId);

		if ($set['approved_hash'] === null || (int) $set['approved_version'] !== (int) $set['version'] || ! hash_equals((string) $set['approved_hash'], $this->center->hash($set, $operations))) {
			$this->sets->update($setId, ['approved_hash' => null, 'approved_version' => null, 'approved_by' => null, 'approved_at' => null]);

			return $this->stop($set, ChangeStatus::REVIEW, 'Zestaw zmienił się po zatwierdzeniu – wymagany ponowny przegląd. Nic nie wysłano.');
		}

		$pending = array_values(array_filter($operations, fn ($op) => $op['status'] === ChangeStatus::OP_PENDING));

		// Ponowny odczyt stanu tuż przed wdrożeniem: zmiana poza Penny Pincher = zatrzymanie i ponowny przegląd.
		try {
			$conflicts = $this->conflicts($connection, $set, $pending);
		} catch (GoogleAdsApiException $e) {
			return $this->retryLater($set, $e, 'Nie udało się odczytać stanu konta przed wdrożeniem');
		}

		if ($conflicts !== []) {
			foreach ($conflicts as $opId => $message) {
				$this->sets->updateOperation($opId, ['status' => ChangeStatus::OP_CONFLICT, 'error_message' => $message]);
			}

			$this->sets->update($setId, ['approved_hash' => null, 'approved_version' => null, 'approved_by' => null, 'approved_at' => null]);

			return $this->stop($set, ChangeStatus::REVIEW, 'Stan zasobów w Google Ads zmienił się od przygotowania zmian (' . count($conflicts) . ' ' . ($conflicts === 1 ? 'operacja' : 'operacje') . ') – nic nie wysłano, wymagany ponowny przegląd.');
		}

		$applied = $this->appliedRefs($operations);
		$token = $this->connectionService->accessToken($connection);
		$ambiguous = false;

		foreach ($this->center->requests($set, $this->ready($pending, $operations), $applied) as $request) {
			$ids = array_map(fn ($op) => (int) $op['id'], $request['ops']);

			foreach ($request['ops'] as $op) {
				$this->sets->updateOperation((int) $op['id'], ['status' => ChangeStatus::OP_SENDING, 'attempts' => (int) $op['attempts'] + 1]);
			}

			try {
				$result = $this->mutate->mutate($token, (string) $set['customer_id'], $connection['login_customer_id'] ?? null, $request['operations'], false, $request['group'] === null);
			} catch (AmbiguousMutateException $e) {
				foreach ($ids as $id) {
					$this->sets->updateOperation($id, ['status' => ChangeStatus::OP_RECONCILE, 'error_message' => mb_substr($e->getMessage(), 0, 1000)]);
				}

				$this->sets->addAttempt($setId, $projectId, 'execute', 'ambiguous', $ids, ['customer_id' => $set['customer_id'], 'request_id' => $e->requestId, 'message' => $e->getMessage(), 'user_id' => $requester]);
				$ambiguous = true;

				// Kolejnych żądań nie wysyłamy – najpierw trzeba ustalić, co Google przyjął.
				foreach ($this->sets->operations($setId) as $left) {
					if ($left['status'] === ChangeStatus::OP_SENDING) {
						$this->sets->updateOperation($left['id'], ['status' => ChangeStatus::OP_PENDING]);
					}
				}

				break;
			} catch (WriteBlockedException $e) {
				foreach ($ids as $id) {
					$this->sets->updateOperation($id, ['status' => ChangeStatus::OP_PENDING]);
				}

				return $this->stop($set, ChangeStatus::READY, 'Wdrożenie wstrzymane: ' . $e->getMessage());
			} catch (GoogleAdsApiException $e) {
				// Całe żądanie odrzucone przed zapisem (autoryzacja, uprawnienia, limit) – nic nie zapisano.
				foreach ($ids as $id) {
					$this->sets->updateOperation($id, $e->kind === GoogleAdsApiException::QUOTA ? ['status' => ChangeStatus::OP_PENDING] : ['status' => ChangeStatus::OP_FAILED, 'error_code' => $e->googleCode ?? $e->kind, 'error_message' => mb_substr($e->userMessage(), 0, 1000)]);
				}

				$this->sets->addAttempt($setId, $projectId, 'execute', 'error', $ids, ['customer_id' => $set['customer_id'], 'http_status' => $e->httpStatus, 'request_id' => $e->requestId, 'error_code' => $e->googleCode ?? $e->kind, 'message' => $e->getMessage(), 'user_id' => $requester]);

				if ($e->kind === GoogleAdsApiException::QUOTA) {
					return $this->retryLater($set, $e, 'Limit zapytań Google Ads');
				}

				break;
			}

			foreach ($request['ops'] as $i => $op) {
				if ($result->ok($i)) {
					$this->sets->updateOperation((int) $op['id'], ['status' => ChangeStatus::OP_APPLIED, 'result_resource_name' => $result->resourceNames[$i] ?? $op['resource_name'], 'applied_at' => $this->db->now(), 'error_code' => null, 'error_message' => null]);

					if ($op['temp_ref'] !== null && isset($result->resourceNames[$i])) {
						$applied['ref:' . $op['temp_ref']] = $result->resourceNames[$i];
					}
				} else {
					$this->sets->updateOperation((int) $op['id'], ['status' => ChangeStatus::OP_FAILED, 'error_code' => $result->errors[$i]['code'] ?? 'ERROR', 'error_message' => mb_substr((string) ($result->errors[$i]['message'] ?? 'Operacja odrzucona.'), 0, 1000)]);
				}
			}

			$failedCount = count(array_filter(array_keys($request['ops']), fn ($i) => ! $result->ok($i)));
			$this->sets->addAttempt($setId, $projectId, 'execute', $failedCount === 0 ? 'ok' : ($failedCount === count($ids) ? 'error' : 'partial'), $ids, ['customer_id' => $set['customer_id'], 'request_id' => $result->requestId, 'message' => $result->requestError, 'user_id' => $requester]);

			// Zależne operacje w kolejnych żądaniach nie mogą działać, jeśli rodzic nie powstał.
			$this->skipOrphans($setId);
		}

		$this->skipOrphans($setId);

		// Operacje niewysłane po pewnym odrzuceniu wcześniejszego żądania – do ponownego przygotowania (bez wyniku nieznanego).
		if (! $ambiguous) {
			foreach ($this->sets->operations($setId) as $left) {
				if ($left['status'] === ChangeStatus::OP_PENDING) {
					$this->sets->updateOperation($left['id'], ['status' => ChangeStatus::OP_SKIPPED, 'error_message' => 'Nie wysłano – wcześniejsze żądanie zostało odrzucone albo zależność nie jest spełniona.']);
				}
			}
		}

		$this->links($set);
		$this->verify($connection, $set, $requester);

		return $this->finish($set, $requester);
	}

	/**
	 * Uzgodnienie stanu po niepewnym wyniku: odczyt z Google Ads decyduje, czy operacja została przyjęta.
	 * Nic nie jest wysyłane ponownie automatycznie – operacje nieprzyjęte wracają do „oczekuje” i wymagają ponownego,
	 * świadomego uruchomienia wdrożenia.
	 */
	public function reconcile(Project $project, int $setId, int $userId): string
	{
		$set = $this->center->find($project, $setId);
		$operations = $this->sets->operations($setId);
		$unknown = array_values(array_filter($operations, fn ($op) => in_array($op['status'], [ChangeStatus::OP_RECONCILE, ChangeStatus::OP_SENDING], true)));

		if ($unknown === [] || ! in_array($set['status'], [ChangeStatus::RECONCILE, ChangeStatus::PARTIAL, ChangeStatus::FAILED], true)) {
			throw new ValidationFailed(['reconcile' => 'Ten zestaw nie wymaga uzgodnienia.']);
		}

		$connection = $this->connections->currentForProject($project->id);

		if ($connection === null || (int) $connection['id'] !== (int) $set['connection_id']) {
			throw new ValidationFailed(['connection' => 'Brak aktywnego połączenia z kontem, na którym wdrażano zmiany.']);
		}

		$found = 0;

		try {
			// Grupy atomowe: Google zapisuje je w całości albo wcale – jeden rozpoznany zasób rozstrzyga całą grupę.
			$groups = [];

			foreach ($unknown as $op) {
				$groups[$op['atomic_group'] ?? 'op:' . $op['id']][] = $op;
			}

			foreach ($groups as $ops) {
				$decision = null;

				foreach ($ops as $op) {
					$match = $this->locate($connection, $set, $op);

					if ($match['state'] !== 'unknown') {
						$decision = $match;
						break;
					}
				}

				foreach ($ops as $op) {
					$own = count($ops) === 1 ? $decision : ($this->locate($connection, $set, $op) + []);
					$applied = ($decision['state'] ?? 'absent') === 'applied';
					$values = $applied
						? ['status' => ChangeStatus::OP_APPLIED, 'result_resource_name' => ($own['resource_name'] ?? null) ?: $op['resource_name'], 'applied_at' => $op['applied_at'] ?? $this->db->now(), 'error_message' => 'Uzgodniono: zmiana jest widoczna w Google Ads.']
						: (($decision['state'] ?? 'absent') === 'conflict'
							? ['status' => ChangeStatus::OP_CONFLICT, 'error_message' => 'Uzgodnienie: stan różni się i od stanu sprzed zmiany, i od oczekiwanego.']
							: ['status' => ChangeStatus::OP_PENDING, 'error_message' => 'Uzgodnienie: Google Ads nie przyjął tej operacji – można ją wdrożyć ponownie.']);
					$this->sets->updateOperation((int) $op['id'], $values);
					$found += $applied ? 1 : 0;
				}
			}
		} catch (GoogleAdsApiException $e) {
			$this->sets->addAttempt($setId, $project->id, 'reconcile', 'error', array_column($unknown, 'id'), ['customer_id' => $set['customer_id'], 'http_status' => $e->httpStatus, 'request_id' => $e->requestId, 'error_code' => $e->googleCode ?? $e->kind, 'message' => $e->getMessage(), 'user_id' => $userId]);

			throw new ValidationFailed(['google_ads' => 'Nie udało się odczytać stanu konta: ' . $e->userMessage()]);
		}

		$this->sets->addAttempt($setId, $project->id, 'reconcile', 'ok', array_column($unknown, 'id'), ['customer_id' => $set['customer_id'], 'user_id' => $userId, 'message' => "Przyjęte przez Google: {$found} z " . count($unknown) . '.']);
		$this->links($set);
		$this->verify($connection, $set, $userId);
		$after = $this->sets->operations($setId);

		if (array_filter($after, fn ($op) => $op['status'] === ChangeStatus::OP_PENDING) !== []) {
			$this->sets->update($setId, ['status' => ChangeStatus::READY, 'worker_token' => null, 'deploy_key' => null]);
			$this->sets->addEvent($setId, $project->id, 'reconciled', $userId, $set['status'], ChangeStatus::READY, 'Część operacji nie została przyjęta – można świadomie wdrożyć je ponownie (przyjęte nie zostaną wysłane drugi raz).');

			return 'Uzgodniono stan. Operacje, których Google Ads nie przyjął, czekają na ponowne, świadome wdrożenie.';
		}

		$status = $this->finish($set, $userId, 'reconciled');

		return 'Uzgodniono stan konta – status: ' . ChangeStatus::LABELS[$status] . '.';
	}

	/**
	 * Weryfikacja: odczyt stanu po zmianie i porównanie z oczekiwanym („przyjęte przez API” ≠ „widoczne w Google Ads”).
	 */
	public function verify(array $connection, array $set, ?int $userId): void
	{
		$verified = 0;
		$mismatch = 0;

		foreach ($this->sets->operations((int) $set['id']) as $op) {
			if ($op['status'] !== ChangeStatus::OP_APPLIED) {
				continue;
			}

			$name = (string) ($op['result_resource_name'] ?? $op['resource_name']);

			try {
				$state = $name !== '' ? $this->reader->read($connection, OperationCatalog::resource($op['type']), $name) : null;
			} catch (GoogleAdsApiException $e) {
				$this->sets->updateOperation($op['id'], ['verification' => ['error' => mb_substr($e->userMessage(), 0, 300), 'at' => $this->db->now()]]);
				continue;
			}

			$diff = OperationCatalog::compare(OperationCatalog::expected($op), $state);
			$this->sets->updateOperation($op['id'], [
				'status' => $diff === [] ? ChangeStatus::OP_VERIFIED : ChangeStatus::OP_MISMATCH,
				'verified_at' => $this->db->now(),
				'current_state' => ['after' => $state, 'read_at' => $this->db->now()] + (array) ($op['current_state'] ?? []),
				'verification' => ['diff' => $diff, 'at' => $this->db->now()],
			]);
			$diff === [] ? $verified++ : $mismatch++;
		}

		if ($verified + $mismatch > 0) {
			$this->sets->addAttempt((int) $set['id'], (int) $set['project_id'], 'verify', $mismatch === 0 ? 'ok' : 'partial', [], ['customer_id' => $set['customer_id'], 'user_id' => $userId, 'message' => "Zweryfikowano {$verified}, rozbieżności: {$mismatch}."]);
		}
	}

	// --- Pomocnicze -----------------------------------------------------------------------------

	/**
	 * @return array<int, string> id operacji => opis konfliktu
	 */
	private function conflicts(array $connection, array $set, array $pending): array
	{
		$conflicts = [];

		foreach ($pending as $op) {
			$type = $op['type'];

			if (OperationCatalog::action($type) === 'create') {
				// Ochrona przed ponownym utworzeniem (np. po awarii): taki zasób już istnieje.
				$located = $this->locate($connection, $set, $op);

				if ($located['state'] === 'applied') {
					$conflicts[$op['id']] = 'Taki zasób już istnieje w Google Ads – nie tworzymy duplikatu.';
				}

				continue;
			}

			$state = $this->reader->read($connection, OperationCatalog::resource($type), (string) $op['resource_name']);
			$before = (array) ($op['before_state'] ?? []);

			if ($state === null) {
				$conflicts[$op['id']] = 'Zasób nie istnieje już w Google Ads.';
				continue;
			}

			foreach (OperationCatalog::watched($op) as $field) {
				if (array_key_exists($field, $before) && ($before[$field] ?? null) != ($state[$field] ?? null)) {
					$conflicts[$op['id']] = sprintf('Pole „%s” zmieniono poza Penny Pincher (było: %s, jest: %s).', OperationCatalog::FIELD_LABELS[$field] ?? $field, self::show($before[$field] ?? null), self::show($state[$field] ?? null));
					break;
				}
			}

			$this->sets->updateOperation($op['id'], ['current_state' => ['pre_deploy' => $state, 'read_at' => $this->db->now()] + (array) ($op['current_state'] ?? [])]);
		}

		return $conflicts;
	}

	/**
	 * Ustalenie, czy zmiana jest widoczna w Google Ads: 'applied' (z resource name), 'absent' (stan sprzed zmiany),
	 * 'conflict' (inny stan) albo 'unknown' (nie da się rozstrzygnąć tą operacją, np. budżet utworzony w grupie).
	 *
	 * @return array{state: string, resource_name?: ?string}
	 */
	private function locate(array $connection, array $set, array $op): array
	{
		$type = $op['type'];
		$payload = $op['payload'];
		$expected = OperationCatalog::expected($op);
		$parent = fn (string $key) => isset($payload[$key]) && ! str_starts_with((string) $payload[$key], 'ref:') ? (string) $payload[$key] : null;

		if (OperationCatalog::action($type) !== 'create') {
			$state = $this->reader->read($connection, OperationCatalog::resource($type), (string) $op['resource_name']);

			if (OperationCatalog::compare($expected, $state) === []) {
				return ['state' => 'applied', 'resource_name' => $op['resource_name']];
			}

			$before = (array) ($op['before_state'] ?? []);
			$same = $state !== null && array_filter(OperationCatalog::watched($op), fn ($f) => array_key_exists($f, $before) && ($before[$f] ?? null) != ($state[$f] ?? null)) === [];

			return ['state' => $same ? 'absent' : 'conflict'];
		}

		$candidates = match ($type) {
			'campaign.create' => $this->reader->list($connection, 'campaign', StateQueries::campaign()),
			'ad_group.create' => $parent('campaign') !== null ? $this->reader->list($connection, 'ad_group', StateQueries::adGroups($parent('campaign'))) : null,
			'ad_group_criterion.create' => $parent('ad_group') !== null ? $this->reader->list($connection, 'ad_group_criterion', StateQueries::adGroupCriteria($parent('ad_group'))) : null,
			'campaign_criterion.create' => $parent('campaign') !== null ? $this->reader->list($connection, 'campaign_criterion', StateQueries::campaignCriteria($parent('campaign'))) : null,
			'ad_group_ad.create' => $parent('ad_group') !== null ? $this->reader->list($connection, 'ad_group_ad', StateQueries::adGroupAds($parent('ad_group'))) : null,
			'conversion_action.create' => $this->reader->list($connection, 'conversion_action', StateQueries::conversionAction()),
			default => null,
		};

		if ($candidates === null) {
			return ['state' => 'unknown'];
		}

		$identity = array_diff_key($expected, array_flip(['status']));

		foreach ($candidates as $candidate) {
			if (OperationCatalog::compare($identity, $candidate) === []) {
				return ['state' => 'applied', 'resource_name' => $candidate['resource_name']];
			}
		}

		return ['state' => 'absent'];
	}

	/**
	 * Operacje gotowe do wysłania: zależności (depends_on) spełnione w tym lub wcześniejszym przebiegu.
	 */
	private function ready(array $pending, array $all): array
	{
		$byUuid = array_column($all, null, 'op_uuid');
		$pendingUuids = array_column($pending, 'op_uuid');

		return array_values(array_filter($pending, function ($op) use ($byUuid, $pendingUuids) {
			foreach ($op['depends_on'] as $dependency) {
				$status = $byUuid[$dependency]['status'] ?? null;

				// Zależność w tej samej grupie atomowej jest wysyłana razem; inaczej musi być już przyjęta.
				if (! in_array($status, ChangeStatus::OP_DONE, true) && ! (in_array($dependency, $pendingUuids, true) && ($byUuid[$dependency]['atomic_group'] ?? null) !== null && $byUuid[$dependency]['atomic_group'] === $op['atomic_group'])) {
					return false;
				}
			}

			return true;
		}));
	}

	/**
	 * @return array<string, string>
	 */
	private function appliedRefs(array $operations): array
	{
		$refs = [];

		foreach ($operations as $op) {
			if ($op['temp_ref'] !== null && $op['result_resource_name'] !== null && in_array($op['status'], ChangeStatus::OP_DONE, true)) {
				$refs['ref:' . $op['temp_ref']] = (string) $op['result_resource_name'];
			}
		}

		return $refs;
	}

	/**
	 * Operacje, których rodzic (odwołanie tymczasowe albo depends_on) nie został przyjęty – pomijane, nie wysyłane.
	 */
	private function skipOrphans(int $setId): void
	{
		$operations = $this->sets->operations($setId);
		$byUuid = array_column($operations, null, 'op_uuid');
		$byTemp = [];

		foreach ($operations as $op) {
			if ($op['temp_ref'] !== null) {
				$byTemp['ref:' . $op['temp_ref']] = $op;
			}
		}

		foreach ($operations as $op) {
			if ($op['status'] !== ChangeStatus::OP_PENDING) {
				continue;
			}

			$parents = [...array_map(fn ($ref) => $byTemp[$ref] ?? null, array_filter(OperationCatalog::parentRefs($op), fn ($r) => str_starts_with($r, 'ref:'))), ...array_map(fn ($u) => $byUuid[$u] ?? null, $op['depends_on'])];

			foreach ($parents as $parent) {
				if ($parent !== null && in_array($parent['status'], [ChangeStatus::OP_FAILED, ChangeStatus::OP_SKIPPED, ChangeStatus::OP_CONFLICT], true)) {
					$this->sets->updateOperation($op['id'], ['status' => ChangeStatus::OP_SKIPPED, 'error_message' => 'Pominięto: operacja nadrzędna („' . $parent['label'] . '”) nie została wykonana.']);
					break;
				}
			}
		}
	}

	/**
	 * Powiązania lokalnych obiektów (np. planu kampanii) z utworzonymi zasobami Google Ads.
	 */
	private function links(array $set): void
	{
		foreach ($this->sets->operations((int) $set['id']) as $op) {
			if ($op['local_type'] !== null && $op['local_id'] !== null && $op['result_resource_name'] !== null && in_array($op['status'], ChangeStatus::OP_DONE, true)) {
				$this->sets->link((int) $set['project_id'], (string) $set['customer_id'], (string) $op['local_type'], (int) $op['local_id'], OperationCatalog::resource($op['type']), (string) $op['result_resource_name'], (int) $op['id']);
			}
		}
	}

	private function finish(array $set, ?int $userId, string $event = 'deployed'): string
	{
		$operations = $this->sets->operations((int) $set['id']);
		$statuses = array_column($operations, 'status');
		$done = count(array_filter($statuses, fn ($s) => in_array($s, ChangeStatus::OP_DONE, true)));
		$verified = count(array_filter($statuses, fn ($s) => $s === ChangeStatus::OP_VERIFIED));

		$status = match (true) {
			in_array(ChangeStatus::OP_RECONCILE, $statuses, true) => ChangeStatus::RECONCILE,
			$done === count($statuses) => ChangeStatus::APPLIED,
			$done > 0 => ChangeStatus::PARTIAL,
			default => ChangeStatus::FAILED,
		};

		$this->sets->update((int) $set['id'], [
			'status' => $status, 'worker_token' => null, 'deploy_finished_at' => $this->db->now(), 'next_attempt_at' => null,
			'verified_at' => $done > 0 && $verified === $done ? $this->db->now() : null,
			'last_error' => $status === ChangeStatus::APPLIED ? null : ($status === ChangeStatus::RECONCILE ? 'Wynik części operacji jest nieznany – użyj „Uzgodnij stan”.' : 'Część operacji nie została wykonana – szczegóły przy operacjach.'),
		]);
		$this->sets->addEvent((int) $set['id'], (int) $set['project_id'], $event, $userId, $set['status'], $status, sprintf('Przyjęte przez Google Ads: %d z %d, zweryfikowane odczytem: %d.', $done, count($statuses), $verified));

		// Powiązanie wyniku ze źródłem (rekomendacja / działanie) – bez oznaczania rekomendacji jako skutecznej.
		if ($done > 0 && $set['source_type'] === 'recommendation' && $set['source_id'] !== null) {
			$this->recommendations->addEvent((int) $set['source_id'], (int) $set['project_id'], 'deployed', $userId, null, null, 'Wdrożono zmiany w Google Ads przez Change Center (zestaw #' . $set['id'] . ', ' . ChangeStatus::LABELS[$status] . '). Efekt sprawdź po kilku dniach.');
		}

		return $status;
	}

	private function stop(array $set, string $status, string $message): string
	{
		$this->sets->update((int) $set['id'], ['status' => $status, 'worker_token' => null, 'last_error' => mb_substr($message, 0, 1000), 'deploy_key' => $status === ChangeStatus::READY ? null : $set['deploy_key']]);
		$this->sets->addEvent((int) $set['id'], (int) $set['project_id'], 'deploy_stopped', null, ChangeStatus::DEPLOYING, $status, $message);

		return $status;
	}

	private function retryLater(array $set, GoogleAdsApiException $e, string $context): string
	{
		$attempts = (int) $set['attempts'];

		if (! $e->isRetryable() || $attempts >= self::MAX_QUOTA_RETRIES) {
			return $this->stop($set, ChangeStatus::READY, $context . ': ' . $e->userMessage() . ' Wdrożenie wstrzymane – uruchom je ponownie później.');
		}

		$delay = $e->retryAfterSeconds ?? (60 * (2 ** max(0, $attempts - 1)));
		$this->sets->update((int) $set['id'], ['worker_token' => null, 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + min(3600, $delay)), 'last_error' => mb_substr($context . ': ' . $e->userMessage(), 0, 1000)]);
		$this->sets->addEvent((int) $set['id'], (int) $set['project_id'], 'retry_scheduled', null, null, null, $context . ' – ponowienie za ' . min(3600, $delay) . ' s (żądanie zostało odrzucone w całości, nic nie zapisano).');

		return ChangeStatus::DEPLOYING;
	}

	private static function show(mixed $value): string
	{
		return match (true) {
			$value === null => '—',
			is_bool($value) => $value ? 'tak' : 'nie',
			is_array($value) => implode(', ', array_map('strval', $value)),
			default => (string) $value,
		};
	}
}
