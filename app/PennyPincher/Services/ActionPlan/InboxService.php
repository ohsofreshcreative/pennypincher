<?php

namespace App\PennyPincher\Services\ActionPlan;

use App\PennyPincher\Domain\ActionPlan\Inbox;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\ActionItemRepository;
use App\PennyPincher\Repositories\AlertRepository;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\Changes\ChangeEffectsService;
use App\PennyPincher\Support\AppUrl;

/**
 * Wspólna kolejka Planu działania i ścieżka jednej rekomendacji (rekomendacja → zgoda → wykonanie → wynik → monitoring).
 * Tylko odczyt istniejących rekordów, zawsze w obrębie projektu; statusy zmienia się w modułach źródłowych.
 */
class InboxService
{
	public const LIMIT = 100;

	public function __construct(
		private Db $db,
		private RecommendationRepository $recommendations,
		private ActionItemRepository $actions,
		private ChangeSetRepository $sets,
		private ChangeEffectsService $effects,
	) {
	}

	/**
	 * Liczba pozycji w każdej zakładce (z tych samych statusów co lista).
	 *
	 * @return array<string, int>
	 */
	public function counts(Project $project): array
	{
		$counts = array_fill_keys(array_keys(Inbox::TABS), 0);
		$sources = [
			'recommendation' => 'SELECT status, is_obsolete, COUNT(*) AS n FROM ' . $this->db->table('recommendations') . ' WHERE project_id = ? GROUP BY status, is_obsolete',
			'action' => 'SELECT status, is_obsolete, COUNT(*) AS n FROM ' . $this->db->table('action_items') . ' WHERE project_id = ? GROUP BY status, is_obsolete',
			'change' => 'SELECT status, 0 AS is_obsolete, COUNT(*) AS n FROM ' . $this->db->table('change_sets') . ' WHERE project_id = ? GROUP BY status',
			'alert' => 'SELECT status, 0 AS is_obsolete, COUNT(*) AS n FROM ' . $this->db->table('alerts') . ' WHERE project_id = ? GROUP BY status',
		];

		foreach ($sources as $kind => $sql) {
			foreach ($this->db->select($sql, [$project->id]) as $row) {
				$tab = Inbox::tabOf($kind, (string) $row['status']);

				if ($tab !== null && ! ((int) $row['is_obsolete'] === 1 && $this->activeOnly($kind, $tab))) {
					$counts[$tab] += (int) $row['n'];
				}
			}
		}

		return $counts;
	}

