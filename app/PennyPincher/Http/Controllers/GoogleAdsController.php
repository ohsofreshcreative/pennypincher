<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Infrastructure\Security\SecretBox;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use App\PennyPincher\Services\GoogleAds\SyncService;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Połączenie projektu z Google Ads (OAuth 2.0), wybór konta i synchronizacja.
 * Uprawnienia: podgląd – członek; łączenie, wybór konta, synchronizacja, rozłączenie – administrator.
 * Integracja ma dostęp tylko do odczytu danych – nie wykonuje zmian w kampaniach.
 */
class GoogleAdsController extends Controller
{
	/** Ile sekund ręczna synchronizacja może pracować w ramach żądania – resztę dokończy cron. */
	private const INLINE_SECONDS = 20;

	public function __construct(
		AccessService $access,
		private ConnectionService $service,
		private SyncService $sync,
		private GoogleAdsConnectionRepository $connections,
		private SyncRunRepository $runs,
		private GoogleAdsConfig $config,
	) {
		parent::__construct($access);
	}

	public function show(int $project)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$connection = $this->connections->currentForProject($project->id);
		$accounts = [];
		$accountsError = null;

		if ($connection !== null && $connection['status'] === GoogleAdsConnectionRepository::STATUS_PENDING && $this->canManage($project)) {
			try {
				$accounts = $this->service->accessibleAccounts($connection);
			} catch (GoogleAdsApiException $e) {
				$accountsError = $e->userMessage();
			}
		}

		return $this->render('app.pages.google-ads', [
			'project' => $project,
			'connection' => $connection,
			'accounts' => $accounts,
			'accountsError' => $accountsError,
			'runs' => $this->runs->recentForProject($project->id, 15),
			'lastSync' => $connection ? $this->runs->lastCompleted((int) $connection['id']) : null,
			'missingConfig' => $this->config->missing(),
			'encryptionReady' => SecretBox::isConfigured(),
			'redirectUri' => $this->config->redirectUri(),
			'apiVersion' => $this->config->apiVersion(),
			'canManage' => $this->canManage($project),
			'pageTitle' => 'Google Ads',
			'section' => 'google-ads',
		]);
	}

	public function connect(int $project)
	{
		$project = $this->access->project($project, Ability::UpdateProject);

		if ($project->isDemo) {
			return $this->redirect(AppUrl::project($project->id, 'google-ads'), 'Projektu demonstracyjnego nie można połączyć z kontem Google Ads.', 'error');
		}

		if (! $this->config->isConfigured() || ! SecretBox::isConfigured()) {
			return $this->redirect(AppUrl::project($project->id, 'google-ads'), 'Integracja z Google Ads nie jest jeszcze skonfigurowana na serwerze. Zobacz docs/GOOGLE_ADS.md.', 'error');
		}

		return redirect()->away($this->service->begin($project, $this->access->userId()));
	}

	/**
	 * Powrót z Google. State jest jednorazowy i powiązany z użytkownikiem; projekt pochodzi ze state,
	 * a uprawnienia sprawdzamy ponownie (mogły się zmienić w trakcie autoryzacji).
	 */
	public function callback(Request $request)
	{
		try {
			$state = $this->service->consumeState((string) $request->query('state', ''), $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::to(), (array_values($e->errors)[0] ?? $e->getMessage()), 'error');
		}

		$project = $this->access->project($state['project_id'], Ability::UpdateProject);
		$back = AppUrl::project($project->id, 'google-ads');

		if ($request->query('error') !== null) {
			return $this->redirect($back, $request->query('error') === 'access_denied' ? 'Nie udzielono dostępu do konta Google Ads.' : 'Google przerwał autoryzację. Spróbuj ponownie.', 'error');
		}

		$code = (string) $request->query('code', '');

		if ($code === '' || strlen($code) > 2048) {
			return $this->redirect($back, 'Google nie zwrócił kodu autoryzacji. Spróbuj ponownie.', 'error');
		}

		try {
			$this->service->complete($project, $this->access->userId(), $code, $state['verifier']);
		} catch (GoogleAdsApiException $e) {
			return $this->redirect($back, $e->userMessage(), 'error');
		}

		return $this->redirect($back, 'Połączono z kontem Google. Wybierz konto reklamowe.');
	}

	public function selectAccount(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::UpdateProject);
		$back = AppUrl::project($project->id, 'google-ads');
		$connection = $this->connections->currentForProject($project->id);

		if ($connection === null) {
			return $this->redirect($back, 'Najpierw połącz konto Google.', 'error');
		}

		try {
			$connection = $this->service->selectAccount($project, $connection, (string) $request->input('customer_id', ''));
			$this->sync->enqueue($connection, 'backfill', $this->access->userId());
		} catch (ValidationFailed $e) {
			return $this->redirect($back, (array_values($e->errors)[0] ?? $e->getMessage()), 'error');
		} catch (GoogleAdsApiException $e) {
			return $this->redirect($back, $e->userMessage(), 'error');
		}

		return $this->redirect($back, 'Konto wybrane. Rozpoczęliśmy pobieranie danych z ostatnich 12 miesięcy – może to potrwać kilkanaście minut.');
	}

	public function syncNow(int $project)
	{
		$project = $this->access->project($project, Ability::UpdateProject);
		$back = AppUrl::project($project->id, 'google-ads');
		$connection = $this->connections->currentForProject($project->id);

		if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
			return $this->redirect($back, 'Synchronizacja wymaga aktywnego połączenia z kontem reklamowym.', 'error');
		}

		$run = $this->sync->enqueue($connection, 'incremental', $this->access->userId());

		// Część pracy wykonujemy od razu (krótko), resztę dokończy zadanie cron – żądanie HTTP nie może trwać długo.
		if (in_array($run['status'], [SyncRunRepository::QUEUED, SyncRunRepository::RUNNING], true) && $run['next_attempt_at'] === null) {
			$run = $this->sync->process($run, time() + self::INLINE_SECONDS);
		}

		return $this->redirect($back, match ($run['status']) {
			SyncRunRepository::COMPLETED => 'Synchronizacja zakończona.',
			SyncRunRepository::FAILED => 'Synchronizacja nie powiodła się: ' . $run['last_error'],
			default => 'Synchronizacja trwa – zostanie dokończona w tle.',
		}, $run['status'] === SyncRunRepository::FAILED ? 'error' : 'success');
	}

	public function retry(int $project, int $run)
	{
		$project = $this->access->project($project, Ability::UpdateProject);
		$runRow = $this->runs->findInProject($project->id, $run);
		$back = AppUrl::project($project->id, 'google-ads');

		if ($runRow === null || $runRow['status'] !== SyncRunRepository::FAILED) {
			return $this->redirect($back, 'Ten przebieg nie wymaga ponowienia.', 'error');
		}

		$this->sync->retry($runRow);

		return $this->redirect($back, 'Synchronizacja zostanie wznowiona od miejsca, w którym się zatrzymała.');
	}

	public function disconnect(int $project)
	{
		$project = $this->access->project($project, Ability::UpdateProject);
		$connection = $this->connections->currentForProject($project->id);

		if ($connection !== null) {
			$this->runs->cancelOpen((int) $connection['id']);
			$this->service->disconnect($connection);
		}

		return $this->redirect(AppUrl::project($project->id, 'google-ads'), 'Rozłączono konto Google Ads. Zsynchronizowane dane pozostały w projekcie.');
	}

	private function canManage(Project $project): bool
	{
		return Policy::allows($project->role, Ability::UpdateProject);
	}
}
