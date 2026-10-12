<?php

namespace App\PennyPincher\Services\DataForSeo;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Keywords\Concepts;
use App\PennyPincher\Domain\Keywords\PhraseQuality;
use App\PennyPincher\Domain\Keywords\SeedSelector;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoClient;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoConfig;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoException;
use App\PennyPincher\Repositories\DataForSeoRepository;
use App\PennyPincher\Repositories\KeywordIdeaRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\Analysis\AnalysisDataBuilder;
use App\PennyPincher\Services\Analysis\AnalysisService;
use Throwable;

/**
 * Pobieranie pomysłów słów kluczowych z DataForSEO.
 *
 * Zasady:
 * - płatne zapytanie powstaje WYŁĄCZNIE po ręcznym potwierdzeniu użytkownika (zgoda + zaakceptowany szacunek kosztu),
 * - przed zapytaniem sprawdzany jest miesięczny limit projektu (wymagany) i opcjonalny limit organizacji,
 * - zapytanie wykonuje worker (`pp:sync:work`), nigdy wyświetlenie strony,
 * - identyczne zapytanie w okresie ważności pamięci podręcznej nie jest ponownie płatne,
 * - koszt faktyczny pochodzi z odpowiedzi API (pole `cost`).
 */
class DataForSeoService
{
	public const MAX_ATTEMPTS = 3;

	public function __construct(
		private DataForSeoConfig $config,
		private DataForSeoClient $client,
		private DataForSeoRepository $tasks,
		private KeywordIdeaRepository $ideas,
		private ProjectRepository $projects,
		private AnalysisDataBuilder $data,
		private \App\PennyPincher\Infrastructure\Database\Db $db,
	) {
	}

	/**
	 * Zapis własnego zestawu fraz źródłowych (maks. 20, każda maks. 80 znaków). Fraza z listy automatycznej
	 * zachowuje źródło „auto”, pozostałe są „manual” (nie są automatycznie odrzucane).
	 *
	 * @param array<int, string> $phrases
	 * @param array<int, string> $autoPhrases frazy z ostatniej propozycji automatycznej
	 */
	public function saveSeeds(Project $project, array $phrases, array $autoPhrases, int $userId): array
	{
		$auto = array_flip(array_map(fn ($p) => Text::normalize($p), $autoPhrases));
		$seeds = [];
		$errors = [];

		foreach ($phrases as $phrase) {
			$phrase = mb_strtolower(SeedSelector::clean(wp_strip_all_tags((string) $phrase)));
			$key = Text::normalize($phrase);

			if ($key === '' || isset($seeds[$key])) {
				continue;
			}

			if (mb_strlen($phrase) > DataForSeoClient::MAX_SEED_LENGTH) {
				$errors['seeds'] = 'Fraza „' . mb_substr($phrase, 0, 40) . '…” jest dłuższa niż 80 znaków.';
				continue;
			}

			$seeds[$key] = ['phrase' => $phrase, 'source' => isset($auto[$key]) ? 'auto' : 'manual'];
		}

		if (count($seeds) > DataForSeoClient::MAX_SEEDS) {
			$errors['seeds'] = sprintf('Maksymalnie %d fraz na jedno zlecenie (podano %d).', DataForSeoClient::MAX_SEEDS, count($seeds));
		}

		if ($seeds === [] && $errors === []) {
			$errors['seeds'] = 'Dodaj co najmniej jedną frazę albo przywróć propozycje automatyczne.';
		}

		if ($errors !== []) {
			throw new ValidationFailed($errors);
		}

		$state = $this->projects->seedState($project->id);
		$this->projects->saveSeedState($project->id, ['custom' => array_values($seeds), 'last_used' => $state['last_used'], 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $userId]);

		return array_values($seeds);
	}

