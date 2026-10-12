<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Support\Flash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

abstract class Controller
{
	public function __construct(protected AccessService $access)
	{
	}

	/**
	 * Renderuje widok aplikacji z danymi wspólnymi dla layoutu.
	 */
	protected function render(string $view, array $data = [], int $status = 200): Response
	{
		$project = $data['project'] ?? null;

		$shared = [
			'currentUser' => wp_get_current_user(),
			'projects' => $this->access->projects(),
			'project' => $project instanceof Project ? $project : null,
			'flash' => Flash::pull(),
			// Licznik nowych alertów w nawigacji (jedno zapytanie po indeksie; tylko dla projektu bieżącego użytkownika).
			'alertCount' => $project instanceof Project ? app(\App\PennyPincher\Repositories\AlertRepository::class)->newCount($project->id) : 0,
			'errors' => [],
			'old' => [],
		];

		return response(view($view, array_merge($shared, $data)), $status);
	}

	protected function redirect(string $url, ?string $message = null, string $type = 'success'): RedirectResponse
	{
		if ($message !== null) {
			Flash::set($type, $message);
		}

		return redirect()->to($url);
	}
}
