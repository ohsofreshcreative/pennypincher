<?php

namespace App\PennyPincher\Services\Analysis;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Keywords\KeywordAnalyzer;
use App\PennyPincher\Domain\Leaks\LeakDetector;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\KeywordIdeaRepository;
use App\PennyPincher\Repositories\MetricsRepository;

/**
 * Analiza słów kluczowych: nowe możliwości (Keyword Opportunities) i propozycje wykluczeń.
 * Korzysta wyłącznie z danych w bazie – wyniki DataForSEO są pobierane osobno, po zgodzie użytkownika.
 */
class KeywordOpportunityService
{
	public const TYPE = 'keywords';

	public function __construct(
		private Db $db,
		private AnalysisRepository $analyses,
		private AnalysisDataBuilder $data,
		private MetricsRepository $metrics,
		private KeywordIdeaRepository $ideas,
		private LeakDetector $detector,
	) {
	}

	public function run(Project $project, AnalysisPeriod $period, string $trigger = 'manual', ?int $userId = null): int
	{
		$leakContext = $this->data->leakContext($project, $period);
		$keywordsWithStats = [];

		foreach ($leakContext->keywords as $keyword) {
			$keywordsWithStats[$keyword['id']] = $keyword;
		}

		$keywords = array_map(fn ($k) => $keywordsWithStats[$k['id']] ?? $k + ['conversions' => null], $this->metrics->allKeywords($project->id));
		$clicks = $leakContext->totalClicks();
		$context = $project->context();
		$ideas = $this->ideas->forProject($project->id, $context->locationCode(), $context->language());

		$analyzer = new KeywordAnalyzer(
			$leakContext->terms,
			$keywords,
			$leakContext->negatives,
			$leakContext->adGroups,
			array_map(fn ($c) => $c['name'], $leakContext->campaigns),
			$context,
			$ideas,
			$clicks > 0 ? (int) round($leakContext->totalCost() / $clicks) : null,
			$project->currency,
			$period->days(),
		);

		$opportunities = $analyzer->opportunities();
		$negatives = $analyzer->negatives($this->detector->minCost($leakContext));
		$now = $this->db->now();

		return $this->db->transaction(function () use ($project, $period, $trigger, $userId, $opportunities, $negatives, $now, $ideas, $leakContext) {
			$runId = $this->analyses->createRun($project->id, self::TYPE, $period->range->fromString(), $period->range->toString(), $period->preset, $trigger, $userId, [
				'opportunities' => count($opportunities),
				'negatives' => count(array_filter($negatives, fn ($n) => ! $n['already_excluded'])),
				'ideas_available' => count($ideas),
				'terms_analyzed' => count($leakContext->terms),
				'context_completeness' => $project->context()->completeness(),
			]);

			$this->analyses->insertOpportunities(array_map(fn ($row) => [
				'analysis_run_id' => $runId,
				'project_id' => $project->id,
				'fingerprint' => sha1('opp|' . $row['normalized']),
				'created_at' => $now,
			] + array_diff_key($row, ['normalized' => true]), $opportunities));

			$this->analyses->insertNegativeSuggestions(array_map(fn ($row) => [
				'analysis_run_id' => $runId,
				'project_id' => $project->id,
				'fingerprint' => sha1('neg|' . $row['reason_code'] . '|' . mb_strtolower($row['phrase'])),
				'created_at' => $now,
			] + $row, $negatives));

			return $runId;
		});
	}
}
