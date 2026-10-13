<?php

namespace App\PennyPincher\Services\Changes;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Conversions\ConversionFieldPolicy;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\GoogleAds\Changes\ResourceName;
use App\PennyPincher\Domain\Planner\NegativeConflictChecker;
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
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use App\PennyPincher\Services\GoogleAds\StateReader;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use App\PennyPincher\Services\Planner\CampaignPlanService;

/**
 * Change Center – jedyna droga każdej zmiany w Google Ads:
 * szkic → przegląd → zatwierdzenie (z odczytem aktualnego stanu, walidacją lokalną i opcjonalnie walidacją Google)
 * → świadome uruchomienie wdrożenia przez wykonawcę → kolejka → worker (`ExecutionService`) → weryfikacja.
 *
 * Akceptacja rekomendacji (np. w Action Plan) nie tworzy ani nie zatwierdza zmian – to osobne czynności.
 */
class ChangeCenterService
{
	public const SOURCES = [
		'manual' => 'Ręcznie (Google Ads Management)',
		'recommendation' => 'Rekomendacja',
		'action_item' => 'Action Plan',
		'budget_leak' => 'Budget Leaks',
		'keyword_opportunity' => 'Keyword Opportunities',
		'negative_suggestion' => 'Propozycje wykluczeń',
		'plan' => 'Campaign Planner',
		'conversion_health' => 'Conversion Health Check',
		'monitoring' => 'Monitoring',
		'rollback' => 'Cofnięcie zmiany',
	];

	/** Stan odczytany z Google Ads ważny do zatwierdzenia (godziny). Przy wdrożeniu stan jest i tak czytany ponownie. */
	public const STATE_MAX_AGE_HOURS = 24;

	/** Słowo potwierdzenia wdrożenia zmian wysokiego ryzyka. */
	public const CONFIRM_WORD = 'WDRÓŻ';

	public function __construct(
		private Db $db,
		private ChangeSetRepository $sets,
		private GoogleAdsConnectionRepository $connections,
		private WriteGuard $guard,
		private StateReader $reader,
		private GoogleAdsMutateClient $mutate,
		private ConnectionService $connectionService,
		private CampaignPlanService $planner,
	) {
	}

	// --- Tworzenie i edycja -----------------------------------------------------------------------

	/**
	 * Nowy szkic powiązany z bieżącym połączeniem (kontem) projektu. Bez połączenia szkic można przygotować,
	 * ale nie zatwierdzić.
	 */
	public function createSet(Project $project, array $input, int $userId): int
	{
		$this->requirePrepare($project);
		$title = trim(wp_strip_all_tags((string) ($input['title'] ?? '')));

		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationFailed(['title' => 'Podaj nazwę zestawu zmian (maks. 255 znaków).']);
		}

		$source = (string) ($input['source_type'] ?? 'manual');
		$connection = $this->connections->currentForProject($project->id);
		$active = $connection !== null && $connection['status'] === GoogleAdsConnectionRepository::STATUS_ACTIVE;

		$id = $this->sets->create([
			'project_id' => $project->id,
			'organization_id' => $project->organizationId,
			'connection_id' => $active ? (int) $connection['id'] : null,
			'customer_id' => $active ? (string) $connection['customer_id'] : null,
			'title' => $title,
			'description' => self::text($input['description'] ?? null, 2000),
			'source_type' => array_key_exists($source, self::SOURCES) ? $source : 'manual',
			'source_id' => isset($input['source_id']) ? (int) $input['source_id'] : null,
			'source_label' => isset($input['source_label']) ? mb_substr(wp_strip_all_tags((string) $input['source_label']), 0, 255) : null,
			'status' => ChangeStatus::DRAFT,
			'rollback_of' => isset($input['rollback_of']) ? (int) $input['rollback_of'] : null,
			'created_by' => $userId,
		]);
		$this->sets->addEvent($id, $project->id, 'created', $userId, null, ChangeStatus::DRAFT, $active ? null : 'Projekt nie ma aktywnego połączenia z Google Ads – szkic można przygotować, ale nie zatwierdzić.');

