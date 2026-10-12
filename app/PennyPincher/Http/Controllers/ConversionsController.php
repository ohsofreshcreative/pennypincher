<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Conversions\ConversionClass;
use App\PennyPincher\Repositories\ConversionActionRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\ConversionBreakdown;
use App\PennyPincher\Support\AnalysisPeriodRequest;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Klasyfikacja działań powodujących konwersję (lead / potencjalny kontakt / sprzedaż / mikrokonwersja…).
 * Zmienia wyłącznie interpretację w Penny Pincher – nie dotyka ustawień konwersji w Google Ads.
 */
class ConversionsController extends Controller
{
	public function __construct(
		AccessService $access,
		private ConversionActionRepository $actions,
		private ConversionBreakdown $breakdown,
		private AnalysisPeriodRequest $periods,
	) {
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$period = $this->periods->resolve($request, $project);
		$breakdown = $this->breakdown->forPeriod($project->id, $period->range);

		return $this->render('app.pages.conversions', [
			'project' => $project,
			'period' => $period,
			'breakdown' => $breakdown,
			'canEdit' => Policy::allows($project->role, Ability::UpdateProject),
			'pageTitle' => 'Klasyfikacja konwersji',
			'section' => 'conversions',
		]);
	}

	public function update(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::UpdateProject);
		$classes = (array) $request->input('class', []);
		$goals = (array) $request->input('goal', []);
		$changed = 0;

		foreach ($this->actions->forProject($project->id) as $action) {
			$submitted = ConversionClass::tryFrom((string) ($classes[$action['id']] ?? ''));

			if ($submitted === null) {
				continue;
			}

			$goal = ! empty($goals[$action['id']]);

			// Zapisujemy tylko zmienione wiersze i wcześniej ustawione ręcznie – propozycje bez zmian pozostają propozycjami.
			if ($action['classification_source'] === 'manual' || $submitted->value !== $action['effective_class'] || $goal !== $action['effective_goal']) {
				$this->actions->classify($project->id, $action['id'], $submitted, $goal, $this->access->userId());
				$changed++;
			}
		}

		return $this->redirect(AppUrl::project($project->id, 'conversions'), $changed > 0
			? "Zapisano klasyfikację ({$changed}). Uruchom ponownie analizę, aby uwzględnić zmiany w Budget Leaks i rekomendacjach."
			: 'Bez zmian.');
	}
}
