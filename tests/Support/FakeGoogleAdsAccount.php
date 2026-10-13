<?php

namespace Tests\Support;

/**
 * Atrapa konta Google Ads w pamięci – na potrzeby testów Change Center (wyłącznie lokalnie, bez sieci).
 *
 * - odpowiada na zapytania GAQL odczytu stanu (StateQueries): SELECT … FROM zasób WHERE pole = / != / IN,
 * - wykonuje `googleAds:mutate` (create / update z updateMask / remove, tymczasowe ID w jednym żądaniu,
 *   partial_failure, validate_only, transakcyjność żądania bez partial_failure),
 * - pozwala wstrzyknąć błędy: odrzucenie operacji, timeout (z zapisem albo bez), błąd serwera, limit zapytań.
 *
 * Zachowanie jest uproszczonym modelem dokumentacji Google Ads API v25 – testy na tej atrapie NIE potwierdzają
 * działania z prawdziwym API.
 */
final class FakeGoogleAdsAccount
{
	public const CUSTOMER = GoogleAdsMock::CUSTOMER;

	private const OPERATIONS = [
		'campaignBudgetOperation' => 'campaign_budget', 'campaignOperation' => 'campaign', 'campaignCriterionOperation' => 'campaign_criterion',
		'adGroupOperation' => 'ad_group', 'adGroupCriterionOperation' => 'ad_group_criterion', 'adGroupAdOperation' => 'ad_group_ad',
		'conversionActionOperation' => 'conversion_action', 'customerConversionGoalOperation' => 'customer_conversion_goal',
		'campaignConversionGoalOperation' => 'campaign_conversion_goal',
	];

	private const COLLECTIONS = [
		'campaign_budget' => 'campaignBudgets', 'campaign' => 'campaigns', 'campaign_criterion' => 'campaignCriteria', 'ad_group' => 'adGroups',
		'ad_group_criterion' => 'adGroupCriteria', 'ad_group_ad' => 'adGroupAds', 'conversion_action' => 'conversionActions',
		'customer_conversion_goal' => 'customerConversionGoals', 'campaign_conversion_goal' => 'campaignConversionGoals', 'language_constant' => 'languageConstants',
	];

	/** @var array<string, array<string, array<string, mixed>>> zasób => resource name => pola płaskie („campaign.status” => …) */
	public array $store = [];

	/** @var array<int, array{validate_only: bool, partial_failure: bool, operations: int, outcome: string}> */
	public array $mutations = [];

	/** Odrzucenie operacji: fn(string $resource, string $action, array $object, int $index): ?string (komunikat błędu). */
	public ?\Closure $reject = null;

	/** Jednorazowa awaria transportu: ['mode' => timeout|http500|quota|auth, 'apply' => czy Google zdążył zapisać]. */
	public ?array $failNext = null;

	private int $nextId = 7000;

	public function __construct()
	{
		foreach (['pl' => 1030, 'en' => 1000, 'de' => 1001, 'cs' => 1021, 'uk' => 1036] as $code => $id) {
			$this->put('language_constant', 'languageConstants/' . $id, ['code' => $code, 'name' => $code, 'id' => (string) $id]);
		}
	}

	public function install(GoogleAdsMock $mock): self
	{
		foreach (array_keys(self::COLLECTIONS) as $resource) {
			$mock->on($resource, fn (array $request) => $this->search((string) $request['gaql']));
		}

		$mock->on('mutate', fn (array $request) => $this->mutate((string) $request['body']));

		return $this;
	}

	/**
	 * Stan atrapy między żądaniami HTTP (testy formularzy na serwerze PHP).
	 */
	public function export(): array
	{
		return ['store' => $this->store, 'mutations' => $this->mutations, 'next_id' => $this->nextId];
	}

	public function import(array $state): self
	{
		$this->store = $state['store'] ?? $this->store;
		$this->mutations = $state['mutations'] ?? [];
		$this->nextId = (int) ($state['next_id'] ?? $this->nextId);

		return $this;
	}

	// --- Dane startowe -------------------------------------------------------------------------------

	public function budget(string $id, int $amountMicros, bool $shared = false): string
	{
		return $this->put('campaign_budget', $this->name('campaign_budget', $id), ['id' => $id, 'name' => 'Budżet ' . $id, 'amount_micros' => (string) $amountMicros, 'explicitly_shared' => $shared, 'status' => 'ENABLED', 'period' => 'DAILY']);
	}

