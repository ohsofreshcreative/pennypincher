<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Management\ManagementOverviewService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use Illuminate\Http\Request;

/**
 * Zarządzanie › Przegląd i konfiguracja (pp2_management): onboarding integracji, ustawienia projektu / organizacji /
 * serwera i wejścia do wszystkich podwidoków. Tylko odczyt – akcje (diagnostyka, synchronizacja, zapis ustawień)
 * wysyłają do dotychczasowych kontrolerów z ich uprawnieniami. Bez flagi adres sekcji prowadzi jak dotąd do Ustawień.
 */
class AdministrationController extends Controller
{
	public function __construct(AccessService $access, private ManagementOverviewService $overview)
	{
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);

		if (! Feature::enabled(Feature::MANAGEMENT)) {
			return redirect()->to(AppUrl::project($project->id, 'settings', $request->query()));
		}

		$this->access->rememberProject($project);
		$data = $this->overview->overview($project);

		return $this->render('app.pages.administration', [
			'project' => $project,
			'overview' => $data,
			'diagnostics' => $data['canManage'] ? get_transient(GoogleAdsController::diagnosticsKey($project->id)) : false,
			'pageTitle' => 'Zarządzanie',
			'section' => 'administration',
		]);
	}
}
