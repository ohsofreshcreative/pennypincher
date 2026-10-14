<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\WriteBlockedException;
use App\PennyPincher\Repositories\ActionItemRepository;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Changes\ChangeCenterService;
use App\PennyPincher\Services\Changes\ChangeEffectsService;
use App\PennyPincher\Services\Changes\ChangeProposalService;
use App\PennyPincher\Services\Changes\ExecutionService;
use App\PennyPincher\Services\Changes\PlanDeploymentService;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use App\PennyPincher\Support\AppUrl;
use Closure;
use Illuminate\Http\Request;

/**
 * Change Center: lista i szczegóły zestawów zmian Google Ads, edycja operacji, przegląd, zatwierdzenie,
 * kolejkowanie wdrożenia, uzgadnianie, cofanie. Kontroler nie wysyła mutacji – robi to wyłącznie worker.
 */
class ChangeCenterController extends Controller
{
	private const MICROS = ['amount_micros', 'cpc_bid_micros', 'target_cpa_micros', 'cpc_bid_ceiling_micros'];

	private const INTS = ['click_through_lookback_window_days', 'view_through_lookback_window_days'];

	private const BOOLS = ['target_search_network', 'target_content_network', 'primary_for_goal', 'always_use_default_value', 'biddable', 'shared_ack', 'confirm_goal_change', 'geo_presence_only'];

	public function __construct(
		AccessService $access,
		private ChangeSetRepository $sets,
		private ChangeCenterService $center,
		private ExecutionService $execution,
		private ChangeProposalService $proposals,
		private PlanDeploymentService $plans,
		private ChangeEffectsService $effects,
		private WriteGuard $guard,
		private GoogleAdsConnectionRepository $connections,
		private ActionItemRepository $actionItems,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$connection = $this->connections->currentForProject($project->id);
		$filters = [
			'status' => array_key_exists((string) $request->query('status', ''), ChangeStatus::LABELS) ? (string) $request->query('status') : '',
			'source' => array_key_exists((string) $request->query('source', ''), ChangeCenterService::SOURCES) ? (string) $request->query('source') : '',
		];

		return $this->render('app.pages.changes.index', [
			'project' => $project,
			'filters' => $filters,
			'sets' => $this->sets->paginate($project->id, $filters, max(1, (int) $request->query('page', 1))),
			'counts' => $this->sets->counts($project->id),
			'connection' => $connection,
			'mode' => $this->guard->mode($connection),
			'serverMode' => $this->guard->serverMode(),
			'isExecutor' => $this->guard->isExecutor($project, $this->access->userId()),
			'canPrepare' => Policy::allows($project->role, Ability::PrepareChanges),
			'pageTitle' => 'Change Center',
			'section' => 'changes',
		]);
	}

	public function show(int $project, int $set)
	{
		$project = $this->access->project($project);
		$row = $this->sets->find($project->id, $set) ?? throw new NotFound('Zestaw zmian nie istnieje.');
		$operations = $this->sets->operations($set);
		$connection = $this->connections->currentForProject($project->id);
		$userId = $this->access->userId();

		return $this->render('app.pages.changes.show', [
			'project' => $project,
			'set' => $row,
			'operations' => $operations,
			'local' => $this->center->validateLocally($project, $row, $operations),
			'events' => $this->sets->events($project->id, $set),
			'attempts' => $this->sets->attempts($project->id, $set),
			'connection' => $connection,
			'mode' => $this->guard->mode($connection),
			'serverMode' => $this->guard->serverMode(),
			'deployBlocker' => $this->center->deployBlocker($project, $row, $operations, $connection, $userId),
			'canPrepare' => Policy::allows($project->role, Ability::PrepareChanges),
			'canApprove' => Policy::allows($project->role, Ability::ApproveChanges),
			'apiValidation' => $this->guard->apiValidationAvailable($connection),
			'hashOk' => $row['approved_hash'] !== null && hash_equals((string) $row['approved_hash'], $this->center->hash($row, $operations)),
			'effects' => $this->effects->forSet($project, $row),
			'rollbackable' => array_filter($operations, fn ($op) => in_array($op['status'], ChangeStatus::OP_DONE, true) && OperationCatalog::inverse($op, $op['result_resource_name']) !== null) !== [],
			'deployKey' => wp_generate_uuid4(),
			'pageTitle' => 'Zestaw zmian #' . $row['id'],
			'section' => 'changes',
		]);
	}

	public function store(int $project, Request $request)
	{
		return $this->act($project, null, Ability::PrepareChanges, fn ($p) => $this->center->createSet($p, ['title' => $request->input('title'), 'description' => $request->input('description')], $this->access->userId()), 'Utworzono pusty szkic. Dodaj operacje w Google Ads Management albo z rekomendacji.');
	}

