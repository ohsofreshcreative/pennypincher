<?php

namespace App\PennyPincher\Http\Controllers;

/**
 * Ogólna instrukcja „Jak zacząć” – statyczna treść, tylko odczyt (link z okna instrukcji każdej strony).
 */
class GuideController extends Controller
{
	public function index(int $project)
	{
		$project = $this->access->project($project);

		return $this->render('app.pages.guide', [
			'project' => $project,
			'pageTitle' => 'Jak zacząć',
			'section' => 'guide',
		]);
	}
}
