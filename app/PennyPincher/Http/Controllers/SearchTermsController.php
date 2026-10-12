<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Support\DateRangeRequest;
use Illuminate\Http\Request;

class SearchTermsController extends Controller
{
	public function __construct(AccessService $access, private MetricsRepository $metrics, private DateRangeRequest $ranges)
	{
		parent::__construct($access);
	}

	public function index(int $project, Request $request)
	{
		$project = $this->access->project($project);
		$this->access->rememberProject($project);
		$range = $this->ranges->resolve($request, $project);
		$resolved = $this->metrics->resolve('search_term_metrics', $project->id, $range);
		$campaignOptions = $this->metrics->campaignOptions($project->id);

		$campaignId = (int) $request->query('campaign', 0);

		$filters = [
			'q' => trim((string) $request->query('q', '')),
			// Filtr kampanii przyjmujemy tylko dla kampanii z tego projektu.
			'campaign_id' => isset($campaignOptions[$campaignId]) ? $campaignId : 0,
			'conversions' => in_array($request->query('conversions'), ['with', 'without'], true) ? (string) $request->query('conversions') : '',
		];
		$sort = array_key_exists((string) $request->query('sort'), MetricsRepository::SEARCH_TERM_SORTS) ? (string) $request->query('sort') : 'spend';
		$direction = $request->query('dir') === 'asc' ? 'asc' : 'desc';

		return $this->render('app.pages.search-terms.index', [
			'project' => $project,
			'range' => $range,
			'resolved' => $resolved,
			'terms' => $this->metrics->searchTerms($project->id, $resolved, $filters, $sort, $direction, max(1, (int) $request->query('page', 1)), 50),
			'campaignOptions' => $campaignOptions,
			'filters' => $filters,
			'sort' => $sort,
			'direction' => $direction,
			'pageTitle' => 'Wyszukiwane hasła',
			'section' => 'search-terms',
		]);
	}
}
