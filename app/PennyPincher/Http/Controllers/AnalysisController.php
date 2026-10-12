<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\AnalysisService;
use App\PennyPincher\Support\AnalysisPeriodRequest;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Ręczne uruchomienie pełnej analizy okresu (audyt → Budget Leaks → słowa kluczowe → rekomendacje).
 * Analiza czyta wyłącznie dane z bazy – nie odpytuje Google Ads ani DataForSEO i niczego nie zmienia na koncie.
 */
class AnalysisController extends Controller
{
	private const RETURN_TO = ['budget-leaks', 'recommendations', 'keywords', 'audits', ''];

	public function __construct(AccessService $access, private AnalysisService $analysis, private AnalysisPeriodRequest $periods)
	{
		parent::__construct($access);
	}

	public function store(int $project, Request $request)
	{
		$project = $this->access->project($project, Ability::ImportData);
		$period = $this->periods->resolve($request, $project);
		$section = (string) $request->input('return', 'budget-leaks');
		$section = in_array($section, self::RETURN_TO, true) ? $section : 'budget-leaks';

		$result = $this->analysis->run($project, $period, 'manual', $this->access->userId());
		$url = AppUrl::project($project->id, $section, AnalysisPeriodRequest::query($period));

		if ($result['errors'] !== []) {
			return $this->redirect($url, 'Analiza zakończona częściowo: ' . implode(' ', array_unique($result['errors'])), 'error');
		}

		$r = $result['recommendations'];

		return $this->redirect($url, sprintf('Analiza okresu %s zakończona. Rekomendacje: %d (nowe: %d, nieaktualne: %d).', $period->range->label(), $r['total'], $r['created'], $r['obsolete']));
	}
}
