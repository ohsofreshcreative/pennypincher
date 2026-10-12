<?php

namespace App\PennyPincher\Services\Analysis;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\RecommendationRepository;

/**
 * Odświeża rekomendacje na podstawie najnowszych wyników audytu, Budget Leaks i analizy słów dla okresu.
 *
 * - ta sama rekomendacja (odcisk) jest aktualizowana, a nie dublowana; status, notatka i historia zostają,
 * - rekomendacja, która nie pojawiła się w analizie tego samego okresu, dostaje oznaczenie „nieaktualna”,
 * - gdy problem wraca, oznaczenie jest zdejmowane (chyba że użytkownik oznaczył ją ręcznie).
 * Penny Pincher niczego nie zmienia w Google Ads – „Wykonana” to wyłącznie ręczne potwierdzenie użytkownika.
 */
class RecommendationService
{
	public function __construct(
		private Db $db,
		private RecommendationRepository $recommendations,
		private AuditRepository $audits,
		private AnalysisRepository $analyses,
		private MetricsRepository $metrics,
	) {
	}

	/**
	 * @return array{created: int, updated: int, obsolete: int, restored: int, total: int}
	 */
	public function refresh(Project $project, AnalysisPeriod $period, ?int $analysisRunId = null): array
	{
		$from = $period->range->fromString();
		$to = $period->range->toString();
		$audit = $this->audits->latestRunForPeriod($project->id, $from, $to);
		$leakRun = $this->analyses->latestRun($project->id, BudgetLeakService::TYPE, $from, $to);
		$keywordRun = $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE, $from, $to);

		$generator = new RecommendationGenerator($this->metrics->campaignOptions($project->id), $this->metrics->adGroups($project->id), $project->currency);
		$candidates = $generator->generate(
			$audit ? $this->audits->findings((int) $audit['id']) : [],
			$leakRun ? $this->analyses->leaks((int) $leakRun['id']) : [],
			$keywordRun ? $this->analyses->opportunities((int) $keywordRun['id']) : [],
			$keywordRun ? $this->analyses->negativeSuggestions((int) $keywordRun['id']) : [],
		);