	public function campaign(string $id, string $name, string $budget, array $fields = []): string
	{
		return $this->put('campaign', $this->name('campaign', $id), $fields + [
			'id' => $id, 'name' => $name, 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH', 'bidding_strategy_type' => 'MAXIMIZE_CONVERSIONS',
			'campaign_budget' => $budget, 'network_settings.target_google_search' => true, 'network_settings.target_search_network' => true,
			'network_settings.target_content_network' => true,
		]);
	}

	public function adGroup(string $id, string $campaignId, string $name): string
	{
		return $this->put('ad_group', $this->name('ad_group', $id), ['id' => $id, 'name' => $name, 'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD', 'campaign' => $this->name('campaign', $campaignId)]);
	}

	public function keyword(string $adGroupId, string $criterionId, string $text, string $match, string $status = 'ENABLED', bool $negative = false): string
	{
		return $this->put('ad_group_criterion', $this->name('ad_group_criterion', $adGroupId . '~' . $criterionId), [
			'criterion_id' => $criterionId, 'status' => $status, 'negative' => $negative, 'type' => 'KEYWORD', 'keyword.text' => $text, 'keyword.match_type' => $match,
			'ad_group' => $this->name('ad_group', $adGroupId),
		]);
	}

	public function campaignNegative(string $campaignId, string $criterionId, string $text, string $match): string
	{
		return $this->put('campaign_criterion', $this->name('campaign_criterion', $campaignId . '~' . $criterionId), [
			'criterion_id' => $criterionId, 'type' => 'KEYWORD', 'negative' => true, 'status' => 'ENABLED', 'keyword.text' => $text, 'keyword.match_type' => $match,
			'campaign' => $this->name('campaign', $campaignId),
		]);
	}

	public function conversionAction(string $id, string $name, array $fields = []): string
	{
		return $this->put('conversion_action', $this->name('conversion_action', $id), $fields + [
			'id' => $id, 'name' => $name, 'type' => 'WEBPAGE', 'origin' => 'WEBSITE', 'category' => 'SUBMIT_LEAD_FORM', 'status' => 'ENABLED',
			'primary_for_goal' => true, 'counting_type' => 'ONE_PER_CLICK', 'include_in_conversions_metric' => true, 'click_through_lookback_window_days' => '30',
			'owner_customer' => 'customers/' . self::CUSTOMER,
		]);
	}

	public function customerGoal(string $category, string $origin, bool $biddable): string
	{
		return $this->put('customer_conversion_goal', $this->name('customer_conversion_goal', $category . '~' . $origin), ['category' => $category, 'origin' => $origin, 'biddable' => $biddable]);
	}

	public function campaignGoal(string $campaignId, string $category, string $origin, bool $biddable): string
	{
		$campaign = $this->get('campaign', $this->name('campaign', $campaignId)) ?? [];

		return $this->put('campaign_conversion_goal', $this->name('campaign_conversion_goal', $campaignId . '~' . $category . '~' . $origin), [
			'campaign' => $this->name('campaign', $campaignId), 'category' => $category, 'origin' => $origin, 'biddable' => $biddable,
			'campaign.id' => $campaignId, 'campaign.name' => $campaign['campaign.name'] ?? 'Kampania', 'campaign.status' => $campaign['campaign.status'] ?? 'ENABLED',
		]);
	}

	/**
	 * Zmiana „poza Penny Pincher” (np. ręcznie w interfejsie Google Ads) – do testów wykrywania dryfu.
	 */
	public function set(string $resource, string $resourceName, string $field, mixed $value): void
	{
		$this->store[$resource][$resourceName][$resource . '.' . $field] = $value;
	}

	/**
	 * @return array<string, mixed>|null pola płaskie
	 */
	public function get(string $resource, string $resourceName): ?array
	{
		return $this->store[$resource][$resourceName] ?? null;
	}