	/**
	 * Przywrócenie propozycji automatycznych (usuwa własny zestaw; ostatnio użyty zostaje w historii).
	 */
	public function resetSeeds(Project $project): void
	{
		$state = $this->projects->seedState($project->id);
		$this->projects->saveSeedState($project->id, ['custom' => null, 'last_used' => $state['last_used'], 'updated_at' => gmdate('Y-m-d H:i:s')]);
	}

	/**
	 * Wczytanie ostatnio użytego zestawu jako własnego.
	 */
	public function restoreLastUsed(Project $project, int $userId): void
	{
		$state = $this->projects->seedState($project->id);

		if ($state['last_used'] === []) {
			throw new ValidationFailed(['seeds' => 'Brak wcześniej użytego zestawu fraz.']);
		}

		$this->projects->saveSeedState($project->id, ['custom' => $state['last_used'], 'last_used' => $state['last_used'], 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => $userId]);
	}

	public function isConfigured(): bool
	{
		return $this->config->isConfigured();
	}

	/**
	 * Bezpłatny test połączenia (dane konta i saldo).
	 *
	 * @return array{ok: bool, balance: ?float, currency: string, sandbox: bool, message: string}
	 */
	public function testConnection(): array
	{
		try {
			$user = $this->client->userData();

			return ['ok' => true, 'balance' => $user['balance'], 'currency' => 'USD', 'sandbox' => $this->config->sandbox(), 'message' => 'Połączenie z DataForSEO działa.'];
		} catch (DataForSeoException $e) {
			return ['ok' => false, 'balance' => null, 'currency' => 'USD', 'sandbox' => $this->config->sandbox(), 'message' => $e->getMessage()];
		}
	}

	/**
	 * Plan zapytania (bez wykonywania): frazy źródłowe, parametry, szacowany koszt i stan limitów.
	 */
	public function plan(Project $project, AnalysisPeriod $period): array
	{
		$leakContext = $this->data->leakContext($project, $period);
		$context = $project->context();
		$working = [];

		foreach ([...array_column($leakContext->keywords, 'text'), ...array_column($leakContext->campaigns, 'name'), ...array_column($leakContext->adGroups, 'name')] as $text) {
			array_push($working, ...Concepts::of((string) $text));
		}

		$auto = SeedSelector::analyze($context, $leakContext->terms, $leakContext->keywords, DataForSeoClient::MAX_SEEDS, array_values(array_unique($working)));
		$state = $this->projects->seedState($project->id);
		$seeds = $state['custom'] ?? $auto['seeds'];

		// Frazy dodane ręcznie nie są odrzucane – dostają tylko ostrzeżenie o możliwej niskiej trafności.
		foreach ($seeds as &$seed) {
			$flags = array_values(array_intersect(PhraseQuality::flags($seed['phrase'], $context, array_values(array_unique($working))), PhraseQuality::REJECT_AUTO));
			$seed['warning'] = ($seed['source'] ?? '') === 'manual' && $flags !== [] ? 'Możliwa niska trafność: ' . PhraseQuality::describe($flags) . '.' : null;
		}

		unset($seed);
		$endpoint = DataForSeoClient::KEYWORDS_FOR_KEYWORDS;
		$payload = DataForSeoClient::keywordsForKeywordsPayload(array_column($seeds, 'phrase'), $context->locationCode(), $context->language());
		$cacheKey = $this->cacheKey($endpoint, $payload);
		$cached = $seeds !== [] && $this->tasks->cacheGet($cacheKey) !== null;
		$estimate = $cached ? 0.0 : DataForSeoClient::ESTIMATES[$endpoint];

		return [
			'endpoint' => $endpoint,
			'seeds' => $seeds,
			'auto_seeds' => $auto['seeds'],
			'rejected_seeds' => $auto['rejected'],
			'custom' => $state['custom'] !== null,
			'last_used' => $state['last_used'],
			'seeds_updated_at' => $state['updated_at'],
			'payload' => $payload,
			'payload_hash' => sha1(wp_json_encode($payload)),
			'location_code' => $context->locationCode(),
			'language_code' => $context->language(),
			'cached' => $cached,
			'estimated_cost' => $estimate,
			'usage' => $this->usage($project),
			'blocked' => $seeds === [] ? 'Brak fraz źródłowych: uzupełnij usługi priorytetowe w kontekście biznesowym albo wybierz okres z konwertującymi hasłami.' : $this->budgetProblem($project, $estimate),
		];
	}

