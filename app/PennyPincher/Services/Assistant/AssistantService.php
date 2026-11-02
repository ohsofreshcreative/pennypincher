<?php

namespace App\PennyPincher\Services\Assistant;

use App\PennyPincher\Domain\Assistant\Explainer;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use App\PennyPincher\Services\Changes\ChangeProposalService;

/**
 * Asystent (pp2_assistant): „co warto zrobić dzisiaj” i wyjaśnienia rekomendacji. Tylko odczyt istniejących wyników
 * (rekomendacje, alerty, zestawy zmian, synchronizacje) w obrębie projektu. Bez własnego harmonogramu – dane odświeża
 * istniejący przebieg pp:sync:work (synchronizacja → analiza → monitoring). Bez zewnętrznego modelu AI.
 */
class AssistantService
{
	public const TOP = 3;

	public function __construct(
		private Db $db,
		private RecommendationRepository $recommendations,
		private ChangeSetRepository $sets,
		private GoogleAdsConnectionRepository $connections,
		private SyncRunRepository $runs,
	) {
	}

	public function digest(Project $project): array
	{
		$open = $this->db->select(
			'SELECT id FROM ' . $this->db->table('recommendations') . " WHERE project_id = ? AND status IN ('new', 'to_review') AND is_obsolete = 0 ORDER BY priority_score DESC, id DESC LIMIT " . self::TOP,
			[$project->id]
		);
		$top = array_map(fn ($row) => $this->explain($project, (int) $row['id']), $open);
		$counts = $this->db->first(
			'SELECT SUM(status IN (\'new\', \'to_review\') AND is_obsolete = 0) AS to_review, SUM(status = \'accepted\' AND is_obsolete = 0) AS accepted FROM ' . $this->db->table('recommendations') . ' WHERE project_id = ?',
			[$project->id]
		) ?? [];
		$alerts = $this->db->select(
			'SELECT id, title, severity, status FROM ' . $this->db->table('alerts') . " WHERE project_id = ? AND status IN ('new', 'acknowledged') ORDER BY FIELD(severity, 'critical', 'warning', 'info'), last_detected_at DESC LIMIT 5",
			[$project->id]
		);
		$consent = $this->db->select(
			'SELECT id, title, status FROM ' . $this->db->table('change_sets') . ' WHERE project_id = ? AND status IN (?, ?, ?) ORDER BY updated_at DESC LIMIT 10',
			[$project->id, ChangeStatus::REVIEW, ChangeStatus::READY, ChangeStatus::RECONCILE]
		);
		$connection = $this->connections->currentForProject($project->id);
		$lastSync = $connection !== null ? $this->runs->lastCompleted((int) $connection['id']) : null;

		return [
			'top' => $top,
			'to_review' => (int) ($counts['to_review'] ?? 0),
			'accepted' => (int) ($counts['accepted'] ?? 0),
			'alerts' => $alerts,
			'consent' => $consent,
			'freshness' => [
				'analysis_at' => $this->db->scalar('SELECT MAX(created_at) FROM ' . $this->db->table('analysis_runs') . ' WHERE project_id = ?', [$project->id]),
				'sync_at' => $lastSync['finished_at'] ?? null,
				'connected' => $connection !== null,
			],
		];
	}

	public function explain(Project $project, int $recommendationId): array
	{
		$rec = $this->recommendations->find($project->id, $recommendationId) ?? throw new NotFound('Rekomendacja nie istnieje w tym projekcie.');
		$change = $this->sets->latestForSources($project->id, 'recommendation', [$recommendationId])[$recommendationId] ?? null;

		return ['recommendation' => $rec, 'explanation' => Explainer::recommendation($rec, $change, ChangeProposalService::deployable($rec) === null), 'change' => $change];
	}
}
