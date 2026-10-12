<?php

namespace App\PennyPincher\Services\Monitoring;

use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\Money;
use App\PennyPincher\Domain\Monitoring\AlertDetector;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\AlertRepository;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use App\PennyPincher\Services\Analysis\ConversionBreakdown;
use App\PennyPincher\Services\Audit\AuditContextBuilder;
use App\PennyPincher\Services\DashboardService;
use DateTimeImmutable;

/**
 * Monitoring po synchronizacji (w ramach istniejącej kolejki – bez osobnego crona) albo na żądanie.
 *
 * - Idempotentny: ten sam przebieg synchronizacji nie jest monitorowany dwa razy (pp_monitoring_runs).
 * - Deduplikacja: alert per odcisk (typ + kampania/zdarzenie). Ponowne wykrycie aktualizuje istniejący alert;
 *   rozwiązany alert, którego problem wraca, jest otwierany ponownie (z historią).
 * - Alert, którego warunek ustał, nie jest automatycznie rozwiązywany – dostaje znacznik „warunek nie występuje”.
 * - Monitoring nie wykonuje zapytań do Google Ads ani DataForSEO; czyta tylko dane zapisane w bazie.
 */
class MonitoringService
{
	public function __construct(
		private Db $db,
		private AlertRepository $alerts,
		private MetricsRepository $metrics,
		private ConversionBreakdown $breakdown,
		private GoogleAdsConnectionRepository $connections,
		private SyncRunRepository $runs,
		private AuditRepository $audits,
		private AuditContextBuilder $auditContext,
	) {
	}

	/**
	 * @return array{skipped: bool, detected: int, created: int, reopened: int, updated: int, inactive: int}|array{skipped: true}
	 */
	public function run(Project $project, string $trigger = 'manual', ?int $syncRunId = null, ?DateTimeImmutable $today = null): array
	{
		$runId = $this->alerts->startRun($project->id, $syncRunId, $trigger);

		if ($runId === null) {
			return ['skipped' => true, 'detected' => 0, 'created' => 0, 'reopened' => 0, 'updated' => 0, 'inactive' => 0];
		}

		$detected = AlertDetector::detect($this->input($project, $today));
		$summary = $this->persist($project, $detected);
		$this->alerts->finishRun($runId, $summary);

		return ['skipped' => false] + $summary;
	}

