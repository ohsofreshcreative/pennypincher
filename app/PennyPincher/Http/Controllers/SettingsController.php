<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\ProjectValidator;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
	public function __construct(AccessService $access, private ProjectRepository $projects, private ProjectValidator $validator)
	{
		parent::__construct($access);
	}

	public function edit(int $project, Request $request, array $errors = [], int $status = 200)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);

		return $this->render('app.pages.settings', [
			'project' => $project,
			'canEdit' => Policy::allows($project->role, Ability::UpdateProject),
			'errors' => $errors,
			'old' => $request->isMethod('POST') ? $request->input() : [],
			'pageTitle' => 'Ustawienia projektu',
			'section' => 'settings',
		], $status);
	}

	public function update(int $project, Request $request)
	{
		$projectModel = $this->access->project($project, Ability::UpdateProject);

		try {
			$values = $this->validator->validate($request->input());
		} catch (ValidationFailed $e) {
			return $this->edit($project, $request, $e->errors, 422);
		}

		$this->projects->update($projectModel->id, $values);

		return $this->redirect(AppUrl::project($projectModel->id, 'settings'), 'Ustawienia projektu zostały zapisane.');
	}
}