	public function value(string $resource, string $resourceName, string $field): mixed
	{
		return $this->store[$resource][$resourceName][$resource . '.' . $field] ?? null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function all(string $resource): array
	{
		return $this->store[$resource] ?? [];
	}

	/**
	 * Wysłane żądania wykonania (bez walidacji validate_only), także te, które nie dotarły do Google.
	 */
	public function sent(): int
	{
		return count(array_filter($this->mutations, fn ($m) => ! $m['validate_only']));
	}

	/**
	 * Wykonania, które dotarły do Google (zapisane, odrzucone albo z utraconą odpowiedzią).
	 */
	public function executedMutations(): int
	{
		return count(array_filter($this->mutations, fn ($m) => ! $m['validate_only'] && $m['outcome'] !== 'transport_failed_before'));
	}

	public function name(string $resource, string $id): string
	{
		return 'customers/' . self::CUSTOMER . '/' . self::COLLECTIONS[$resource] . '/' . $id;
	}

	// --- GAQL -------------------------------------------------------------------------------------------

	private function search(string $gaql): array
	{
		$gaql = trim((string) preg_replace('/\s+/', ' ', $gaql));

		if (! preg_match('/^SELECT (.+?) FROM (\w+)(?: WHERE (.+))?$/i', $gaql, $m)) {
			return [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Atrapa: nieobsługiwane zapytanie.']]];
		}

		$fields = array_map('trim', explode(',', $m[1]));
		$resource = $m[2];
		$conditions = isset($m[3]) ? preg_split('/\s+AND\s+/i', $m[3]) : [];
		$rows = [];

		foreach ($this->store[$resource] ?? [] as $name => $record) {
			$record = $this->computed($resource, $name, $record);

			if (! $this->matches($record, $conditions)) {
				continue;
			}

			$row = [];

			foreach ($fields as $field) {
				if (array_key_exists($field, $record) && $record[$field] !== null) {
					self::setNested($row, array_map([self::class, 'camel'], explode('.', $field)), $record[$field]);
				}
			}

			$rows[] = $row;
		}

		return [200, GoogleAdsMock::stream($rows)];
	}

	private function computed(string $resource, string $name, array $record): array
	{
		$record[$resource . '.resource_name'] = $name;

		if ($resource === 'campaign_budget') {
			$record['campaign_budget.reference_count'] = (string) count(array_filter($this->store['campaign'] ?? [], fn ($c) => ($c['campaign.campaign_budget'] ?? null) === $name && ($c['campaign.status'] ?? '') !== 'REMOVED'));
		}

		return $record;
	}

	private function matches(array $record, array $conditions): bool
	{
		foreach ($conditions as $condition) {
			$condition = trim($condition);

			if (preg_match("/^([a-z_.]+)\s*(=|!=)\s*'([^']*)'$/i", $condition, $c)) {
				$actual = $record[$c[1]] ?? null;
				$actual = is_bool($actual) ? ($actual ? 'TRUE' : 'FALSE') : (string) $actual;
				$ok = $c[2] === '=' ? $actual === $c[3] : $actual !== $c[3];
			} elseif (preg_match("/^([a-z_.]+)\s+IN\s*\((.+)\)$/i", $condition, $c)) {
				preg_match_all("/'([^']*)'/", $c[2], $values);
				$ok = in_array((string) ($record[$c[1]] ?? ''), $values[1], true);
			} else {
				throw new \RuntimeException('Atrapa: nieobsługiwany warunek ' . $condition);
			}

			if (! $ok) {
				return false;
			}
		}

		return true;
	}

	// --- Mutate ------------------------------------------------------------------------------------------

	private function mutate(string $body): array|\WP_Error
	{
		$request = json_decode($body, true) ?: [];
		$validateOnly = (bool) ($request['validateOnly'] ?? false);
		$partial = (bool) ($request['partialFailure'] ?? false);
		$operations = (array) ($request['mutateOperations'] ?? []);
		$fail = $validateOnly ? null : $this->failNext;
		$this->failNext = $validateOnly ? $this->failNext : null;

		if ($fail !== null && empty($fail['apply'])) {
			$this->mutations[] = ['validate_only' => false, 'partial_failure' => $partial, 'operations' => count($operations), 'outcome' => 'transport_failed_before'];

			return $this->transportFailure($fail['mode']);
		}

		$snapshot = $this->store;
		$nextId = $this->nextId;
		$temp = [];
		$responses = [];
		$errors = [];

		foreach ($operations as $i => $operation) {
			[$key, $body] = [array_key_first($operation), reset($operation)];
			$resource = self::OPERATIONS[$key] ?? null;
			$action = isset($body['create']) ? 'create' : (isset($body['update']) ? 'update' : 'remove');

			try {
				if ($resource === null) {
					throw new \DomainException('Atrapa: nieobsługiwana operacja ' . $key);
				}

				if ($this->reject !== null && ($message = ($this->reject)($resource, $action, (array) ($body[$action] ?? []), $i)) !== null) {
					throw new \DomainException($message);
				}

				$name = match ($action) {
					'create' => $this->applyCreate($resource, (array) $body['create'], $temp),
					'update' => $this->applyUpdate($resource, (array) $body['update'], (string) ($body['updateMask'] ?? ''), $temp),
					default => $this->applyRemove($resource, $this->resolve((string) $body['remove'], $temp)),
				};
				$responses[$i] = [lcfirst(str_replace('Operation', '', $key)) . 'Result' => ['resourceName' => $name]];
			} catch (\DomainException $e) {
				$errors[$i] = $e->getMessage();
				$responses[$i] = new \stdClass();
			}
		}

		$failure = $errors === [] ? null : [
			'code' => 3, 'message' => 'Multiple errors in mutate operations.',
			'details' => [['@type' => 'type.googleapis.com/google.ads.googleads.v25.errors.GoogleAdsFailure', 'requestId' => 'fake-request', 'errors' => array_map(
				fn ($i, $message) => ['errorCode' => ['mutateError' => 'FAKE_REJECTED'], 'message' => $message, 'location' => ['fieldPathElements' => [['fieldName' => 'mutate_operations', 'index' => $i]]]],
				array_keys($errors),
				$errors
			)]],
		];

		// Walidacja albo żądanie atomowe z błędem: nic nie zostaje zapisane.
		if ($validateOnly || ($errors !== [] && ! $partial)) {
			$this->store = $snapshot;
			$this->nextId = $nextId;
		}

		$this->mutations[] = ['validate_only' => $validateOnly, 'partial_failure' => $partial, 'operations' => count($operations), 'outcome' => $errors === [] ? 'ok' : ($partial ? 'partial' : 'rejected')];

		if ($fail !== null) {
			return $this->transportFailure($fail['mode']); // Google zapisał zmiany, ale odpowiedź nie dotarła
		}

		if ($errors !== [] && ! $partial) {
			return [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Request contains an invalid argument.'] + ['details' => $failure['details']]], ['request-id' => 'fake-request']];
		}

		if ($validateOnly) {
			return [200, $failure !== null ? ['partialFailureError' => $failure] : new \stdClass(), ['request-id' => 'fake-validate']];
		}

		return [200, array_filter(['mutateOperationResponses' => array_values($responses), 'partialFailureError' => $failure]), ['request-id' => 'fake-request']];
	}

	private function transportFailure(string $mode): array|\WP_Error
	{
		return match ($mode) {
			'timeout' => new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'),
			'http500' => [500, ['error' => ['code' => 500, 'status' => 'INTERNAL', 'message' => 'Internal error encountered.']]],
			'quota' => [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Resource has been exhausted (e.g. check quota).']]],
			'auth' => [401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED', 'message' => 'Request had invalid authentication credentials.']]],
			default => throw new \InvalidArgumentException($mode),
		};
	}

	private function applyCreate(string $resource, array $object, array &$temp): string
	{
		$fields = [];
		self::flatten($object, $resource, $fields);
		unset($fields[$resource . '.resource_name']);

		foreach ($fields as $field => $value) {
			if (is_string($value) && str_starts_with($value, 'customers/')) {
				$fields[$field] = $this->resolve($value, $temp);
			}
		}

		$id = (string) $this->nextId++;
		$parent = fn (string $field) => (string) preg_replace('#^.*/#', '', (string) ($fields[$resource . '.' . $field] ?? ''));

		[$key, $extra] = match ($resource) {
			'campaign_criterion' => [$parent('campaign') . '~' . $id, ['criterion_id' => $id]],
			'ad_group_criterion' => [$parent('ad_group') . '~' . $id, ['criterion_id' => $id, 'type' => 'KEYWORD']],
			'ad_group_ad' => [$parent('ad_group') . '~' . $id, ['ad.id' => $id, 'ad.type' => 'RESPONSIVE_SEARCH_AD']],
			default => [$id, ['id' => $id]],
		};

		if ($resource === 'campaign_criterion') {
			$extra['type'] = isset($fields['campaign_criterion.location.geo_target_constant']) ? 'LOCATION' : (isset($fields['campaign_criterion.language.language_constant']) ? 'LANGUAGE' : 'KEYWORD');
		}

		if ($resource === 'campaign') {
			$extra['bidding_strategy_type'] = isset($object['manualCpc']) ? 'MANUAL_CPC' : (isset($object['targetSpend']) ? 'TARGET_SPEND' : (isset($object['maximizeConversions']) ? 'MAXIMIZE_CONVERSIONS' : 'UNKNOWN'));
		}

		if ($resource === 'ad_group_criterion' && ($fields['ad_group_criterion.negative'] ?? false) !== true) {
			foreach ($this->store['ad_group_criterion'] ?? [] as $existing) {
				if (($existing['ad_group_criterion.ad_group'] ?? '') === ($fields['ad_group_criterion.ad_group'] ?? '') && ($existing['ad_group_criterion.status'] ?? '') !== 'REMOVED'
					&& mb_strtolower((string) ($existing['ad_group_criterion.keyword.text'] ?? '')) === mb_strtolower((string) ($fields['ad_group_criterion.keyword.text'] ?? ''))
					&& ($existing['ad_group_criterion.keyword.match_type'] ?? '') === ($fields['ad_group_criterion.keyword.match_type'] ?? '') && ! ($existing['ad_group_criterion.negative'] ?? false)) {
					throw new \DomainException('Atrapa: słowo kluczowe już istnieje w grupie (DUPLICATE).');
				}
			}
		}

		$name = $this->name($resource, $key);
		$defaults = ['status' => 'ENABLED'];

		foreach ($extra + $defaults as $field => $value) {
			$fields[$resource . '.' . $field] ??= $value;
		}

		if (isset($object['resourceName']) && preg_match('#/(-\d+)$#', (string) $object['resourceName'])) {
			$temp[(string) $object['resourceName']] = $name;
		}

		$this->store[$resource][$name] = $fields;

		return $name;
	}

	private function applyUpdate(string $resource, array $object, string $mask, array $temp): string
	{
		$name = $this->resolve((string) ($object['resourceName'] ?? ''), $temp);

		if (! isset($this->store[$resource][$name]) || ($this->store[$resource][$name][$resource . '.status'] ?? '') === 'REMOVED') {
			throw new \DomainException('Atrapa: zasób nie istnieje (RESOURCE_NOT_FOUND): ' . $name);
		}

		foreach (array_filter(explode(',', $mask)) as $path) {
			$value = $object;

			foreach (explode('.', $path) as $segment) {
				$value = is_array($value) && array_key_exists($segment, $value) ? $value[$segment] : null;
			}

			$field = $resource . '.' . implode('.', array_map([self::class, 'snake'], explode('.', $path)));

			if ($resource === 'campaign' && in_array(explode('.', $path)[0], ['manualCpc', 'targetSpend', 'maximizeConversions'], true)) {
				$this->store[$resource][$name]['campaign.bidding_strategy_type'] = strtoupper(self::snake(explode('.', $path)[0]));

				if (! str_contains($path, '.')) {
					continue;
				}
			}

			$this->store[$resource][$name][$field] = $value;
		}

		return $name;
	}

	private function applyRemove(string $resource, string $name): string
	{
		if (! isset($this->store[$resource][$name])) {
			throw new \DomainException('Atrapa: zasób nie istnieje (RESOURCE_NOT_FOUND): ' . $name);
		}

		$this->store[$resource][$name][$resource . '.status'] = 'REMOVED';

		return $name;
	}

	private function resolve(string $name, array $temp): string
	{
		if (preg_match('#/-\d+$#', $name)) {
			return $temp[$name] ?? throw new \DomainException('Atrapa: nierozwiązany tymczasowy identyfikator ' . $name);
		}

		return $name;
	}

	private function put(string $resource, string $name, array $fields): string
	{
		$flat = [];

		foreach ($fields as $field => $value) {
			$flat[$resource . '.' . $field] = $value;
		}

		$this->store[$resource][$name] = $flat;

		return $name;
	}

	private static function flatten(array $object, string $prefix, array &$out): void
	{
		foreach ($object as $key => $value) {
			$path = $prefix . '.' . self::snake((string) $key);

			if (is_array($value) && $value !== [] && ! array_is_list($value)) {
				self::flatten($value, $path, $out);
			} elseif (! (is_array($value) && $value === [])) {
				$out[$path] = $value;
			}
		}
	}

	private static function setNested(array &$row, array $keys, mixed $value): void
	{
		$ref = &$row;

		foreach ($keys as $i => $key) {
			if ($i === count($keys) - 1) {
				$ref[$key] = $value;
			} else {
				$ref[$key] ??= [];
				$ref = &$ref[$key];
			}
		}
	}

	private static function camel(string $snake): string
	{
		return lcfirst(str_replace('_', '', ucwords($snake, '_')));
	}

	private static function snake(string $camel): string
	{
		return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
	}
}
