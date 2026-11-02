<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Navigation;
use Illuminate\Http\Request;

class HomeController extends Controller
{
	public function index()
	{
		$project = $this->access->currentProject();

		return redirect()->to($project ? AppUrl::project($project->id) : AppUrl::to('projects'));
	}

	/**
	 * Skróty /app/campaigns/, /app/search-terms/ itd. (także aliasy sekcji głównych) prowadzą do bieżącego projektu.
	 */
	public function shortcut(Request $request, string $section)
	{
		$project = $this->access->currentProject();

		if ($project === null) {
			return $this->redirect(AppUrl::to('projects'), 'Najpierw utwórz projekt albo wybierz istniejący.', 'info');
		}

		return redirect()->to(AppUrl::project($project->id, Navigation::resolve($section), $request->query()));
	}

	/**
	 * Adres sekcji głównej (np. /app/projects/1/optimization/) – pierwszy podwidok z zachowaniem parametrów.
	 */
	public function group(int $project, string $group, Request $request)
	{
		$project = $this->access->project($project);

		return redirect()->to(AppUrl::project($project->id, Navigation::resolve($group), $request->query()));
	}

	public function switchProject(Request $request)
	{
		$project = $this->access->project((int) $request->query('project'));
		$section = (string) $request->query('section', '');

		return redirect()->to(AppUrl::project($project->id, in_array($section, Navigation::SECTIONS, true) ? $section : ''));
	}

	public function notFound()
	{
		return \App\PennyPincher\Http\Responses::error(404, 'Nie znaleziono', 'Ta strona aplikacji nie istnieje.');
	}
}
