<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Monitoring\AlertDetector;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\AlertRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Monitoring\AlertNotifier;
use App\PennyPincher\Services\Monitoring\MonitoringService;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Monitoring: centrum powiadomień projektu (alerty, statusy, historia, ustawienia e-mail).
 */
class MonitoringController extends Controller
{
	public function __construct(
		AccessService $access,
		private AlertRepository $alerts,
		private MonitoringService $service,
		private MetricsRepository $metrics,
		private \App\PennyPincher\Services\Changes\ChangeEffectsService $effects,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$filters = [
			'status' => array_key_exists((string) $request->query('status', ''), AlertRepository::STATUSES) ? (string) $request->query('status') : null,
			'severity' => array_key_exists((string) $request->query('severity', ''), AlertRepository::SEVERITIES) ? (string) $request->query('severity') : null,
			'type' => array_key_exists((string) $request->query('type', ''), AlertDetector::TYPES) ? (string) $request->query('type') : null,
		];
		$open = ctype_digit((string) $request->query('open', '')) ? (int) $request->query('open') : null;

		return $this->render('app.pages.monitoring', [
			'project' => $project,
			'filters' => $filters,
			'alerts' => $this->alerts->paginate($project->id, $filters, max(1, (int) $request->query('page', 1))),
			'counts' => $this->alerts->counts($project->id),
			'lastRun' => $this->alerts->lastRun($project->id),
			'events' => $open ? $this->alerts->events($project->id, $open) : [],
			'open' => $open,
			'campaignOptions' => $this->metrics->campaignOptions($project->id),
			'preferences' => AlertNotifier::preferences($this->access->userId(), $project->id),
			'canEdit' => Policy::allows($project->role, Ability::ManageAlerts),
			'changeEffects' => array_map(fn ($set) => ['set' => $set, 'effects' => $this->effects->forSet($project, $set)], $this->effects->recent($project)),
			'pageTitle' => 'Monitoring',
			'section' => 'monitoring',
		]);
	}

	/**
	 * Ręczne sprawdzenie (te same reguły co po synchronizacji; bez zapytań do Google Ads i DataForSEO).
	 */
	public function check(int $project)
	{
		$project = $this->access->project($project, Ability::ManageAlerts);
		$result = $this->service->run($project, 'manual');

		return $this->redirect(AppUrl::project($project->id, 'monitoring'), sprintf('Sprawdzono dane: %d %s (nowe: %d, ponownie otwarte: %d).', $result['detected'], $result['detected'] === 1 ? 'wykryty problem' : 'wykrytych problemów', $result['created'], $result['reopened']));
	}

	public function updateStatus(int $project, int $alert, Request $request)
	{
		$project = $this->access->project($project, Ability::ManageAlerts);
		parse_str((string) $request->input('back', ''), $back);
		$query = array_intersect_key(array_filter($back, 'is_scalar'), array_flip(['status', 'severity', 'type', 'page']));

		try {
			$days = ctype_digit((string) $request->input('mute_days', '')) ? (int) $request->input('mute_days') : null;
			$this->service->updateStatus($project, $alert, (string) $request->input('status', ''), $this->access->userId(), $request->input('note'), $days);
		} catch (ValidationFailed $e) {
			return $this->redirect(AppUrl::project($project->id, 'monitoring', $query), (string) (array_values($e->errors)[0] ?? $e->getMessage()), 'error');
		}

		return redirect()->to(AppUrl::project($project->id, 'monitoring', $query + ['open' => $alert]) . '#alert-' . $alert);
	}

	/**
	 * Ustawienia powiadomień e-mail bieżącego użytkownika (opt-in, per projekt).
	 */
	public function preferences(int $project, Request $request)
	{
		$project = $this->access->project($project);
		AlertNotifier::savePreferences($this->access->userId(), $project->id, $request->input('enabled') === '1', (string) $request->input('min_severity', 'critical'));

		return $this->redirect(AppUrl::project($project->id, 'monitoring') . '#notifications', $request->input('enabled') === '1' ? 'Włączono powiadomienia e-mail (najwyżej 1 wiadomość na dobę).' : 'Wyłączono powiadomienia e-mail.');
	}
}
