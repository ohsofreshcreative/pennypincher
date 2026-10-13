<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Changes\ChangeProposalService;
use App\PennyPincher\Services\Changes\ConversionManagementService;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use App\PennyPincher\Support\AppUrl;
use Closure;
use Illuminate\Http\Request;

/**
 * Conversion Management: konfiguracja konwersji Google Ads (oddzielona od klasyfikacji Penny Pincher),
 * tworzenie i edycja obsługiwanych pól przez Change Center oraz Conversion Health Check.
 */
class ConversionManagementController extends Controller
{
	public function __construct(
		AccessService $access,
		private ConversionManagementService $service,
		private ChangeProposalService $proposals,
		private GoogleAdsConnectionRepository $connections,
		private WriteGuard $guard,
	) {
		parent::__construct($access);
	}

	public function index(int $project)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$connection = $this->connections->currentForProject($project->id);

		return $this->render('app.pages.conversion-management', [
			'project' => $project,
			'overview' => $this->service->overview($project),
			'connection' => $connection,
			'mode' => $this->guard->mode($connection),
			'serverMode' => $this->guard->serverMode(),
			'canPrepare' => Policy::allows($project->role, Ability::PrepareChanges) && $this->guard->mode($connection)->canPrepare(),
			'pageTitle' => 'Conversion Management',
			'section' => 'conversion-management',
		]);
	}

	public function read(int $project)
	{
		$project = $this->access->project($project, Ability::PrepareChanges);

		try {
			$this->service->readFromGoogle($project);
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($project->id, 'conversion-management'), (string) array_values($e->errors)[0], 'error');
		}

		return $this->redirect(AppUrl::project($project->id, 'conversion-management'), 'Odczytano konfigurację konwersji i celów z Google Ads (tylko odczyt).');
	}

	public function create(int $project, Request $request)
	{
		return $this->prepare($project, fn ($p, $userId) => $this->proposals->conversionCreate($p, $userId, $request->only(['name', 'category', 'counting_type', 'click_window', 'view_window', 'default_value', 'always_use_default_value', 'primary_for_goal'])));
	}

	public function update(int $project, int $action, Request $request)
	{
		return $this->prepare($project, function ($p, $userId) use ($action, $request) {
			$payload = [];

			foreach (OperationCatalog::UPDATE_PATHS['conversion_action'] as $field => $path) {
				if (! $request->has($field) || $request->input($field) === '__keep') {
					continue;
				}

				$value = $request->input($field);
				$payload[$field] = match ($field) {
					'primary_for_goal', 'always_use_default_value' => $value === '1',
					'click_through_lookback_window_days', 'view_through_lookback_window_days' => $value === '' ? null : (int) $value,
					'default_value' => $value === '' ? null : (float) str_replace(',', '.', (string) $value),
					'name' => trim(wp_strip_all_tags((string) $value)),
					default => strtoupper(trim((string) $value)),
				};
			}

			$payload = array_filter($payload, fn ($v) => $v !== null);

			if ($payload === []) {
				throw new ValidationFailed(['change' => 'Nie wybrano żadnej zmiany.']);
			}

			if (array_key_exists('primary_for_goal', $payload)) {
				$payload['confirm_goal_change'] = $request->input('confirm_goal_change') === '1';
			}

			return $this->proposals->conversionUpdate($p, $userId, $action, $payload);
		});
	}

	public function goal(int $project, Request $request)
	{
		return $this->prepare($project, fn ($p, $userId) => $this->proposals->goalUpdate($p, $userId, (string) $request->input('resource_name'), $request->input('biddable') === '1'));
	}

	public function health(int $project, Request $request)
	{
		return $this->prepare($project, function ($p, $userId) use ($request) {
			$key = (string) $request->input('issue');

			foreach ($this->service->overview($p)['issues'] as $issue) {
				if ($issue['key'] === $key) {
					return $this->proposals->fromHealthIssue($p, $userId, $issue);
				}
			}

			throw new ValidationFailed(['issue' => 'Ten problem już nie występuje – odśwież stronę.']);
		});
	}

	private function prepare(int $project, Closure $action)
	{
		$projectModel = $this->access->project($project, Ability::PrepareChanges);

		try {
			$id = $this->proposals->atomically($projectModel, $this->access->userId(), fn () => $action($projectModel, $this->access->userId()));
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($projectModel->id, 'conversion-management'), (string) (array_values($e->errors)[0] ?? $e->getMessage()), 'error');
		} catch (GoogleAdsApiException $e) {
			return $this->redirect(AppUrl::project($projectModel->id, 'conversion-management'), 'Nie udało się odczytać danych z Google Ads: ' . $e->userMessage(), 'error');
		}

		return $this->redirect(AppUrl::project($projectModel->id, 'changes/' . $id), 'Przygotowano szkic zmiany konwersji. Zmiany celów wpływają na automatyczne strategie stawek – w Change Center potwierdź je osobno.');
	}
}