	/**
	 * Pozycje zakładki – rekomendacje, zadania, zestawy zmian i alerty w jednym formacie.
	 *
	 * @return array<int, array{kind: string, id: int, title: string, status: string, status_label: string, tone: string, priority: ?string, updated_at: ?string, url: string, trace: ?string, change: ?array, note: ?string}>
	 */
	public function items(Project $project, string $tab): array
	{
		$map = Inbox::MAP[Inbox::tab($tab)];
		$rows = [];

		if ($map['recommendations'] !== []) {
			$recs = $this->select('recommendations', 'id, title, status, priority, priority_score, updated_at', $project->id, $map['recommendations'], $this->activeOnly('recommendation', $tab), 'priority_score DESC, id DESC');
			$links = $this->sets->latestForSources($project->id, 'recommendation', array_column($recs, 'id'));

			foreach ($recs as $r) {
				$rows[] = $this->row('recommendation', $r, RecommendationGenerator::STATUSES[$r['status']] ?? $r['status'], Inbox::TONES['recommendation'][$r['status']] ?? 'neutral', AppUrl::project($project->id, 'recommendations', ['status' => $r['status'], 'obsolete' => 'all', 'open' => $r['id']]) . '#rec-' . $r['id'], $links[(int) $r['id']] ?? null, $project);
			}
		}

		if ($map['actions'] !== []) {
			$items = $this->select('action_items', 'id, title, status, priority, priority_score, updated_at, recommendation_ids', $project->id, $map['actions'], $this->activeOnly('action', $tab), "FIELD(status, 'in_progress', 'blocked', 'todo', 'done', 'rejected'), position, id");
			$links = $this->sets->latestForSources($project->id, 'action_item', array_column($items, 'id'));

			foreach ($items as $i) {
				$row = $this->row('action', $i, ActionItemRepository::STATUSES[$i['status']] ?? $i['status'], Inbox::TONES['action'][$i['status']] ?? 'neutral', AppUrl::project($project->id, 'action-plan', ['status' => $i['status'], 'open' => $i['id']]) . '#action-' . $i['id'], $links[(int) $i['id']] ?? null, $project);
				$recIds = json_decode((string) ($i['recommendation_ids'] ?? ''), true) ?: [];
				$row['trace'] = $recIds !== [] ? AppUrl::project($project->id, 'action-plan/trace/' . (int) $recIds[0]) : null;
				$rows[] = $row;
			}
		}

		if ($map['changes'] !== []) {
			foreach ($this->select('change_sets', 'id, title, status, risk_level AS priority, updated_at, source_type, source_id', $project->id, $map['changes'], false, 'updated_at DESC, id DESC') as $s) {
				$row = $this->row('change', $s, ChangeStatus::LABELS[$s['status']] ?? $s['status'], ChangeStatus::TONES[$s['status']] ?? 'neutral', AppUrl::project($project->id, 'changes/' . $s['id']), null, $project);
				$row['priority'] = null;
				$row['note'] = ChangeStatus::EXPLANATIONS[$s['status']] ?? null;
				$row['trace'] = $s['source_type'] === 'recommendation' && $s['source_id'] !== null ? AppUrl::project($project->id, 'action-plan/trace/' . (int) $s['source_id']) : null;
				$rows[] = $row;
			}
		}

		if ($map['alerts'] !== []) {
			foreach ($this->select('alerts', 'id, title, status, severity AS priority, last_detected_at AS updated_at, recommendation_id', $project->id, $map['alerts'], false, "FIELD(severity, 'critical', 'warning', 'info'), last_detected_at DESC") as $a) {
				$row = $this->row('alert', $a, AlertRepository::STATUSES[$a['status']] ?? $a['status'], Inbox::TONES['alert'][$a['status']] ?? 'neutral', AppUrl::project($project->id, 'monitoring', ['status' => $a['status'], 'open' => $a['id']]) . '#alert-' . $a['id'], null, $project);
				$row['trace'] = $a['recommendation_id'] !== null ? AppUrl::project($project->id, 'action-plan/trace/' . (int) $a['recommendation_id']) : null;
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Ścieżka jednej rekomendacji: historia decyzji, zadania, zestawy zmian (zgoda, wdrożenie, wynik API,
	 * weryfikacja) i porównanie efektów po wdrożeniu.
	 */
	public function trace(Project $project, int $recommendationId): array
	{
		$rec = $this->recommendations->find($project->id, $recommendationId) ?? throw new NotFound('Rekomendacja nie istnieje w tym projekcie.');

		$items = array_values(array_filter(
			$this->actions->list($project->id, ['obsolete' => 'all']),
			fn ($i) => in_array($recommendationId, array_map('intval', $i['recommendation_ids']), true)
		));
		$itemIds = array_map(fn ($i) => (int) $i['id'], $items);

		$setIds = array_map('intval', $this->db->column(
			'SELECT id FROM ' . $this->db->table('change_sets') . " WHERE project_id = ? AND ((source_type = 'recommendation' AND source_id = ?)"
			. ($itemIds !== [] ? " OR (source_type = 'action_item' AND source_id IN (" . implode(',', array_fill(0, count($itemIds), '?')) . '))' : '') . ') ORDER BY id',
			[$project->id, $recommendationId, ...$itemIds]
		));

		$changes = [];

		foreach ($setIds as $id) {
			$set = $this->sets->find($project->id, $id);

			if ($set === null) {
				continue;
			}

			$changes[] = [
				'set' => $set,
				'operations' => $this->sets->operations($id),
				'events' => $this->sets->events($project->id, $id),
				'attempts' => $this->sets->attempts($project->id, $id),
				'effects' => $this->effects->forSet($project, $set),
			];
		}

		$alerts = $this->db->select('SELECT id, title, status, severity, last_detected_at FROM ' . $this->db->table('alerts') . ' WHERE project_id = ? AND recommendation_id = ? ORDER BY id', [$project->id, $recommendationId]);

		return [
			'recommendation' => $rec,
			'events' => $this->recommendations->events($project->id, $recommendationId),
			'items' => array_map(fn ($i) => $i + ['events' => $this->actions->events($project->id, (int) $i['id'])], $items),
			'changes' => $changes,
			'alerts' => $alerts,
		];
	}

	// --- Pomocnicze -------------------------------------------------------------------------------

	/** Otwarte zakładki pokazują tylko aktualne rekomendacje i zadania (jak domyślne widoki modułów). */
	private function activeOnly(string $kind, string $tab): bool
	{
		return in_array($kind, ['recommendation', 'action'], true) && in_array($tab, ['review', 'accepted', 'in_progress'], true);
	}

	private function select(string $table, string $columns, int $projectId, array $statuses, bool $activeOnly, string $order): array
	{
		return $this->db->select(
			"SELECT {$columns} FROM " . $this->db->table($table) . ' WHERE project_id = ? AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'
			. ($activeOnly ? ' AND is_obsolete = 0' : '') . " ORDER BY {$order} LIMIT " . self::LIMIT,
			[$projectId, ...$statuses]
		);
	}

	private function row(string $kind, array $r, string $label, string $tone, string $url, ?array $change, Project $project): array
	{
		return [
			'kind' => $kind,
			'id' => (int) $r['id'],
			'title' => (string) $r['title'],
			'status' => (string) $r['status'],
			'status_label' => $label,
			'tone' => $tone,
			'priority' => $r['priority'] ?? null,
			'updated_at' => $r['updated_at'] ?? null,
			'url' => $url,
			'trace' => $kind === 'recommendation' ? AppUrl::project($project->id, 'action-plan/trace/' . (int) $r['id']) : null,
			'change' => $change === null ? null : ['id' => (int) $change['id'], 'status' => $change['status'], 'label' => ChangeStatus::LABELS[$change['status']] ?? $change['status'], 'url' => AppUrl::project($project->id, 'changes/' . (int) $change['id'])],
			'note' => null,
		];
	}
}