	public function updateOperation(int $project, int $set, int $operation, Request $request)
	{
		return $this->act($project, $set, Ability::PrepareChanges, function ($p) use ($set, $operation, $request) {
			$op = $this->sets->findOperation($set, $operation) ?? throw new ValidationFailed(['operation' => 'Nie znaleziono operacji.']);
			$this->center->updateOperation($p, $set, $operation, self::payload((array) $request->input('payload', []), $op['payload']), $this->access->userId());

			return $set;
		}, 'Zapisano zmiany operacji. Zestaw wymaga ponownego przeglądu.', 'op-' . $operation);
	}

	public function deleteOperation(int $project, int $set, int $operation)
	{
		return $this->act($project, $set, Ability::PrepareChanges, fn ($p) => $this->center->removeOperation($p, $set, $operation, $this->access->userId()) ?? $set, 'Usunięto operację z zestawu.');
	}

	public function readState(int $project, int $set)
	{
		return $this->act($project, $set, Ability::PrepareChanges, fn ($p) => [$this->center->refreshState($p, $set, $this->access->userId()), $set][1], 'Odczytano aktualny stan zasobów z Google Ads (tylko odczyt).');
	}

	public function submit(int $project, int $set)
	{
		return $this->act($project, $set, Ability::PrepareChanges, fn ($p) => $this->center->submit($p, $set, $this->access->userId()) ?? $set, 'Wysłano do przeglądu. Nic nie zostało wysłane do Google Ads.');
	}

	public function approve(int $project, int $set, Request $request)
	{
		return $this->act($project, $set, Ability::ApproveChanges, fn ($p) => [$this->center->approve($p, $set, $this->access->userId(), $request->input('confirm_high_risk') === '1', $request->input('confirm_bidding') === '1'), $set], null);
	}

	public function reject(int $project, int $set, Request $request)
	{
		return $this->act($project, $set, Ability::ApproveChanges, fn ($p) => $this->center->reject($p, $set, $this->access->userId(), $request->input('note')) ?? $set, 'Odesłano do poprawek (szkic).');
	}

	public function deploy(int $project, int $set, Request $request)
	{
		return $this->act($project, $set, Ability::ExecuteChanges, fn ($p) => [$this->center->requestDeploy($p, $set, $this->access->userId(), (string) $request->input('deploy_key', ''), (string) $request->input('confirmation', '')), $set], null);
	}

	public function cancel(int $project, int $set)
	{
		return $this->act($project, $set, Ability::PrepareChanges, fn ($p) => $this->center->cancel($p, $set, $this->access->userId()) ?? $set, 'Anulowano zestaw zmian.');
	}

	public function reconcile(int $project, int $set)
	{
		return $this->act($project, $set, Ability::ApproveChanges, fn ($p) => [$this->execution->reconcile($p, $set, $this->access->userId()), $set], null);
	}

	public function rollback(int $project, int $set)
	{
		return $this->act($project, $set, Ability::PrepareChanges, fn ($p) => $this->center->prepareRollback($p, $set, $this->access->userId()), 'Przygotowano szkic cofnięcia – sprawdź go, odczytaj aktualny stan i przekaż do zatwierdzenia.');
	}

	public function retry(int $project, int $set)
	{
		return $this->act($project, $set, Ability::PrepareChanges, fn ($p) => $this->center->retryFailed($p, $set, $this->access->userId()), 'Przygotowano nowy szkic z operacji, które się nie powiodły.');
	}

	// --- Przygotowanie zmian z modułów -----------------------------------------------------------

	public function fromRecommendation(int $project, int $recommendation)
	{
		return $this->act($project, null, Ability::PrepareChanges, fn ($p) => $this->proposals->fromRecommendation($p, $this->access->userId(), $recommendation), 'Przygotowano szkic zmiany z rekomendacji. Akceptacja rekomendacji i wdrożenie w Google Ads to osobne kroki.');
	}

	public function fromActionItem(int $project, int $item)
	{
		return $this->act($project, null, Ability::PrepareChanges, function ($p) use ($item) {
			$row = $this->actionItems->find($p->id, $item) ?? throw new ValidationFailed(['action' => 'Nie znaleziono działania.']);

			return $this->proposals->fromActionItem($p, $this->access->userId(), $row);
		}, 'Przygotowano szkic zmian dla działania z Action Plan. Status działania się nie zmienia.');
	}

	public function fromLeak(int $project, int $leak)
	{
		return $this->act($project, null, Ability::PrepareChanges, fn ($p) => $this->proposals->fromLeak($p, $this->access->userId(), $leak), 'Przygotowano szkic zmiany dla wycieku budżetu. Status wycieku się nie zmienia.');
	}

