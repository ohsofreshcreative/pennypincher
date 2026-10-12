<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

class HomeController extends Controller
{
	public function index()
	{
		$project = $this->access->currentProject();

		return redirect()->to($project ? AppUrl::project($project->id) : AppUrl::to('projects'));
	}

	/**
	 * Skróty /app/campaigns/, /app/search-terms/ itd. prowadzą do bieżącego projektu.
	 */
	public function shortcut(Request $request, string $section)
	{
		$project = $this->access->currentProject();

		if ($project === null) {
			return $this->redirect(AppUrl::to('projects'), 'Najpierw utwórz projekt albo wybierz istniejący.', 'info');
		}

		return redirect()->to(AppUrl::project($project->id, $section, $request->query()));
	}

	public function switchProject(Request $request)
	{
		$project = $this->access->project((int) $request->query('project'));
		$section = (string) $request->query('section', '');
		$allowed = ['', 'campaigns', 'search-terms', 'imports', 'settings'];

		return redirect()->to(AppUrl::project($project->id, in_array($section, $allowed, true) ? $section : ''));
	}

	public function notFound()
	{
		return \App\PennyPincher\Http\Responses::error(404, 'Nie znaleziono', 'Ta strona aplikacji nie istnieje.');
	}
}
