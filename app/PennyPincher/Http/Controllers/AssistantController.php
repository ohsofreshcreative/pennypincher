<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Assistant\AssistantService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use Illuminate\Http\Request;

/**
 * Asystent (pp2_assistant): podsumowanie „co dziś zrobić” i wyjaśnienie rekomendacji. Tylko do odczytu – decyzje
 * w Rekomendacjach, zmiany na koncie wyłącznie przez Change Center z zatwierdzeniem. Bez flagi – Rekomendacje.
 */
class AssistantController extends Controller
{
	public function __construct(AccessService $access, private AssistantService $assistant)
	{
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$recommendation = (int) $request->query('recommendation', 0);

		if (! Feature::enabled(Feature::ASSISTANT)) {
			return redirect()->to($recommendation > 0
				? AppUrl::project($project->id, 'recommendations', ['obsolete' => 'all', 'open' => $recommendation]) . '#rec-' . $recommendation
				: AppUrl::project($project->id, 'recommendations'));
		}

		$this->access->rememberProject($project);

		return $this->render('app.pages.assistant', [
			'project' => $project,
			'digest' => $recommendation > 0 ? null : $this->assistant->digest($project),
			'explained' => $recommendation > 0 ? $this->assistant->explain($project, $recommendation) : null,
			'pageTitle' => 'Asystent',
			'section' => 'assistant',
		]);
	}
}
