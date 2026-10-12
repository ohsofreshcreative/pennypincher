<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\DashboardService;
use App\PennyPincher\Support\DateRangeRequest;
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

		return $this->render('app.pages.dashboard', [
			'project' => $project,
			'range' => $range,
			'pageTitle' => 'Przegląd',
			'section' => 'overview',
			'health' => $this->dashboard->health($project),
			'optimization' => $this->dashboard->optimization($project),
		] + $this->dashboard->build($project, $range));
	}
}
