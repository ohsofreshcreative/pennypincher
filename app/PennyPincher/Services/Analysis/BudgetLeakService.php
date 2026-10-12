<?php

namespace App\PennyPincher\Services\Analysis;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Analysis\Statistics;
use App\PennyPincher\Domain\Leaks\Leak;
use App\PennyPincher\Domain\Leaks\LeakDetector;
use App\PennyPincher\Domain\Leaks\SavingsEstimate;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\AnalysisRepository;

/**
 * Uruchamia analizę Budget Leaks dla okresu i zapisuje wyniki (status problemów przenoszony po odcisku).
 */
class BudgetLeakService
{
	public const TYPE = 'budget_leaks';

	public function __construct(
		private Db $db,
		private AnalysisRepository $analyses,
		private AnalysisDataBuilder $data,
		private LeakDetector $detector,
	) {
	}

	public function run(Project $project, AnalysisPeriod $period, string $trigger = 'manual', ?int $userId = null): int
	{
		$context = $this->data->leakContext($project, $period);
		$leaks = $this->detector->detect($context);
		$statuses = $this->analyses->lastLeakStatuses($project->id);
		$spend = $context->totalCost();
		$now = $this->db->now();

		return $this->db->transaction(function () use ($project, $period, $trigger, $userId, $context, $leaks, $statuses, $spend, $now) {
			$runId = $this->analyses->createRun($project->id, self::TYPE, $period->range->fromString(), $period->range->toString(), $period->preset, $trigger, $userId);
			$rows = [];

			foreach ($leaks as $leak) {
				$score = $leak->priorityScore($spend);
				$carried = $statuses[$leak->fingerprint()] ?? null;
				$rows[] = [
					'analysis_run_id' => $runId,
					'project_id' => $project->id,
					'rule_id' => $leak->ruleId,
					'category' => $leak->category,
					'fingerprint' => $leak->fingerprint(),
					'title' => mb_substr($leak->title, 0, 255),
					'entity_type' => $leak->entityType,
					'entity_id' => $leak->entityId,
					'campaign_id' => $leak->campaignId,
					'ad_group_id' => $leak->adGroupId,
					'subject' => $leak->subject !== null ? mb_substr($leak->subject, 0, 500) : null,
					'cost_micros' => $leak->costMicros,
					'clicks' => $leak->clicks,
					'conversions' => $leak->conversions,
					'valuable_conversions' => $leak->valuableConversions,
					'confidence' => $leak->confidence,
					'priority' => Leak::priority($score),
					'priority_score' => $score,
					'savings_micros' => $leak->savingsMicros,
					'savings_group' => $leak->campaignId !== null ? 'campaign:' . $leak->campaignId : 'account',
					'reason' => $leak->reason,
					'action' => $leak->action,
					'evidence' => ['pairs' => $leak->evidence, 'terms' => $leak->termIds],
					'status' => $carried['status'] ?? 'new',
					'status_changed_by' => $carried['status_changed_by'] ?? null,
					'status_changed_at' => $carried['status_changed_at'] ?? null,
					'created_at' => $now,
				];
			}

			if ($rows !== []) {
				$this->analyses->insertLeaks($rows);
			}

			$this->analyses->updateSummary($runId, $this->summary($context, $rows));

			return $runId;
		});
	}

	public function updateStatus(Project $project, int $leakId, string $status, int $userId): array
	{
		if (! isset(AnalysisRepository::LEAK_STATUSES[$status])) {
			throw new ValidationFailed(['status' => 'Nieprawidłowy status.']);
		}

		$leak = $this->analyses->findLeak($project->id, $leakId);

		if ($leak === null) {
			throw new ValidationFailed(['leak' => 'Nie znaleziono problemu w tym projekcie.']);
		}

		$this->analyses->updateLeakStatus($leakId, $status, $userId);

		return $this->analyses->findLeak($project->id, $leakId);
	}

	/**
	 * Szacunek łącznej oszczędności dla wierszy przebiegu (bez pominiętych, bez podwójnego liczenia).
	 */
	public static function savings(array $leaks, array $summary): array
	{
		return SavingsEstimate::total($leaks, array_map('intval', (array) ($summary['campaign_monthly_spend'] ?? [])));
	}

	private function summary($context, array $rows): array
	{
		$monthly = [];

		foreach ($context->campaigns as $id => $campaign) {
			$cost = (int) ($campaign['current']['cost_micros'] ?? 0);

			if ($cost > 0) {
				$monthly[$id] = Statistics::monthlyRunRate($cost, $context->days());
			}
		}

		$decoded = array_map(fn ($row) => ['evidence' => $row['evidence']] + $row, $rows);

		return [
			'spend_micros' => $context->totalCost(),
			'clicks' => $context->totalClicks(),
			'google_conversions' => $context->totalConversions(),
			'goal_conversions' => $context->accountGoals(),
			'campaign_level_conversions' => (bool) ($context->breakdown['campaign_level_available'] ?? false),
			'data_state' => $context->dataState,
			'terms_analyzed' => count($context->terms),
			'keywords_analyzed' => count($context->keywords),
			'campaign_monthly_spend' => $monthly,
			'savings' => SavingsEstimate::total($decoded, $monthly),
			'counts' => [
				'high' => count(array_filter($rows, fn ($r) => $r['priority'] === 'high')),
				'medium' => count(array_filter($rows, fn ($r) => $r['priority'] === 'medium')),
				'low' => count(array_filter($rows, fn ($r) => $r['priority'] === 'low')),
			],
		];
	}
}