	public function fromNegatives(int $project, Request $request)
	{
		return $this->act($project, null, Ability::PrepareChanges, fn ($p) => $this->proposals->fromNegativeSuggestions($p, $this->access->userId(), (int) $request->input('run'), array_map('intval', (array) $request->input('suggestions', []))), 'Przygotowano szkic wykluczeń. Przed zapisem sprawdź wpływ na aktywne słowa kluczowe.');
	}

	public function fromPlan(int $project, int $plan, Request $request)
	{
		return $this->act($project, null, Ability::PrepareChanges, fn ($p) => $this->plans->prepare($p, $plan, $this->access->userId(), [
			'geo_ids' => array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) $request->input('geo_ids', '')) ?: []))) ?: null,
			'bidding' => array_key_exists((string) $request->input('bidding'), OperationCatalog::BIDDING) ? (string) $request->input('bidding') : 'MANUAL_CPC',
			'eu_political' => in_array($request->input('eu_political'), ['no', 'yes'], true) ? (string) $request->input('eu_political') : null,
			'search_partners' => $request->input('search_partners') === '1',
		]), 'Przygotowano wdrożenie planu w Change Center. Kampanie zostaną utworzone jako wstrzymane – dopiero po zatwierdzeniu i świadomym uruchomieniu wdrożenia.');
	}

	// --- Pomocnicze -----------------------------------------------------------------------------

	/**
	 * Wspólna obsługa akcji: uprawnienie, wykonanie, komunikat, przekierowanie do zestawu.
	 * Akcja zwraca id zestawu albo [komunikat, id zestawu].
	 */
	private function act(int $project, ?int $set, Ability $ability, Closure $action, ?string $message, ?string $anchor = null)
	{
		$projectModel = $this->access->project($project, $ability);
		$back = $set !== null ? AppUrl::project($projectModel->id, 'changes/' . $set) : AppUrl::project($projectModel->id, 'changes');

		try {
			$result = $action($projectModel);
		} catch (ValidationFailed $e) {
			return $this->redirect($back . ($anchor ? '#' . $anchor : ''), (string) (array_values($e->errors)[0] ?? $e->getMessage()), 'error');
		} catch (WriteBlockedException $e) {
			return $this->redirect($back, $e->getMessage(), 'error');
		} catch (GoogleAdsApiException $e) {
			return $this->redirect($back . ($anchor ? '#' . $anchor : ''), 'Google Ads: ' . $e->userMessage(), 'error');
		}

		[$text, $target] = is_array($result) ? $result : [$message, $result];

		return $this->redirect(AppUrl::project($projectModel->id, 'changes/' . (int) $target) . ($anchor ? '#' . $anchor : ''), $text ?? $message ?? 'Zapisano.');
	}

	/**
	 * Wartości z formularza edycji operacji → typy ładunku (kwoty w mikro, liczby, wartości logiczne, listy).
	 */
	public static function payload(array $input, array $current): array
	{
		$out = [];

		foreach ($input as $key => $value) {
			if (! is_string($key) || ! array_key_exists($key, $current) && ! in_array($key, ['shared_ack', 'confirm_goal_change'], true)) {
				continue;
			}

			$out[$key] = match (true) {
				in_array($key, self::MICROS, true) => self::micros($value),
				in_array($key, self::INTS, true) => $value === '' || $value === null ? null : (int) $value,
				in_array($key, self::BOOLS, true) => in_array((string) (is_array($value) ? end($value) : $value), ['1', 'true', 'on'], true),
				$key === 'default_value' => $value === '' ? null : (float) str_replace([',', ' '], ['.', ''], (string) $value),
				in_array($key, ['headlines', 'descriptions'], true) => array_values(array_filter(array_map('trim', is_array($value) ? $value : (preg_split('/\r?\n/', (string) $value) ?: [])), fn ($v) => $v !== '')),
				in_array($key, ['status', 'match_type', 'counting_type', 'category', 'strategy'], true) => strtoupper(trim((string) $value)),
				default => is_scalar($value) ? trim((string) $value) : null,
			};
		}

		return array_filter($out, fn ($v, $k) => $v !== null || in_array($k, self::MICROS, true), ARRAY_FILTER_USE_BOTH);
	}

	public static function micros(mixed $value): ?int
	{
		$value = str_replace([' ', "\u{00A0}", 'zł', 'PLN'], '', trim((string) $value));
		$value = str_replace(',', '.', $value);

		return $value === '' || ! is_numeric($value) ? null : (int) round((float) $value * 1_000_000);
	}
}