	/**
	 * Zlecenie zapytania po zgodzie użytkownika. Zwraca zadanie (istniejące, jeśli identyczne już czeka).
	 */
	public function request(Project $project, AnalysisPeriod $period, int $userId, bool $consent, ?string $acceptedEstimate = null): array
	{
		if (! $this->config->isConfigured()) {
			throw new ValidationFailed(['dataforseo' => DataForSeoException::MESSAGES[DataForSeoException::CONFIG]]);
		}

		if (! $consent) {
			throw new ValidationFailed(['consent' => 'Potwierdź zgodę na płatne zapytanie do DataForSEO.']);
		}

		$plan = $this->plan($project, $period);

		// Kontrola limitu i utworzenie zadania pod blokadą organizacji – równoległe zlecenia nie obejdą limitu.
		$lock = 'pp_dfs_org_' . $project->organizationId;

		if ((int) $this->db->scalar('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
			throw new ValidationFailed(['dataforseo' => 'Inne zlecenie DataForSEO jest właśnie zapisywane. Spróbuj ponownie za chwilę.']);
		}

		try {
			return $this->createTask($project, $plan, $userId, $acceptedEstimate);
		} finally {
			$this->db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
		}
	}

	private function createTask(Project $project, array $plan, int $userId, ?string $acceptedEstimate): array
	{
		// Limit liczony ponownie pod blokadą (plan mógł powstać przed zapisem innego zadania).
		$plan['blocked'] = $plan['seeds'] === [] ? 'Brak fraz źródłowych.' : $this->budgetProblem($project, $plan['estimated_cost']);

		if ($plan['blocked'] !== null) {
			throw new ValidationFailed(['dataforseo' => $plan['blocked']]);
		}

		// Użytkownik zaakceptował konkretny szacunek – jeśli koszt wzrósł od wyświetlenia formularza, pytamy ponownie.
		if ($acceptedEstimate !== null && is_numeric($acceptedEstimate) && $plan['estimated_cost'] > (float) $acceptedEstimate + 0.00001) {
			throw new ValidationFailed(['dataforseo' => 'Szacowany koszt zmienił się. Sprawdź go i potwierdź ponownie.']);
		}

		$active = $this->tasks->activeByHash($project->id, $plan['payload_hash']);

		if ($active !== null) {
			return $active;
		}

		$state = $this->projects->seedState($project->id);
		$this->projects->saveSeedState($project->id, ['custom' => $state['custom'], 'last_used' => array_map(fn ($s) => ['phrase' => $s['phrase'], 'source' => $s['source'] ?? 'auto'], $plan['seeds']), 'updated_at' => $state['updated_at']]);

		$id = $this->tasks->create([
			'project_id' => $project->id,
			'organization_id' => $project->organizationId,
			'endpoint' => $plan['endpoint'],
			'payload' => $plan['payload'],
			'payload_hash' => $plan['payload_hash'],
			'estimated_cost' => $plan['estimated_cost'],
			'requested_by' => $userId,
			'approved_at' => gmdate('Y-m-d H:i:s'),
		]);

		return $this->tasks->find($id);
	}

	/**
	 * @return array{project_spent: float, project_reserved: float, project_limit: ?float, organization_spent: float, organization_reserved: float, organization_limit: ?float, month: string}
	 */
	public function usage(Project $project): array
	{
		$month = gmdate('Y-m-01 00:00:00');
		$projectUsage = $this->tasks->monthlyUsage('project_id', $project->id, $month);
		$organizationUsage = $this->tasks->monthlyUsage('organization_id', $project->organizationId, $month);

		return [
			'project_spent' => $projectUsage['spent'],
			'project_reserved' => $projectUsage['reserved'],
			'project_limit' => $project->dataforseoMonthlyLimit,
			'organization_spent' => $organizationUsage['spent'],
			'organization_reserved' => $organizationUsage['reserved'],
			'organization_limit' => $this->config->organizationMonthlyLimit(),
			'month' => gmdate('Y-m'),
		];
	}

	/**
	 * Powód blokady płatnego zapytania (null = można).
	 */
	public function budgetProblem(Project $project, float $estimate, bool $includesOwnReservation = false): ?string
	{
		$usage = $this->usage($project);
		$extra = $includesOwnReservation ? 0.0 : $estimate;

		if ($estimate <= 0) {
			return null; // odpowiedź z pamięci podręcznej – bez kosztu
		}

		if ($usage['project_limit'] === null || $usage['project_limit'] <= 0) {
			return 'Ustaw miesięczny limit wydatków na DataForSEO w ustawieniach projektu (bez limitu płatne zapytania są zablokowane).';
		}

		if ($usage['project_spent'] + $usage['project_reserved'] + $extra > $usage['project_limit'] + 0.00001) {
			return sprintf('Zapytanie przekroczyłoby miesięczny limit projektu (%s USD; wykorzystano %s USD, zarezerwowano %s USD).', self::usd($usage['project_limit']), self::usd($usage['project_spent']), self::usd($usage['project_reserved']));
		}

		if ($usage['organization_limit'] !== null && $usage['organization_spent'] + $usage['organization_reserved'] + $extra > $usage['organization_limit'] + 0.00001) {
			return sprintf('Zapytanie przekroczyłoby miesięczny limit organizacji (%s USD).', self::usd($usage['organization_limit']));
		}

		return null;
	}

	/**
	 * Przetwarzanie kolejki przez worker – do upływu czasu.
	 *
	 * @return array{processed: int, requeued: int}
	 */
	public function work(int $seconds): array
	{
		$deadline = time() + $seconds;
		$requeued = $this->tasks->requeueStale();
		$processed = 0;

		if (! $this->config->isConfigured()) {
			return ['processed' => 0, 'requeued' => $requeued];
		}

		while (time() < $deadline && ($task = $this->tasks->claimNext()) !== null) {
			$this->process($task);
			$processed++;
		}

		return ['processed' => $processed, 'requeued' => $requeued];
	}

	public function process(array $task): array
	{
		$project = $this->projects->findById((int) $task['project_id']);

		if ($project === null) {
			$this->tasks->update((int) $task['id'], ['status' => DataForSeoRepository::FAILED, 'last_error' => 'Projekt nie istnieje.', 'finished_at' => gmdate('Y-m-d H:i:s')]);

			return $this->tasks->find((int) $task['id']);
		}

		$cacheKey = $this->cacheKey($task['endpoint'], $task['payload']);

		try {
			$raw = $this->tasks->cacheGet($cacheKey);
			$fromCache = $raw !== null;
			$cost = 0.0;

			if (! $fromCache) {
				// Limit sprawdzamy ponownie tuż przed płatnym zapytaniem (mogły w międzyczasie powstać inne zadania).
				$reserved = (float) $task['estimated_cost'];
				$estimate = $reserved > 0 ? $reserved : (DataForSeoClient::ESTIMATES[$task['endpoint']] ?? 0.1);
				$problem = $this->budgetProblem($project, $estimate, $reserved > 0);

				if ($problem !== null) {
					throw new DataForSeoException($problem, DataForSeoException::BUDGET);
				}

				$response = $this->client->call($task['endpoint'], $task['payload']);
				$raw = $response['raw'];
				$cost = $response['cost'] ?? $estimate; // brak pola cost → liczymy szacunek (ostrożnie)
				$this->tasks->cachePut($cacheKey, $task['endpoint'], $raw, $cost, $this->config->cacheDays());
			}

			$items = DataForSeoClient::parseKeywordItems($raw);
			$this->storeIdeas($project, $task, $items);

			$this->tasks->update((int) $task['id'], [
				'status' => DataForSeoRepository::COMPLETED,
				'cost' => $cost,
				'from_cache' => $fromCache ? 1 : 0,
				'result_count' => count($items),
				'last_error' => null,
				'error_code' => null,
				'next_attempt_at' => null,
				'finished_at' => gmdate('Y-m-d H:i:s'),
			]);

			$this->refreshAnalysis($project);
		} catch (DataForSeoException $e) {
			$retry = $e->retryable() && (int) $task['attempts'] < self::MAX_ATTEMPTS;

			$this->tasks->update((int) $task['id'], [
				'status' => $retry ? DataForSeoRepository::QUEUED : DataForSeoRepository::FAILED,
				'last_error' => $e->getMessage(),
				'error_code' => mb_substr($e->code(), 0, 20),
				'next_attempt_at' => $retry ? gmdate('Y-m-d H:i:s', time() + 60 * (2 ** max(0, (int) $task['attempts'] - 1))) : null,
				'finished_at' => $retry ? null : gmdate('Y-m-d H:i:s'),
				'cost' => $retry ? null : 0,
			]);
		} catch (Throwable $e) {
			error_log('[Penny Pincher] DataForSEO zadanie #' . $task['id'] . ': ' . $e->getMessage());
			$this->tasks->update((int) $task['id'], ['status' => DataForSeoRepository::FAILED, 'last_error' => 'Nieoczekiwany błąd. Szczegóły są w logu serwera.', 'finished_at' => gmdate('Y-m-d H:i:s')]);
		}

		return $this->tasks->find((int) $task['id']);
	}

	private function storeIdeas(Project $project, array $task, array $items): void
	{
		$payload = $task['payload'][0] ?? [];
		$now = gmdate('Y-m-d H:i:s');
		$rows = [];

		foreach ($items as $item) {
			$normalized = mb_substr(Text::normalize($item['keyword']), 0, 255);

			if ($normalized === '') {
				continue;
			}

			$rows[$normalized] = [
				'project_id' => $project->id,
				'task_id' => (int) $task['id'],
				'keyword' => $item['keyword'],
				'normalized' => $normalized,
				'location_code' => (int) ($payload['location_code'] ?? 0),
				'language_code' => (string) ($payload['language_code'] ?? ''),
				'seed' => null,
				'search_volume' => $item['search_volume'],
				'cpc_micros' => $item['cpc_micros'],
				'cpc_currency' => 'USD', // DataForSEO (Google Ads) podaje CPC i stawki w USD
				'competition' => $item['competition'],
				'competition_index' => $item['competition_index'],
				'low_bid_micros' => $item['low_bid_micros'],
				'high_bid_micros' => $item['high_bid_micros'],
				'source' => 'keywords_for_keywords',
				'fetched_at' => $now,
			];
		}

		if ($rows !== []) {
			$this->ideas->upsert(array_values($rows));
		}
	}

	/**
	 * Po nowych danych przeliczamy słowa kluczowe i rekomendacje dla domyślnego okresu (bez audytu, tylko baza).
	 */
	private function refreshAnalysis(Project $project): void
	{
		try {
			app(AnalysisService::class)->run($project, AnalysisPeriod::fromPreset(AnalysisPeriod::DEFAULT, $project->timezone()), 'dataforseo', null, false);
		} catch (Throwable $e) {
			error_log('[Penny Pincher] Analiza po DataForSEO: ' . $e->getMessage());
		}
	}

	private function cacheKey(string $endpoint, array $payload): string
	{
		return sha1(($this->config->sandbox() ? 'sandbox|' : 'live|') . $endpoint . '|' . wp_json_encode($payload));
	}

	public static function usd(?float $value): string
	{
		return $value === null ? '—' : number_format($value, 2, ',', ' ');
	}
}