	/**
	 * Dane wejściowe detektora (okna 7 / 28 dni, 14 / 14 dni dla celów, tempo, synchronizacja, audyt).
	 */
	public function input(Project $project, ?DateTimeImmutable $today = null): array
	{
		$today = ($today ?? new DateTimeImmutable('now', $project->timezone()))->setTime(0, 0);
		$y = $today->modify('-1 day');
		$d = fn (int $days) => $y->modify("-{$days} days")->format('Y-m-d');
		$recent = new DateRange($d(6), $y->format('Y-m-d'));
		$baseline = new DateRange($d(34), $d(7));
		$all = new DateRange($d(34), $y->format('Y-m-d'));
		$daily = $this->metrics->campaignDaily($project->id, $this->metrics->resolve('campaign_metrics', $project->id, $all));
		$recentResolved = $this->metrics->resolve('campaign_metrics', $project->id, $recent);
		$baselineResolved = $this->metrics->resolve('campaign_metrics', $project->id, $baseline);
		$campaigns = [];
		$baselineSpend = 0;

		foreach ($this->db->select('SELECT id, name, status FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ?', [$project->id]) as $row) {
			$id = (int) $row['id'];
			$sum = fn (string $from, string $to) => array_reduce(
				array_filter(array_keys($daily[$id] ?? []), fn ($date) => $date >= $from && $date <= $to),
				fn ($carry, $date) => [
					'impressions' => $carry['impressions'] + $daily[$id][$date]['impressions'],
					'clicks' => $carry['clicks'] + $daily[$id][$date]['clicks'],
					'cost_micros' => $carry['cost_micros'] + $daily[$id][$date]['cost_micros'],
					'conversions' => $carry['conversions'] + $daily[$id][$date]['conversions'],
				],
				['impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0.0]
			);
			$b = $sum($baseline->fromString(), $baseline->toString());
			$baselineSpend += $b['cost_micros'];
			$last3 = $sum($d(2), $y->format('Y-m-d'));
			$activeDays = count(array_filter($daily[$id] ?? [], fn ($m, $date) => $date >= $baseline->fromString() && $date <= $baseline->toString() && ($m['impressions'] > 0 || $m['cost_micros'] > 0), ARRAY_FILTER_USE_BOTH));

			$campaigns[$id] = [
				'name' => (string) $row['name'],
				'status' => $row['status'],
				'recent' => $sum($recent->fromString(), $recent->toString()),
				'baseline' => $b,
				'last3_impressions' => $recentResolved->isComplete() ? $last3['impressions'] : null,
				'baseline_active_days' => $activeDays,
			];
		}

		$r14 = new DateRange($d(13), $y->format('Y-m-d'));
		$p14 = new DateRange($d(27), $d(14));
		$goalsRecent = $this->breakdown->forPeriod($project->id, $r14)['account'];
		$goalsPrevious = $this->breakdown->forPeriod($project->id, $p14)['account'];
		$costR = $this->metrics->resolve('campaign_metrics', $project->id, $r14);
		$costP = $this->metrics->resolve('campaign_metrics', $project->id, $p14);

		$connection = $this->connections->currentForProject($project->id);
		$lastRun = $connection ? ($this->runs->recentForProject($project->id, 1)[0] ?? null) : null;
		$lastCompleted = $connection ? $this->runs->lastCompleted((int) $connection['id']) : null;

		return [
			'currency' => $project->currency,
			'min_cost_micros' => max(50 * Money::MICROS, (int) round($baselineSpend / 4 * 0.01)),
			'recent_complete' => ! $recentResolved->isEmpty() && $recentResolved->isComplete(),
			'baseline_complete' => ! $baselineResolved->isEmpty() && $baselineResolved->isComplete(),
			'campaigns' => $campaigns,
			'goals' => [
				'recent' => $goalsRecent['goals'] === null ? null : (float) $goalsRecent['goals'],
				'previous' => $goalsPrevious['goals'] === null ? null : (float) $goalsPrevious['goals'],
				'recent_cost' => $costR->isEmpty() ? 0 : (int) $this->metrics->campaignTotals($project->id, $costR)['cost_micros'],
				'previous_cost' => $costP->isEmpty() ? 0 : (int) $this->metrics->campaignTotals($project->id, $costP)['cost_micros'],
				'google_recent' => (float) ($goalsRecent['google'] ?? 0),
				'google_previous' => (float) ($goalsPrevious['google'] ?? 0),
				'micro_recent' => (float) ($goalsRecent['google_micro'] ?? 0),
				'micro_previous' => (float) ($goalsPrevious['google_micro'] ?? 0),
			],
			'pacing' => $project->monthlyBudgetMicros ? DashboardService::pacing($project, $this->auditContext->monthToDate($project, $today)) : null,
			'sync' => [
				'status' => $connection['status'] ?? null,
				'last_run_status' => $lastRun['status'] ?? null,
				'last_completed_at' => $lastCompleted['finished_at'] ?? null,
				'now' => gmdate('Y-m-d H:i:s'),
			],
			'audit_new_critical' => $this->newCriticalFindings($project->id),
			'period' => ['recent_from' => $recent->fromString(), 'recent_to' => $recent->toString(), 'baseline_from' => $baseline->fromString(), 'baseline_to' => $baseline->toString()],
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $detected
	 */
	public function persist(Project $project, array $detected): array
	{
		return $this->db->transaction(function () use ($project, $detected) {
			$existing = $this->alerts->byFingerprint($project->id);
			$now = $this->db->now();
			$stats = ['detected' => count($detected), 'created' => 0, 'reopened' => 0, 'updated' => 0, 'inactive' => 0];

			foreach ($detected as $fp => $alert) {
				$values = [
					'type' => $alert['type'],
					'severity' => $alert['severity'],
					'title' => mb_substr($alert['title'], 0, 255),
					'message' => $alert['message'],
					'evidence' => $alert['evidence'],
					'campaign_id' => $alert['campaign_id'],
					'period_from' => $alert['period_from'],
					'period_to' => $alert['period_to'],
					'condition_active' => 1,
					'last_detected_at' => $now,
				];
				$current = $existing[$fp] ?? null;

				if ($current === null) {
					$id = $this->alerts->insert($values + ['project_id' => $project->id, 'fingerprint' => $fp, 'status' => 'new', 'first_detected_at' => $now, 'updated_at' => $now]);
					$this->alerts->addEvent($id, $project->id, 'detected', null, null, 'new');
					$stats['created']++;
					continue;
				}

				$values['occurrences'] = (int) $current['occurrences'] + 1;

				if ($current['status'] === 'resolved') {
					$values += ['status' => 'new', 'resolved_at' => null, 'last_notified_at' => null];
					$this->alerts->addEvent((int) $current['id'], $project->id, 'reopened', null, 'resolved', 'new', 'Problem pojawił się ponownie.');
					$stats['reopened']++;
				} elseif ($current['status'] === 'muted' && $current['muted_until'] !== null && $current['muted_until'] <= $now) {
					$values += ['status' => 'new', 'muted_until' => null, 'last_notified_at' => null];
					$this->alerts->addEvent((int) $current['id'], $project->id, 'unmuted', null, 'muted', 'new', 'Minął czas wyciszenia, a problem nadal występuje.');
				} else {
					$stats['updated']++;
				}

				$this->alerts->update((int) $current['id'], $values);
			}

			foreach ($existing as $fp => $alert) {
				if (! isset($detected[$fp]) && $alert['condition_active'] && $alert['status'] !== 'resolved') {
					$this->alerts->update((int) $alert['id'], ['condition_active' => 0]);
					$this->alerts->addEvent((int) $alert['id'], $project->id, 'inactive', null, null, null, 'Warunek nie występuje w ostatnim sprawdzeniu – oznacz jako rozwiązany, jeśli problem usunięto.');
					$stats['inactive']++;
				}
			}

			return $stats;
		});
	}

	public function updateStatus(Project $project, int $id, string $status, int $userId, ?string $note = null, ?int $muteDays = null): array
	{
		if (! array_key_exists($status, AlertRepository::STATUSES)) {
			throw new ValidationFailed(['status' => 'Nieprawidłowy status alertu.']);
		}

		$alert = $this->alerts->find($project->id, $id);

		if ($alert === null) {
			throw new ValidationFailed(['alert' => 'Nie znaleziono alertu w tym projekcie.']);
		}

		$note = trim(wp_strip_all_tags((string) $note));
		$values = ['status' => $status];

		if ($status === 'muted') {
			$values['muted_until'] = $muteDays !== null && $muteDays > 0 ? gmdate('Y-m-d H:i:s', time() + min(365, $muteDays) * DAY_IN_SECONDS) : null;
		}

		if ($status === 'resolved') {
			$values['resolved_at'] = $this->db->now();
		}

		$this->alerts->update($id, $values);
		$this->alerts->addEvent($id, $project->id, 'status', $userId, $alert['status'], $status, ($note === '' ? null : mb_substr($note, 0, 1000)) ?? ($status === 'muted' && isset($values['muted_until']) && $values['muted_until'] ? 'Wyciszono do ' . $values['muted_until'] . ' UTC' : null));

		return $this->alerts->find($project->id, $id);
	}

	/**
	 * Krytyczne problemy najnowszego audytu, których nie było w poprzednim audycie tego samego okresu.
	 *
	 * @return array<int, array{fingerprint: string, title: string, id: int}>
	 */
	private function newCriticalFindings(int $projectId): array
	{
		$runs = $this->db->select('SELECT id, date_from, date_to, range_preset FROM ' . $this->db->table('audit_runs') . ' WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);

		if ($runs === []) {
			return [];
		}

		$latest = $runs[0];
		$previous = $this->db->first(
			'SELECT id FROM ' . $this->db->table('audit_runs') . ' WHERE project_id = ? AND id < ? AND (range_preset = ? OR (date_from = ? AND date_to = ?)) ORDER BY id DESC LIMIT 1',
			[$projectId, (int) $latest['id'], (string) $latest['range_preset'], $latest['date_from'], $latest['date_to']]
		);

		if ($previous === null) {
			return [];
		}

		$before = array_flip(array_column($this->audits->findings((int) $previous['id']), 'fingerprint'));

		return array_values(array_map(
			fn ($f) => ['fingerprint' => (string) $f['fingerprint'], 'title' => (string) $f['title'], 'id' => (int) $f['id']],
			array_filter($this->audits->findings((int) $latest['id']), fn ($f) => $f['severity'] === 'critical' && $f['status'] !== 'dismissed' && ! isset($before[$f['fingerprint']]))
		));
	}
}