		return $this->persist($project->id, $candidates, $from, $to, $analysisRunId ?? ($leakRun['id'] ?? null));
	}

	/**
	 * @param array<string, array<string, mixed>> $candidates
	 */
	public function persist(int $projectId, array $candidates, string $from, string $to, ?int $runId): array
	{
		return $this->db->transaction(function () use ($projectId, $candidates, $from, $to, $runId) {
			$existing = $this->recommendations->byFingerprint($projectId);
			$now = $this->db->now();
			$stats = ['created' => 0, 'updated' => 0, 'obsolete' => 0, 'restored' => 0, 'total' => count($candidates)];

			foreach ($candidates as $fingerprint => $candidate) {
				$values = [
					'source' => $candidate['source'],
					'type' => mb_substr($candidate['type'], 0, 40),
					'title' => mb_substr($candidate['title'], 0, 255),
					'description' => $candidate['description'],
					'rationale' => $candidate['rationale'],
					'evidence' => ['pairs' => $candidate['evidence'], 'sources' => $candidate['sources']],
					'action' => $candidate['action'],
					'campaign_id' => $candidate['campaign_id'],
					'ad_group_id' => $candidate['ad_group_id'],
					'priority' => RecommendationGenerator::priority((int) $candidate['priority_score']),
					'priority_score' => max(0, min(100, (int) $candidate['priority_score'])),
					'confidence' => $candidate['confidence'],
					'impact_micros' => $candidate['impact_micros'],
					'impact_label' => $candidate['impact_label'] !== null ? mb_substr($candidate['impact_label'], 0, 255) : null,
					'date_from' => $from,
					'date_to' => $to,
					'last_analysis_run_id' => $runId,
					'last_seen_at' => $now,
					'updated_at' => $now,
				];

				$current = $existing[$fingerprint] ?? null;

				if ($current === null) {
					$id = $this->recommendations->insert($values + [
						'project_id' => $projectId,
						'fingerprint' => $fingerprint,
						'status' => 'new',
						'first_seen_at' => $now,
					]);
					$this->recommendations->addEvent($id, $projectId, 'created', null, null, 'new');
					$stats['created']++;

					continue;
				}

				if ($current['is_obsolete'] && ! $current['manually_obsolete']) {
					$values['is_obsolete'] = 0;
					$values['obsolete_reason'] = null;
					$this->recommendations->addEvent((int) $current['id'], $projectId, 'restored', null, null, null, 'Problem ponownie wykryty w analizie.');
					$stats['restored']++;
				}

				$this->recommendations->update((int) $current['id'], $values);
				$stats['updated']++;
			}

			// Rekomendacje z tego samego okresu, których nie ma w nowej analizie – problem przestał występować.
			foreach ($existing as $fingerprint => $current) {
				if (isset($candidates[$fingerprint]) || $current['is_obsolete'] || $current['date_from'] !== $from || $current['date_to'] !== $to) {
					continue;
				}

				$this->recommendations->update((int) $current['id'], [
					'is_obsolete' => 1,
					'obsolete_reason' => 'Nie występuje w najnowszej analizie tego okresu',
					'updated_at' => $now,
				]);
				$this->recommendations->addEvent((int) $current['id'], $projectId, 'obsolete', null, null, null, 'Problem nie występuje w najnowszej analizie.');
				$stats['obsolete']++;
			}

			return $stats;
		});
	}

	public function updateStatus(Project $project, int $id, string $status, int $userId, ?string $note = null): array
	{
		if (! isset(RecommendationGenerator::STATUSES[$status])) {
			throw new ValidationFailed(['status' => 'Nieprawidłowy status.']);
		}

		$recommendation = $this->find($project, $id);
		$note = self::cleanNote($note);

		if ($recommendation['status'] !== $status) {
			$this->db->transaction(function () use ($project, $recommendation, $status, $userId, $note) {
				$this->recommendations->update((int) $recommendation['id'], ['status' => $status, 'updated_at' => $this->db->now()]);
				$this->recommendations->addEvent((int) $recommendation['id'], $project->id, 'status', $userId, $recommendation['status'], $status, $note);
			});
		}

		return $this->find($project, $id);
	}

	public function updateNote(Project $project, int $id, ?string $note, int $userId): array
	{
		$recommendation = $this->find($project, $id);
		$note = self::cleanNote($note);

		$this->db->transaction(function () use ($project, $recommendation, $note, $userId) {
			$this->recommendations->update((int) $recommendation['id'], ['note' => $note, 'updated_at' => $this->db->now()]);
			$this->recommendations->addEvent((int) $recommendation['id'], $project->id, 'note', $userId, null, null, $note);
		});

		return $this->find($project, $id);
	}

	public function markObsolete(Project $project, int $id, bool $obsolete, int $userId): array
	{
		$recommendation = $this->find($project, $id);

		$this->db->transaction(function () use ($project, $recommendation, $obsolete, $userId) {
			$this->recommendations->update((int) $recommendation['id'], [
				'is_obsolete' => $obsolete ? 1 : 0,
				'obsolete_reason' => $obsolete ? RecommendationRepository::MANUAL_OBSOLETE : null,
				'updated_at' => $this->db->now(),
			]);
			$this->recommendations->addEvent((int) $recommendation['id'], $project->id, $obsolete ? 'obsolete' : 'restored', $userId, null, null, $obsolete ? 'Oznaczona ręcznie jako nieaktualna.' : 'Przywrócona ręcznie.');
		});

		return $this->find($project, $id);
	}

	private function find(Project $project, int $id): array
	{
		$recommendation = $this->recommendations->find($project->id, $id);

		if ($recommendation === null) {
			throw new ValidationFailed(['recommendation' => 'Nie znaleziono rekomendacji w tym projekcie.']);
		}

		return $recommendation;
	}

	private static function cleanNote(?string $note): ?string
	{
		$note = trim(wp_strip_all_tags((string) $note));

		if (mb_strlen($note) > 2000) {
			throw new ValidationFailed(['note' => 'Notatka może mieć najwyżej 2000 znaków.']);
		}

		return $note === '' ? null : $note;
	}
}
