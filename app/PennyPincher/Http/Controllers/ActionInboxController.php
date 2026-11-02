<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\ActionPlan\Inbox;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\ActionPlan\InboxService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use Illuminate\Http\Request;

/**
 * Plan działania 2.0 (pp2_action_plan): wspólna kolejka rekomendacji, zadań, zmian na koncie i alertów oraz ścieżka
 * rekomendacji od decyzji do monitoringu efektów. Tylko odczyt – decyzje i wdrożenia w modułach źródłowych
 * (zapis do Google Ads wyłącznie przez Change Center: podgląd zmian → zatwierdzenie → potwierdzenie wdrożenia).
 */
class ActionInboxController extends Controller
{
	public function __construct(AccessService $access, private InboxService $inbox)
	{
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);

		if (! Feature::enabled(Feature::ACTION_PLAN)) {
			return redirect()->to(AppUrl::project($project->id, 'action-plan'));
		}

		$this->access->rememberProject($project);
		$tab = Inbox::tab((string) $request->query('tab', ''));

		return $this->render('app.pages.action-inbox', [
			'project' => $project,
			'tab' => $tab,
			'counts' => $this->inbox->counts($project),
			'items' => $this->inbox->items($project, $tab),
			'pageTitle' => 'Plan działania',
			'section' => 'action-inbox',
		]);
	}

	public function trace(int $project, int $recommendation)
	{
		$project = $this->access->project($project);

		return $this->render('app.pages.action-trace', [
			'project' => $project,
			'trace' => $this->inbox->trace($project, $recommendation),
			'pageTitle' => 'Ścieżka rekomendacji',
			'section' => Feature::enabled(Feature::ACTION_PLAN) ? 'action-inbox' : 'action-plan',
		]);
	}
}