		return $id;
	}

	/**
	 * Dodanie operacji do edytowalnego zestawu. Każda zmiana operacji unieważnia wcześniejsze zatwierdzenie.
	 *
	 * @param array{type: string, resource_name?: ?string, payload?: array, label?: string, temp_ref?: ?string, atomic_group?: ?string, depends_on?: array, before_state?: ?array, before_source?: string, local_type?: ?string, local_id?: ?int} $op
	 */
	public function addOperation(Project $project, int $setId, array $op, int $userId): int
	{
		$set = $this->editable($project, $setId);
		$type = (string) ($op['type'] ?? '');

		if (! OperationCatalog::exists($type)) {
			throw new ValidationFailed(['type' => 'Ta operacja nie jest obsługiwana przez Penny Pincher.']);
		}

		$resource = OperationCatalog::resource($type);
		$name = isset($op['resource_name']) && $op['resource_name'] !== '' ? (string) $op['resource_name'] : null;

		if (OperationCatalog::action($type) !== 'create') {
			if ($set['customer_id'] === null) {
				throw new ValidationFailed(['connection' => 'Połącz projekt z kontem Google Ads, aby przygotować zmianę istniejącego zasobu.']);
			}

			if ($name === null || ! ResourceName::isValid($name, (string) $set['customer_id']) || ! str_contains($name, '/' . OperationCatalog::COLLECTIONS[$resource] . '/')) {
				throw new ValidationFailed(['resource' => 'Zasób nie należy do konta Google Ads tego zestawu zmian.']);
			}
		}

		$payload = self::cleanPayload((array) ($op['payload'] ?? []));
		self::guardConversionFields($type, is_array($op['before_state'] ?? null) ? $op['before_state'] : null, $payload);
		$record = [
			'type' => $type,
			'resource_type' => $resource,
			'resource_name' => $name,
			'payload' => $payload,
			'before_state' => $op['before_state'] ?? null,
		];

		$id = $this->sets->insertOperation([
			'change_set_id' => $setId,
			'project_id' => $project->id,
			'position' => $op['position'] ?? $this->sets->nextPosition($setId),
			'op_uuid' => wp_generate_uuid4(),
			'type' => $type,
			'resource_type' => $resource,
			'resource_name' => $name,
			'temp_ref' => isset($op['temp_ref']) ? mb_substr((string) $op['temp_ref'], 0, 60) : null,
			'atomic_group' => isset($op['atomic_group']) ? mb_substr((string) $op['atomic_group'], 0, 60) : null,
			'depends_on' => array_values((array) ($op['depends_on'] ?? [])),
			'label' => mb_substr(wp_strip_all_tags((string) ($op['label'] ?? OperationCatalog::TYPES[$type]['label'])), 0, 255),
			'payload' => $payload,
			'before_state' => $op['before_state'] ?? null,
			'before_source' => in_array($op['before_source'] ?? null, ['api', 'sync'], true) ? $op['before_source'] : (isset($op['before_state']) ? 'sync' : null),
			'before_read_at' => ($op['before_source'] ?? null) === 'api' ? $this->db->now() : null,
			'risk' => OperationCatalog::risk($record),
			'status' => ChangeStatus::OP_PENDING,
			'local_type' => $op['local_type'] ?? null,
			'local_id' => $op['local_id'] ?? null,
		]);

		$this->invalidate($project, $set, $userId, 'operation_added', 'Dodano operację: ' . mb_substr((string) ($op['label'] ?? $type), 0, 200), $id);

		return $id;
	}

	/**
	 * Edycja proponowanych wartości operacji (tylko pola ładunku; typ i zasób pozostają).
	 */
	public function updateOperation(Project $project, int $setId, int $operationId, array $payload, int $userId, ?string $label = null): void
	{
		$set = $this->editable($project, $setId);
		$op = $this->sets->findOperation($setId, $operationId) ?? throw new ValidationFailed(['operation' => 'Nie znaleziono operacji.']);
		$merged = self::cleanPayload(array_replace($op['payload'], $payload));
		// Sprawdzamy tylko pola zmieniane teraz – istniejąca, nieprawidłowa operacja jest opisana w walidacji (do usunięcia).
		self::guardConversionFields($op['type'], is_array($op['before_state'] ?? null) ? $op['before_state'] : null, array_intersect_key($merged, self::cleanPayload($payload)));
		$op['payload'] = $merged;

		$this->sets->updateOperation($operationId, array_filter([
			'payload' => $merged,
			'risk' => OperationCatalog::risk($op),
			'label' => $label !== null ? mb_substr(wp_strip_all_tags($label), 0, 255) : null,
		], fn ($v) => $v !== null));
		$this->invalidate($project, $set, $userId, 'operation_edited', 'Zmieniono operację: ' . $op['label'], $operationId);
	}

	/**
	 * Operacja zmiany działania konwersji nie może zawierać pól, których Google Ads API nie pozwala zmienić
	 * (ConversionFieldPolicy). Bez znanego stanu działania kontrolę wykona walidacja po odczycie z Google Ads.
	 */
	private static function guardConversionFields(string $type, ?array $state, array $payload): void
	{
		if ($type !== 'conversion_action.update' || $state === null) {
			return;
		}

		if (($violations = ConversionFieldPolicy::violations($state, $payload)) !== []) {
			throw new ValidationFailed(['payload' => ConversionFieldPolicy::message($violations)]);
		}
	}

	public function removeOperation(Project $project, int $setId, int $operationId, int $userId): void
	{
		$set = $this->editable($project, $setId);
		$op = $this->sets->findOperation($setId, $operationId) ?? throw new ValidationFailed(['operation' => 'Nie znaleziono operacji.']);

		foreach ($this->sets->operations($setId) as $other) {
			$refs = OperationCatalog::parentRefs($other);

			if ($other['id'] !== $operationId && (($op['temp_ref'] !== null && in_array('ref:' . $op['temp_ref'], $refs, true)) || in_array($op['op_uuid'], $other['depends_on'], true))) {
				throw new ValidationFailed(['operation' => 'Od tej operacji zależą inne („' . $other['label'] . '”) – usuń najpierw je.']);
			}
		}

		$this->sets->deleteOperation($setId, $operationId);
		$this->invalidate($project, $set, $userId, 'operation_removed', 'Usunięto operację: ' . $op['label']);
	}

	// --- Odczyt stanu z Google Ads ----------------------------------------------------------------

	/**
	 * Odczyt aktualnego stanu zasobów z Google Ads (stan „przed” do podglądu i wykrywania zmian poza aplikacją)
	 * oraz danych do kontroli duplikatów. Tylko odczyt (GAQL).
	 */
	public function refreshState(Project $project, int $setId, int $userId): int
	{
		$set = $this->editable($project, $setId);
		$connection = $this->boundConnection($set);
		$read = 0;

		try {
			foreach ($this->sets->operations($setId) as $op) {
				$this->readOperationState($connection, $set, $op);
				$read++;
			}
		} catch (GoogleAdsApiException $e) {
			$this->sets->addAttempt($setId, $project->id, 'read', 'error', [], ['customer_id' => $set['customer_id'], 'http_status' => $e->httpStatus, 'request_id' => $e->requestId, 'error_code' => $e->googleCode ?? $e->kind, 'message' => $e->getMessage(), 'user_id' => $userId]);

			throw new ValidationFailed(['google_ads' => 'Nie udało się odczytać stanu konta Google Ads' . ($read > 0 ? " (odczytano {$read} z " . count($this->sets->operations($setId)) . ' operacji)' : '') . ': ' . $e->userMessage()]);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			// Np. nieprawidłowy identyfikator zasobu albo problem z tokenem – bez szczegółów technicznych w interfejsie.
			error_log('[Penny Pincher] Odczyt stanu zestawu #' . $setId . ': ' . ChangeSetRepository::scrub($e->getMessage()));
			$this->sets->addAttempt($setId, $project->id, 'read', 'error', [], ['customer_id' => $set['customer_id'], 'error_code' => 'LOCAL', 'message' => mb_substr(ChangeSetRepository::scrub($e->getMessage()), 0, 300), 'user_id' => $userId]);

			throw new ValidationFailed(['google_ads' => 'Nie udało się odczytać stanu konta Google Ads – spróbuj ponownie. Jeśli problem się powtarza, sprawdź połączenie w zakładce Google Ads (może wymagać ponownej autoryzacji).']);
		}

		$this->sets->addAttempt($setId, $project->id, 'read', 'ok', [], ['customer_id' => $set['customer_id'], 'user_id' => $userId, 'message' => "Odczytano stan {$read} operacji."]);
		$this->invalidate($project, $set, $userId, 'state_read', 'Odczytano aktualny stan zasobów z Google Ads.');

		return $read;
	}

	/**
	 * Stan jednego zasobu + kontekst (siostrzane zasoby, kampanie współdzielące budżet) zapisane w operacji.
	 */
	public function readOperationState(array $connection, array $set, array $op): void
	{
		$type = $op['type'];
		$resource = OperationCatalog::resource($type);
		$values = ['before_read_at' => $this->db->now(), 'before_source' => 'api'];
		$context = [];

		if (OperationCatalog::action($type) !== 'create') {
			$state = $this->reader->read($connection, $resource, (string) $op['resource_name']);

			if ($state !== null && $resource === 'campaign_budget') {
				$state['campaigns'] = array_map(fn ($c) => ['name' => $c['name'], 'status' => $c['status'], 'resource_name' => $c['resource_name']], $this->reader->campaignsUsingBudget($connection, (string) $op['resource_name']));
			}

			if ($state !== null && $type === 'campaign_criterion.remove' && $state['campaign'] !== null && in_array($state['type'], ['LOCATION', 'LANGUAGE'], true)) {
				$siblings = $this->reader->list($connection, 'campaign_criterion', StateQueries::campaignCriteria((string) $state['campaign']));
				$context['remaining_same_type'] = count(array_filter($siblings, fn ($c) => $c['type'] === $state['type'] && ! $c['negative'] && $c['resource_name'] !== $op['resource_name']));
			}

			$values['before_state'] = $state;

			if ($state === null) {
				$context['missing'] = true; // zasób nie istnieje albo jest niedostępny – walidacja zablokuje operację
			}
		}

		// Dane do kontroli duplikatów – z Google Ads, gdy rodzic już istnieje.
		$payload = $op['payload'];
		$parent = fn (string $key) => isset($payload[$key]) && ! str_starts_with((string) $payload[$key], 'ref:') ? (string) $payload[$key] : null;

		if ($type === 'ad_group_criterion.create' && $parent('ad_group') !== null) {
			$rows = $this->reader->list($connection, 'ad_group_criterion', StateQueries::adGroupCriteria($parent('ad_group')));
			$context['existing_keywords'] = array_values(array_map(fn ($k) => $k['keyword_text'] . '|' . $k['match_type'], array_filter($rows, fn ($k) => $k['negative'] === ! empty($payload['negative']))));
			$context['active_keywords'] = array_values(array_map(fn ($k) => ['text' => $k['keyword_text'], 'match_type' => $k['match_type']], array_filter($rows, fn ($k) => ! $k['negative'] && $k['status'] === 'ENABLED')));
		} elseif ($type === 'campaign_criterion.create' && $parent('campaign') !== null && ($payload['kind'] ?? '') === 'negative_keyword') {
			$rows = $this->reader->list($connection, 'campaign_criterion', StateQueries::campaignCriteria($parent('campaign')));
			$context['existing_negatives'] = array_values(array_map(fn ($c) => $c['keyword_text'] . '|' . $c['match_type'], array_filter($rows, fn ($c) => $c['type'] === 'KEYWORD' && $c['negative'])));
		} elseif ($type === 'campaign.create') {
			$context['existing_names'] = array_values(array_map(fn ($c) => (string) $c['name'], $this->reader->list($connection, 'campaign', StateQueries::campaign())));
		} elseif ($type === 'ad_group.create' && $parent('campaign') !== null) {
			$context['existing_names'] = array_values(array_map(fn ($g) => (string) $g['name'], $this->reader->list($connection, 'ad_group', StateQueries::adGroups($parent('campaign')))));
		} elseif ($type === 'conversion_action.create') {
			$context['existing_names'] = array_values(array_map(fn ($c) => (string) $c['name'], $this->reader->list($connection, 'conversion_action', StateQueries::conversionAction())));
		}

		$values['current_state'] = $context === [] ? null : ['context' => $context, 'read_at' => $this->db->now()];
		$op['before_state'] = $values['before_state'] ?? $op['before_state'];
		$values['risk'] = OperationCatalog::risk($op);
		$this->sets->updateOperation((int) $op['id'], $values);
	}

	// --- Walidacja --------------------------------------------------------------------------------

	/**
	 * Walidacja lokalna całego zestawu (bez Google Ads). Wynik NIE jest potwierdzeniem Google Ads.
	 *
	 * @return array{ok: bool, errors: array<int, array<int, string>>, warnings: array<int, array<int, string>>, set_errors: array<int, string>}
	 */
	public function validateLocally(Project $project, array $set, array $operations): array
	{
		$errors = [];
		$warnings = [];
		$confirmations = [];
		$unsupported = [];
		$setErrors = [];
		$temps = [];
		$uuids = array_column($operations, 'op_uuid');

		if ($operations === []) {
			$setErrors[] = 'Zestaw nie zawiera operacji.';
		}

		$context = $this->syncContext($project, $set);

		foreach ($operations as $op) {
			$opContext = array_replace($context['base'], $this->opContext($context, $op), (array) ($op['current_state']['context'] ?? []));

			if (($op['type'] === 'ad_group_criterion.create' || $op['type'] === 'campaign_criterion.create') && ! empty($op['payload']['text']) && (! empty($op['payload']['negative']) || ($op['payload']['kind'] ?? '') === 'negative_keyword')) {
				$opContext['blocked_keywords'] = $this->blockedKeywords($op, $opContext);
			}

			if ($op['type'] === 'ad_group_criterion.create' && empty($op['payload']['negative']) && ! empty($op['payload']['text'])) {
				$opContext['blocking_negatives'] = array_values(array_unique(array_map(
					fn ($n) => $n['text'] . ($n['level'] === 'campaign' ? ' (kampania)' : ' (grupa)'),
					array_filter((array) ($opContext['negatives'] ?? []), fn ($n) => NegativeConflictChecker::blocks($n['text'], strtolower($n['match_type']), (string) $op['payload']['text']))
				)));
			}

			$result = OperationCatalog::validate($op, $opContext);
			$opErrors = $result['errors'];

			foreach (OperationCatalog::parentRefs($op) as $ref) {
				if (! str_starts_with($ref, 'ref:')) {
					continue;
				}

				if (! array_key_exists(substr($ref, 4), $temps)) {
					$opErrors[] = 'Zasób nadrzędny musi zostać utworzony wcześniej w tym samym zestawie.';
				} elseif ($temps[substr($ref, 4)] === null || $temps[substr($ref, 4)] !== $op['atomic_group']) {
					// Tymczasowe identyfikatory działają tylko w obrębie jednego żądania (dokumentacja Google Ads API).
					$opErrors[] = 'Operacja zależna musi być w tej samej grupie atomowej co tworzony zasób nadrzędny.';
				}
			}

			foreach ($op['depends_on'] as $dependency) {
				if (! in_array($dependency, $uuids, true)) {
					$opErrors[] = 'Brak operacji, od której zależy ta zmiana.';
				}
			}

			if ($op['temp_ref'] !== null) {
				$temps[$op['temp_ref']] = $op['atomic_group'];
			}

			if ($opErrors !== []) {
				$errors[$op['id']] = array_values(array_unique($opErrors));
			}

			if ($result['warnings'] !== []) {
				$warnings[$op['id']] = $result['warnings'];
			}

			if (($result['confirmations'] ?? []) !== []) {
				$confirmations[$op['id']] = $result['confirmations'];
			}

			if (($result['unsupported'] ?? []) !== []) {
				$unsupported[$op['id']] = $result['unsupported'];
			}
		}

		return ['ok' => $errors === [] && $setErrors === [], 'errors' => $errors, 'warnings' => $warnings, 'set_errors' => $setErrors, 'confirmations' => $confirmations, 'unsupported' => $unsupported];
	}

	/**
	 * Walidacja przez Google Ads API (validate_only = true – nic nie jest zapisywane). Dostępna, gdy serwer pozwala.
	 *
	 * @return array{status: string, message: string, errors: array<int, string>}
	 */
	public function validateWithApi(Project $project, array $set, array $operations, ?int $userId): array
	{
		$connection = $this->connections->currentForProject($project->id);

		if (! $this->guard->apiValidationAvailable($connection) || $connection === null || (int) $connection['id'] !== (int) $set['connection_id']) {
			return ['status' => 'skipped', 'message' => 'Walidacja w Google Ads API niedostępna (wyłączona na serwerze albo brak aktywnego połączenia) – sprawdzono tylko lokalnie.', 'errors' => []];
		}

		$errors = [];

		try {
			foreach ($this->requests($set, array_filter($operations, fn ($op) => $op['status'] === ChangeStatus::OP_PENDING), []) as $request) {
				$result = $this->mutate->mutate($this->connectionService->accessToken($connection), (string) $set['customer_id'], $connection['login_customer_id'] ?? null, $request['operations'], true, $request['group'] === null);

				foreach ($request['ops'] as $i => $op) {
					if (isset($result->errors[$i])) {
						$errors[$op['id']] = $result->errors[$i]['message'] . ' (' . $result->errors[$i]['code'] . ')';
					}
				}

				$this->sets->addAttempt((int) $set['id'], $project->id, 'api_validation', $errors === [] ? 'ok' : 'error', array_column($request['ops'], 'id'), ['customer_id' => $set['customer_id'], 'request_id' => $result->requestId, 'user_id' => $userId, 'message' => $result->requestError]);
			}
		} catch (WriteBlockedException $e) {
			return ['status' => 'skipped', 'message' => $e->getMessage(), 'errors' => []];
		} catch (GoogleAdsApiException $e) {
			$this->sets->addAttempt((int) $set['id'], $project->id, 'api_validation', 'error', [], ['customer_id' => $set['customer_id'], 'http_status' => $e->httpStatus, 'request_id' => $e->requestId, 'error_code' => $e->googleCode ?? $e->kind, 'message' => $e->getMessage(), 'user_id' => $userId]);

			return ['status' => 'error', 'message' => $e->userMessage(), 'errors' => []];
		} catch (AmbiguousMutateException $e) {
			return ['status' => 'error', 'message' => $e->getMessage(), 'errors' => []];
		}

		return $errors === []
			? ['status' => 'ok', 'message' => 'Google Ads API potwierdził poprawność operacji (walidacja bez zapisu).', 'errors' => []]
			: ['status' => 'error', 'message' => 'Google Ads API odrzucił część operacji (walidacja bez zapisu).', 'errors' => $errors];
	}

	// --- Przepływ statusów ------------------------------------------------------------------------

	public function submit(Project $project, int $setId, int $userId): void
	{
		$set = $this->find($project, $setId);
		$operations = $this->sets->operations($setId);
		$validation = $this->validateLocally($project, $set, $operations);
		$this->sets->update($setId, ['validation' => ['local' => $validation + ['at' => $this->db->now()]] + array_intersect_key($set['validation'], ['api' => 1])]);

		if (! $validation['ok']) {
			throw new ValidationFailed(['validation' => 'Popraw błędy operacji przed wysłaniem do przeglądu: ' . self::firstError($validation)]);
		}

		if (! $this->sets->transition($setId, [ChangeStatus::DRAFT], ChangeStatus::REVIEW)) {
			throw new ValidationFailed(['status' => 'Do przeglądu można wysłać tylko szkic.']);
		}

		$this->sets->addEvent($setId, $project->id, 'submitted', $userId, ChangeStatus::DRAFT, ChangeStatus::REVIEW);
	}

	/**
	 * Zatwierdzenie (administrator). Wymaga aktualnego stanu z Google Ads i poprawnej walidacji; zapisuje odcisk
	 * zatwierdzonej wersji operacji. Potem walidacja (lokalna + Google, jeśli dostępna) → „Gotowy do wdrożenia”.
	 * Nic nie jest wysyłane do Google Ads w trybie wykonania.
	 */
	public function approve(Project $project, int $setId, int $userId, bool $confirmHighRisk, bool $confirmBidding): string
	{
		if (! Policy::allows($project->role, Ability::ApproveChanges)) {
			throw new ValidationFailed(['permission' => 'Zatwierdzać zmiany może administrator organizacji.']);
		}

		$set = $this->find($project, $setId);

		if ($set['status'] !== ChangeStatus::REVIEW) {
			throw new ValidationFailed(['status' => 'Zatwierdzić można tylko zestaw w statusie „Do przeglądu”.']);
		}

		$this->boundConnection($set);
		$operations = $this->sets->operations($setId);
		$limit = gmdate('Y-m-d H:i:s', time() - self::STATE_MAX_AGE_HOURS * HOUR_IN_SECONDS);

		foreach ($operations as $op) {
			if ($op['before_source'] !== 'api' || $op['before_read_at'] === null || $op['before_read_at'] < $limit) {
				throw new ValidationFailed(['state' => 'Przed zatwierdzeniem odczytaj aktualny stan zasobów z Google Ads („Odczytaj stan z Google Ads”) – stan nie może być starszy niż ' . self::STATE_MAX_AGE_HOURS . ' h.']);
			}
		}

		$validation = $this->validateLocally($project, $set, $operations);

		if (! $validation['ok']) {
			$this->sets->update($setId, ['validation' => ['local' => $validation + ['at' => $this->db->now()]]]);

			throw new ValidationFailed(['validation' => 'Walidacja lokalna wykryła błędy: ' . self::firstError($validation)]);
		}

		$risk = ChangeStatus::maxRisk(array_map(fn ($op) => OperationCatalog::risk($op), $operations));

		if ($risk === 'high' && ! $confirmHighRisk) {
			throw new ValidationFailed(['confirm' => 'Zestaw zawiera zmiany wysokiego ryzyka – potwierdź, że znasz ich skutki.']);
		}

		if (array_filter($operations, fn ($op) => OperationCatalog::affectsBidding($op)) !== [] && ! $confirmBidding) {
			throw new ValidationFailed(['confirm_bidding' => 'Zestaw zmienia cele lub strategię stawek (Smart Bidding) – potwierdź to osobno.']);
		}

		$hash = $this->hash($set, $operations);

		if (! $this->sets->transition($setId, [ChangeStatus::REVIEW], ChangeStatus::VALIDATING, [
			'approved_version' => $set['version'], 'approved_hash' => $hash, 'approved_by' => $userId, 'approved_at' => $this->db->now(),
			'high_risk_confirmed' => $risk === 'high' ? 1 : 0, 'risk_level' => $risk, 'budget_impact_micros' => self::budgetImpact($operations),
		])) {
			throw new ValidationFailed(['status' => 'Status zestawu zmienił się w międzyczasie – odśwież stronę.']);
		}

		$this->sets->addEvent($setId, $project->id, 'approved', $userId, ChangeStatus::REVIEW, ChangeStatus::VALIDATING, 'Zatwierdzono wersję ' . $set['version'] . ' (odcisk ' . substr($hash, 0, 12) . '). Zatwierdzenie nie oznacza wdrożenia.');

		$api = $this->validateWithApi($project, $set, $operations, $userId);
		$record = ['local' => $validation + ['at' => $this->db->now()], 'api' => $api + ['at' => $this->db->now()]];

		if ($api['status'] === 'error') {
			$this->sets->transition($setId, [ChangeStatus::VALIDATING], ChangeStatus::DRAFT, ['validation' => $record, 'approved_hash' => null, 'approved_version' => null, 'approved_by' => null, 'approved_at' => null]);
			$this->sets->addEvent($setId, $project->id, 'validation_failed', $userId, ChangeStatus::VALIDATING, ChangeStatus::DRAFT, $api['message']);

			foreach ($api['errors'] as $opId => $message) {
				$this->sets->updateOperation((int) $opId, ['error_code' => 'VALIDATION', 'error_message' => $message]);
			}

			return 'Google Ads odrzucił część operacji podczas walidacji (bez zapisu) – zestaw wrócił do szkicu.';
		}

		$this->sets->transition($setId, [ChangeStatus::VALIDATING], ChangeStatus::READY, ['validation' => $record]);
		$this->sets->addEvent($setId, $project->id, 'validated', $userId, ChangeStatus::VALIDATING, ChangeStatus::READY, $api['message']);

		return $api['status'] === 'ok'
			? 'Zatwierdzono. Walidacja lokalna i walidacja Google Ads API (bez zapisu) zakończone powodzeniem. Nic nie zostało jeszcze zmienione w Google Ads.'
			: 'Zatwierdzono. Walidacja lokalna OK (to nie jest potwierdzenie Google Ads). Nic nie zostało jeszcze zmienione w Google Ads.';
	}

	/**
	 * Odesłanie do poprawek (administrator) – status „Szkic”, zatwierdzenie usunięte.
	 */
	public function reject(Project $project, int $setId, int $userId, ?string $note): void
	{
		if (! Policy::allows($project->role, Ability::ApproveChanges)) {
			throw new ValidationFailed(['permission' => 'Odrzucać zmiany może administrator organizacji.']);
		}

		$set = $this->find($project, $setId);

		if (! $this->sets->transition($setId, [ChangeStatus::REVIEW, ChangeStatus::READY], ChangeStatus::DRAFT, ['approved_hash' => null, 'approved_version' => null, 'approved_by' => null, 'approved_at' => null])) {
			throw new ValidationFailed(['status' => 'Odesłać do poprawek można zestaw w przeglądzie albo gotowy do wdrożenia.']);
		}

		$this->sets->addEvent($setId, $project->id, 'rejected', $userId, $set['status'], ChangeStatus::DRAFT, self::text($note, 1000));
	}

	/**
	 * Świadome uruchomienie wdrożenia przez wykonawcę. Zestaw trafia do kolejki – to jeszcze nie jest wdrożenie.
	 * Klucz wdrożenia (z formularza) chroni przed podwójnym kliknięciem i ponownym wysłaniem formularza.
	 */
	public function requestDeploy(Project $project, int $setId, int $userId, string $deployKey, string $confirmation): string
	{
		$set = $this->find($project, $setId);
		$connection = $this->connections->currentForProject($project->id);

		if ($set['deploy_key'] !== null && hash_equals((string) $set['deploy_key'], $deployKey)) {
			return 'Ten zestaw jest już w kolejce do wdrożenia (ponowne kliknięcie zostało zignorowane).';
		}

		$this->guard->assertCanExecute($project, $connection, $userId, $set);

		if ($set['status'] !== ChangeStatus::READY) {
			throw new ValidationFailed(['status' => 'Wdrożyć można tylko zestaw „Gotowy do wdrożenia”.']);
		}

		$operations = $this->sets->operations($setId);

		if ($set['approved_hash'] === null || (int) $set['approved_version'] !== (int) $set['version'] || ! hash_equals((string) $set['approved_hash'], $this->hash($set, $operations))) {
			throw new ValidationFailed(['approval' => 'Zestaw zmienił się po zatwierdzeniu – wymaga ponownego przeglądu.']);
		}

		$expected = $set['risk_level'] === 'high' ? self::CONFIRM_WORD : 'tak';

		if (mb_strtoupper(trim($confirmation)) !== mb_strtoupper($expected)) {
			throw new ValidationFailed(['confirm' => $set['risk_level'] === 'high' ? 'Zmiany wysokiego ryzyka: wpisz „' . self::CONFIRM_WORD . '”, aby potwierdzić wdrożenie.' : 'Potwierdź wdrożenie.']);
		}

		if (! preg_match('/^[0-9a-f-]{36}$/', $deployKey)) {
			throw new ValidationFailed(['deploy_key' => 'Nieprawidłowy formularz – odśwież stronę.']);
		}

		if (! $this->sets->transition($setId, [ChangeStatus::READY], ChangeStatus::DEPLOYING, [
			'deploy_key' => $deployKey, 'deploy_requested_by' => $userId, 'deploy_requested_at' => $this->db->now(), 'next_attempt_at' => null, 'last_error' => null,
		])) {
			return 'Zestaw jest już wdrażany albo jego status się zmienił.';
		}

		$this->sets->addEvent($setId, $project->id, 'deploy_requested', $userId, ChangeStatus::READY, ChangeStatus::DEPLOYING, 'Wdrożenie zlecone – zestaw czeka w kolejce na worker.');

		return 'Zestaw trafił do kolejki wdrożeń. To jeszcze nie jest wdrożenie – status zmieni się po przetworzeniu przez worker (zwykle do kilku minut).';
	}

	public function cancel(Project $project, int $setId, int $userId): void
	{
		$set = $this->find($project, $setId);

		if (! $this->sets->transition($setId, ChangeStatus::CANCELLABLE, ChangeStatus::CANCELLED)) {
			throw new ValidationFailed(['status' => 'Tego zestawu nie można już anulować (jest wdrażany albo wdrożony).']);
		}

		$this->sets->addEvent($setId, $project->id, 'cancelled', $userId, $set['status'], ChangeStatus::CANCELLED);
	}

	/**
	 * „Przygotuj cofnięcie”: nowy szkic z operacjami odwrotnymi – tylko dla operacji, które da się bezpiecznie odwrócić.
	 */
	public function prepareRollback(Project $project, int $setId, int $userId): int
	{
		$set = $this->find($project, $setId);

		if (! in_array($set['status'], [ChangeStatus::APPLIED, ChangeStatus::PARTIAL], true)) {
			throw new ValidationFailed(['status' => 'Cofnięcie można przygotować tylko dla wdrożonego zestawu.']);
		}

		$inverse = [];

		foreach ($this->sets->operations($setId) as $op) {
			if (in_array($op['status'], ChangeStatus::OP_DONE, true) && ($candidate = OperationCatalog::inverse($op, $op['result_resource_name'])) !== null) {
				$inverse[] = $candidate + ['source_op' => $op];
			}
		}

		if ($inverse === []) {
			throw new ValidationFailed(['rollback' => 'Żadnej operacji z tego zestawu nie da się bezpiecznie cofnąć automatycznie (np. usunięcia, nowe kampanie, nowe konwersje) – wykonaj to świadomie w Google Ads.']);
		}

		$newId = $this->createSet($project, ['title' => 'Cofnięcie: ' . mb_substr($set['title'], 0, 230), 'description' => 'Operacje odwrotne do zestawu #' . $setId . '. Przed zatwierdzeniem odczytaj aktualny stan – mógł się zmienić od wdrożenia.', 'source_type' => 'rollback', 'source_id' => $setId, 'rollback_of' => $setId], $userId);

		foreach (array_reverse($inverse) as $candidate) {
			$this->addOperation($project, $newId, ['type' => $candidate['type'], 'resource_name' => $candidate['resource_name'], 'payload' => $candidate['payload'], 'label' => $candidate['label'] . ' (' . $candidate['source_op']['label'] . ')'], $userId);
		}

		$this->sets->addEvent($setId, $project->id, 'rollback_prepared', $userId, null, null, 'Przygotowano szkic cofnięcia #' . $newId . '.');

		return $newId;
	}

	/**
	 * Nowy szkic z operacji, które się nie powiodły (bez ponownego wysyłania tych, które Google przyjął).
	 */
	public function retryFailed(Project $project, int $setId, int $userId): int
	{
		$set = $this->find($project, $setId);
		$failed = array_values(array_filter($this->sets->operations($setId), fn ($op) => in_array($op['status'], [ChangeStatus::OP_FAILED, ChangeStatus::OP_SKIPPED, ChangeStatus::OP_CONFLICT], true)));

		if ($failed === [] || ! in_array($set['status'], [ChangeStatus::PARTIAL, ChangeStatus::FAILED, ChangeStatus::REVIEW, ChangeStatus::DRAFT], true)) {
			throw new ValidationFailed(['retry' => 'Brak nieudanych operacji do ponownego przygotowania.']);
		}

		// Zasoby nadrzędne utworzone w poprzedniej próbie – odwołania tymczasowe zastępujemy prawdziwymi identyfikatorami.
		$created = [];

		foreach ($this->sets->operations($setId) as $op) {
			if ($op['temp_ref'] !== null && $op['result_resource_name'] !== null && in_array($op['status'], ChangeStatus::OP_DONE, true)) {
				$created['ref:' . $op['temp_ref']] = $op['result_resource_name'];
			}
		}

		$newId = $this->createSet($project, ['title' => 'Ponowienie: ' . mb_substr($set['title'], 0, 230), 'source_type' => $set['source_type'], 'source_id' => $set['source_id'], 'source_label' => $set['source_label']], $userId);

		foreach ($failed as $op) {
			foreach (['budget', 'campaign', 'ad_group'] as $key) {
				if (isset($op['payload'][$key], $created[$op['payload'][$key]])) {
					$op['payload'][$key] = $created[$op['payload'][$key]];
				}
			}

			$this->addOperation($project, $newId, [
				'type' => $op['type'], 'resource_name' => $op['resource_name'], 'payload' => $op['payload'], 'label' => $op['label'],
				'temp_ref' => $op['temp_ref'], 'atomic_group' => $op['atomic_group'], 'depends_on' => array_values(array_intersect($op['depends_on'], array_column($failed, 'op_uuid'))),
				'local_type' => $op['local_type'], 'local_id' => $op['local_id'] !== null ? (int) $op['local_id'] : null,
			], $userId);
		}

		return $newId;
	}

	// --- Odczyt ---------------------------------------------------------------------------------

	public function find(Project $project, int $setId): array
	{
		return $this->sets->find($project->id, $setId) ?? throw new ValidationFailed(['change_set' => 'Nie znaleziono zestawu zmian w tym projekcie.']);
	}

	/**
	 * Odcisk zatwierdzanej treści: konto, połączenie i każda operacja (typ, zasób, ładunek, stan „przed”, zależności).
	 */
	public function hash(array $set, array $operations): string
	{
		$canonical = [
			'customer' => (string) $set['customer_id'],
			'connection' => (int) $set['connection_id'],
			'operations' => array_map(fn ($op) => [
				'uuid' => $op['op_uuid'], 'type' => $op['type'], 'resource' => $op['resource_name'], 'temp' => $op['temp_ref'], 'group' => $op['atomic_group'],
				'depends' => $op['depends_on'], 'payload' => self::ksortRecursive((array) $op['payload']), 'before' => is_array($op['before_state']) ? self::ksortRecursive($op['before_state']) : null,
			], $operations),
		];

		return hash('sha256', (string) wp_json_encode($canonical));
	}

	/**
	 * Podział operacji na żądania googleAds:mutate: operacje z tą samą grupą atomową – jedno żądanie „wszystko albo nic”
	 * (wymagane dla tymczasowych identyfikatorów), pozostałe – żądania z partial_failure (wynik każdej operacji osobno).
	 *
	 * @param array<string, string> $applied odwołania 'ref:…' → rzeczywiste resource names z wcześniejszych przebiegów
	 * @return array<int, array{group: ?string, ops: array<int, array>, operations: array<int, array>}>
	 */
	public function requests(array $set, array $operations, array $applied): array
	{
		$requests = [];
		$groups = [];
		$loose = [];

		foreach (array_values($operations) as $op) {
			if ($op['atomic_group'] !== null) {
				$groups[$op['atomic_group']][] = $op;
			} else {
				$loose[] = $op;
			}
		}

		foreach ($groups as $group => $ops) {
			$resolved = $applied;
			$temp = -1;

			foreach ($ops as $op) {
				if ($op['temp_ref'] !== null) {
					$resolved['ref:' . $op['temp_ref']] = OperationCatalog::tempResourceName((string) $set['customer_id'], OperationCatalog::resource($op['type']), $temp--);
				}
			}

			$requests[] = ['group' => (string) $group, 'ops' => $ops, 'operations' => array_map(fn ($op) => OperationCatalog::build($op, (string) $set['customer_id'], $resolved), $ops)];
		}

		foreach (array_chunk($loose, 500) as $chunk) {
			$requests[] = ['group' => null, 'ops' => $chunk, 'operations' => array_map(fn ($op) => OperationCatalog::build($op, (string) $set['customer_id'], $applied), $chunk)];
		}

		return $requests;
	}

	public static function budgetImpact(array $operations): ?int
	{
		$impacts = array_filter(array_map(fn ($op) => OperationCatalog::budgetImpact($op), $operations), fn ($v) => $v !== null);

		return $impacts === [] ? null : array_sum($impacts);
	}

	// --- Pomocnicze -----------------------------------------------------------------------------

	private function requirePrepare(Project $project): void
	{
		if (! $this->guard->projectMode($project)->canPrepare()) {
			throw new ValidationFailed(['mode' => 'Tryb „tylko odczyt” – przygotowywanie zmian Google Ads jest wyłączone w konfiguracji serwera.']);
		}

		if (! Policy::allows($project->role, Ability::PrepareChanges)) {
			throw new ValidationFailed(['permission' => 'Brak uprawnień do przygotowywania zmian.']);
		}
	}

	private function editable(Project $project, int $setId): array
	{
		$this->requirePrepare($project);
		$set = $this->find($project, $setId);

		if (! in_array($set['status'], ChangeStatus::EDITABLE, true)) {
			throw new ValidationFailed(['status' => 'Zestaw w statusie „' . ChangeStatus::LABELS[$set['status']] . '” nie może być edytowany. Odeślij go do poprawek albo przygotuj nowy.']);
		}

		return $set;
	}

	/**
	 * Połączenie zestawu – musi być aktywne i takie samo jak w chwili przygotowania (konto nie może zostać podmienione).
	 */
	private function boundConnection(array $set): array
	{
		$connection = $this->connections->currentForProject((int) $set['project_id']);

		if ($set['connection_id'] === null || $connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
			throw new ValidationFailed(['connection' => 'Projekt nie ma aktywnego połączenia z Google Ads, dla którego przygotowano ten zestaw.']);
		}

		if ((int) $connection['id'] !== (int) $set['connection_id'] || (string) $connection['customer_id'] !== (string) $set['customer_id']) {
			throw new ValidationFailed(['connection' => 'Projekt jest teraz połączony z innym kontem Google Ads niż to, dla którego przygotowano zmiany.']);
		}

		return $connection;
	}

	/**
	 * Zmiana treści zestawu: nowa wersja, usunięcie zatwierdzenia (wymaga ponownego przeglądu).
	 */
	private function invalidate(Project $project, array $set, int $userId, string $event, ?string $note, ?int $operationId = null): void
	{
		$this->sets->update((int) $set['id'], ['version' => (int) $set['version'] + 1, 'approved_hash' => null, 'approved_version' => null, 'approved_by' => null, 'approved_at' => null]);
		$this->sets->addEvent((int) $set['id'], $project->id, $event, $userId, null, null, $note, $operationId);
	}

	/**
	 * Kontekst walidacji z danych synchronizacji (gdy brak świeżego odczytu z API).
	 */
	private function syncContext(Project $project, array $set): array
	{
		$t = fn (string $name) => $this->db->table($name);
		$keywords = [];

		$byCampaign = [];

		foreach ($this->db->select("SELECT k.text, k.match_type, k.status, g.external_id AS ad_group_external, c.external_id AS campaign_external FROM {$t('keywords')} k LEFT JOIN {$t('ad_groups')} g ON g.id = k.ad_group_id LEFT JOIN {$t('campaigns')} c ON c.id = k.campaign_id WHERE k.project_id = ?", [$project->id]) as $row) {
			$keyword = ['text' => mb_strtolower((string) $row['text']), 'match_type' => strtoupper((string) $row['match_type']), 'status' => $row['status']];
			$keywords[(string) $row['ad_group_external']][] = $keyword;
			$byCampaign[(string) $row['campaign_external']][] = $keyword;
		}

		// Wykluczenia z synchronizacji: poziom kampanii i grupy (listy wspólne pomijamy – brak mapowania na kampanie).
		$negatives = [];

		foreach ($this->db->select("SELECT n.level, n.text, n.match_type, c.external_id AS campaign_external, g.external_id AS ad_group_external FROM {$t('negative_keywords')} n LEFT JOIN {$t('campaigns')} c ON c.id = n.campaign_id LEFT JOIN {$t('ad_groups')} g ON g.id = n.ad_group_id WHERE n.project_id = ? AND n.level IN ('campaign', 'ad_group')", [$project->id]) as $row) {
			$key = $row['level'] === 'campaign' ? 'c:' . $row['campaign_external'] : 'g:' . $row['ad_group_external'];
			$negatives[$key][] = ['text' => mb_strtolower((string) $row['text']), 'match_type' => strtoupper((string) $row['match_type']), 'level' => $row['level']];
		}

		$groupCampaign = array_column($this->db->select("SELECT g.external_id AS ad_group_external, c.external_id AS campaign_external FROM {$t('ad_groups')} g INNER JOIN {$t('campaigns')} c ON c.id = g.campaign_id WHERE g.project_id = ? AND g.external_id IS NOT NULL", [$project->id]), 'campaign_external', 'ad_group_external');

		return [
			'negatives' => $negatives,
			'group_campaign' => $groupCampaign,
			'base' => [
				'customer_id' => $set['customer_id'],
				'currency' => $project->currency,
				'measurement_reliable' => $this->planner->measurement($project)['reliable'],
			],
			'keywords' => $keywords,
			'campaign_keywords' => $byCampaign,
			'campaign_names' => $this->db->column("SELECT name FROM {$t('campaigns')} WHERE project_id = ? AND status <> 'removed'", [$project->id]),
			'conversion_names' => $this->db->column("SELECT name FROM {$t('conversion_actions')} WHERE project_id = ?", [$project->id]),
		];
	}

	private function opContext(array $context, array $op): array
	{
		$payload = $op['payload'];
		$out = [];

		if ($op['type'] === 'campaign.create') {
			$out['existing_names'] = $context['campaign_names'];
		} elseif ($op['type'] === 'conversion_action.create') {
			$out['existing_names'] = $context['conversion_names'];
		} elseif ($op['type'] === 'ad_group_criterion.create' && isset($payload['ad_group']) && ! str_starts_with((string) $payload['ad_group'], 'ref:')) {
			$rows = $context['keywords'][ResourceName::id((string) $payload['ad_group'])] ?? [];
			$out['existing_keywords'] = empty($payload['negative']) ? array_map(fn ($k) => $k['text'] . '|' . $k['match_type'], $rows) : [];
			$out['active_keywords'] = array_values(array_filter($rows, fn ($k) => $k['status'] === 'enabled'));

			if (empty($payload['negative'])) {
				$groupId = ResourceName::id((string) $payload['ad_group']);
				$campaignId = (string) ($context['group_campaign'][$groupId] ?? '');
				$out['negatives'] = array_merge($context['negatives']['g:' . $groupId] ?? [], $campaignId !== '' ? ($context['negatives']['c:' . $campaignId] ?? []) : []);
			}
		} elseif ($op['type'] === 'campaign_criterion.create' && ($payload['kind'] ?? '') === 'negative_keyword' && isset($payload['campaign']) && ! str_starts_with((string) $payload['campaign'], 'ref:')) {
			$out['active_keywords'] = array_values(array_filter($context['campaign_keywords'][ResourceName::id((string) $payload['campaign'])] ?? [], fn ($k) => $k['status'] === 'enabled'));
		}

		return $out;
	}

	/**
	 * Aktywne słowa kluczowe, które zablokowałoby wykluczenie (reguły Google: bez wariantów odmiany).
	 *
	 * @return array<int, string>
	 */
	private function blockedKeywords(array $op, array $context): array
	{
		$payload = $op['payload'];
		$blocked = [];

		foreach ((array) ($context['active_keywords'] ?? []) as $keyword) {
			if (NegativeConflictChecker::blocks((string) $payload['text'], strtolower((string) $payload['match_type']), (string) $keyword['text'])) {
				$blocked[] = (string) $keyword['text'];
			}
		}

		return array_values(array_unique($blocked));
	}

	private static function cleanPayload(array $payload): array
	{
		$out = [];

		foreach ($payload as $key => $value) {
			if (! is_string($key) || ! preg_match('/^[a-z_0-9]+$/', $key)) {
				continue;
			}

			$out[$key] = is_string($value) ? trim(wp_strip_all_tags($value)) : (is_array($value) ? array_values(array_map(fn ($v) => is_string($v) ? trim(wp_strip_all_tags($v)) : $v, $value)) : $value);
		}

		return $out;
	}

	private static function ksortRecursive(array $value): array
	{
		if (! array_is_list($value)) {
			ksort($value);
		}

		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = self::ksortRecursive($item);
			}
		}

		return $value;
	}

	private static function text(mixed $value, int $max): ?string
	{
		$value = trim(wp_strip_all_tags((string) $value));

		return $value === '' ? null : mb_substr($value, 0, $max);
	}

	private static function firstError(array $validation): string
	{
		$first = $validation['set_errors'][0] ?? (array_values($validation['errors'])[0][0] ?? 'nieznany błąd');

		return rtrim($first, '.') . '.';
	}
}
