<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Changes\ConversionManagementService;
use App\PennyPincher\Services\Conversions\ConversionCenterService;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use App\PennyPincher\Support\AnalysisPeriodRequest;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use Illuminate\Http\Request;

/**
 * Centrum konwersji (pp2_conversions): Konwersje (klasyfikacja Penny Pincher) i Conversion Management (konfiguracja
 * Google Ads) w jednym widoku. Tylko odczyt – formularze wysyłają do dotychczasowych akcji (te same uprawnienia,
 * zmiany w Google Ads wyłącznie jako szkic w Change Center).
 */
class ConversionCenterController extends Controller
{
	public function __construct(
		AccessService $access,
		private ConversionCenterService $center,
		private ConversionManagementService $management,
		private GoogleAdsConnectionRepository $connections,
		private WriteGuard $guard,
		private AnalysisPeriodRequest $periods,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);

		if (! Feature::enabled(Feature::CONVERSIONS)) {
			return redirect()->to(AppUrl::project($project->id, 'conversions', array_diff_key($request->query(), ['tab' => true])));
		}

		$this->access->rememberProject($project);
		$tab = ConversionCenterService::tab((string) $request->query('tab', ''));
		$period = $this->periods->resolve($request, $project);
		$results = $this->center->results($project, $period);
		$connection = $this->connections->currentForProject($project->id);
		$data = [
			'project' => $project,
			'tab' => $tab,
			'period' => $period,
			'breakdown' => $results['breakdown'],
			'canEdit' => Policy::allows($project->role, Ability::UpdateProject),
			'returnTo' => 'center:' . $tab,
			'pageTitle' => 'Konwersje',
			'section' => 'conversion-center',
		];

		if ($tab === 'results') {
			$data += ['resolved' => $results['resolved'], 'campaigns' => $results['campaigns']];
		}

		if (in_array($tab, ['events', 'diagnostics'], true)) {
			$data += [
				'overview' => $this->management->overview($project),
				'connection' => $connection,
				'mode' => $this->guard->mode($connection),
				'serverMode' => $this->guard->serverMode(),
				'canPrepare' => Policy::allows($project->role, Ability::PrepareChanges) && $this->guard->mode($connection)->canPrepare(),
			];
		}

		if ($tab === 'history') {
			$data['history'] = $this->center->history($project);
		}

		return $this->render('app.pages.conversion-center', $data);
	}
}
