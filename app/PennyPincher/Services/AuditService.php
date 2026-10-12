<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\AuditScore;
use App\PennyPincher\Domain\Audit\Finding;
use App\PennyPincher\Domain\Audit\RuleRegistry;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Services\Audit\AuditContextBuilder;
use Throwable;

/**
 * Uruchamia reguły audytu, zapisuje wynik i problemy. Audyt tylko czyta dane – nigdy nie zmienia konta Google Ads.
 */
class AuditService
{
	public function __construct(
		private Db $db,
		private AuditRepository $audits,
		private AuditContextBuilder $builder,
	) {
	}

	public function run(Project $project, string $trigger = 'manual', ?int $userId = null, ?AnalysisPeriod $period = null): int
	{
		$period ??= AnalysisPeriod::fromPreset(AnalysisPeriod::DEFAULT, $project->timezone());
		$context = $this->builder->build($project, $period);
		$findings = [];
		$ruleErrors = [];

		foreach (RuleRegistry::all() as $rule) {
			try {
				array_push($findings, ...$rule->evaluate($context));
			} catch (Throwable $e) {
				// Błąd jednej reguły nie przerywa audytu – zapisujemy go w jakości danych przebiegu.
				$ruleErrors[] = $rule->id();
				error_log('[Penny Pincher] Reguła audytu ' . $rule->id() . ': ' . $e->getMessage());
			}
		}

		$statuses = $this->audits->lastStatuses($project->id);
		$now = $this->db->now();
		$rows = [];

		foreach (self::unique($findings) as $finding) {
			$carried = $statuses[$finding->fingerprint()] ?? null;

			$rows[] = [
				'project_id' => $project->id,
				'rule_id' => $finding->ruleId,
				'category' => $finding->category,
				'severity' => $finding->severity->value,
				'title' => mb_substr($finding->title, 0, 255),
				'description' => $finding->description,
				'evidence' => $finding->evidence,
				'recommendation' => $finding->recommendation,
				'entity_type' => $finding->entityType,
				'entity_id' => $finding->entityId,
				'fingerprint' => $finding->fingerprint(),
				'penalty' => $finding->penalty,
				'score_dimension' => $finding->dimension->value,
				'status' => $carried['status'] ?? 'new',
				'status_changed_by' => $carried['status_changed_by'] ?? null,
				'status_changed_at' => $carried['status_changed_at'] ?? null,
				'detected_at' => $now,
			];
		}

		$score = AuditScore::calculate($rows, AuditScore::unavailableReason($context));

		return $this->db->transaction(function () use ($project, $trigger, $userId, $context, $rows, $score, $ruleErrors, $period) {
			$runId = $this->audits->createRun([
				'project_id' => $project->id,
				'status' => $ruleErrors === [] ? 'completed' : 'partial',
				'score' => $score['score'],
				'score_breakdown' => $score,
				'data_quality' => self::dataQuality($context, $ruleErrors),
				'date_from' => $context->range->fromString(),
				'date_to' => $context->range->toString(),
				'range_preset' => $period->preset,
				'rules_version' => RuleRegistry::VERSION,
				'trigger_type' => $trigger,
				'requested_by' => $userId,
			]);

			if ($rows !== []) {
				$this->audits->insertFindings(array_map(fn ($row) => ['audit_run_id' => $runId] + $row, $rows));
			}

			return $runId;
		});
	}

	/**
	 * Zmiana statusu problemu (Nowy / Przejrzany / Pominięty). Wynik audytu jest przeliczany,
	 * bo pominięte problemy nie obniżają Audit Score.
	 */
	public function updateStatus(Project $project, int $findingId, string $status, int $userId): array
	{
		if (! isset(AuditRepository::STATUSES[$status])) {
			throw new ValidationFailed(['status' => 'Nieprawidłowy status.']);
		}

		$finding = $this->audits->findFinding($project->id, $findingId);

		if ($finding === null) {
			throw new ValidationFailed(['finding' => 'Nie znaleziono problemu w tym projekcie.']);
		}

		$this->db->transaction(function () use ($project, $finding, $status, $userId) {
			$this->audits->updateFinding((int) $finding['id'], [
				'status' => $status,
				'status_changed_by' => $userId,
				'status_changed_at' => $this->db->now(),
			]);

			$run = $this->audits->findRun($project->id, (int) $finding['audit_run_id']);
			$score = AuditScore::calculate($this->audits->findings((int) $run['id']), $run['score_breakdown']['reason'] ?? null);
			$this->audits->updateRun((int) $run['id'], ['score' => $score['score'], 'score_breakdown' => $score]);
		});

		return $this->audits->findFinding($project->id, $findingId);
	}

	/**
	 * @param array<int, Finding> $findings
	 * @return array<int, Finding>
	 */
	private static function unique(array $findings): array
	{
		$unique = [];

		foreach ($findings as $finding) {
			$unique[$finding->fingerprint()] ??= $finding;
		}

		return array_values($unique);
	}

	private static function dataQuality(AuditContext $context, array $ruleErrors): array
	{
		$coverage = $context->coverage;

		return [
			'covered_days' => min($coverage->coveredDays, $coverage->totalDays),
			'total_days' => $coverage->totalDays,
			'source' => $coverage->isEmpty() ? null : $coverage->source(),
			'has_api' => $coverage->hasApi(),
			'has_imports' => $coverage->hasImports(),
			'overridden_by_api' => $coverage->overriddenByApi,
			'connection_status' => $context->connection['status'] ?? null,
			'last_sync_at' => $context->lastSyncAt,
			'conversion_settings_known' => $context->hasConversionSettings(),
			'activity' => $context->activity,
			'rule_errors' => $ruleErrors,
		];
	}
}
