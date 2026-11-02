<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\DashboardService;
use App\PennyPincher\Support\DateRangeRequest;
use App\PennyPincher\Support\Feature;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
	public function __construct(AccessService $access, private DashboardService $dashboard, private DateRangeRequest $ranges)
	{
		parent::__construct($access);
	}

	public function show(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$range = $this->ranges->resolve($request, $project);

		$data = [
			'project' => $project,
			'range' => $range,
			'pageTitle' => 'Przegląd',
			'section' => 'overview',
			'health' => $this->dashboard->health($project),
			'optimization' => $this->dashboard->optimization($project),
		] + $this->dashboard->build($project, $range);

		if (! Feature::enabled(Feature::DASHBOARD)) {
			return $this->render('app.pages.dashboard', $data);
		}

		// Centrum dowodzenia (flaga pp2_dashboard): te same KPI co w Przeglądzie + priorytety, zdarzenia i onboarding.
		// Analiza uruchamiana stąd obejmuje dokładnie zakres dat dashboardu.
		return $this->render('app.pages.command-center', [
			'pageTitle' => 'Centrum dowodzenia',
			'activity' => $this->dashboard->activity($project),
			'newAlerts' => $this->dashboard->newAlerts($project),
			'canRun' => Policy::allows($project->role, Ability::ImportData),
			'analysisPeriod' => AnalysisPeriod::custom($range->fromString(), $range->toString(), $project->timezone()),
		] + $data);
	}
}
