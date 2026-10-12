<?php

namespace App\PennyPincher\Services\ActionPlan;

use App\PennyPincher\Domain\ActionPlan\ActionPlanBuilder;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\ActionItemRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\Analysis\RecommendationService;

/**
 * Action Plan: generowanie działań z rekomendacji, statusy (Do zrobienia → Wykonane), notatki i historia.
 *
 * - Działania są aktualizowane po odcisku – status, notatka i historia należą do użytkownika.
 * - „Wykonane” ustawia wyłącznie użytkownik; zniknięcie problemu w kolejnej analizie oznacza tylko „nieaktualne”.
 * - Działanie z niespełnioną zależnością (np. pomiar) dostaje status „Zablokowane” tylko automatycznie przy utworzeniu;
 *   gdy zależności zostaną wykonane, wraca do „Do zrobienia”. Ręcznie ustawiony status nie jest zmieniany.
 */
class ActionPlanService
{
	public function __construct(
		private Db $db,
		private ActionItemRepository $items,
		private MetricsRepository $metrics,
		private RecommendationService $recommendations,
	) {
	}

	/**
	 * @return array{created: int, updated: int, obsolete: int, total: int}
	 */
	public function refresh(Project $project, ?string $from = null, ?string $to = null): array
	{
		$recs = $this->db->select(
			'SELECT * FROM ' . $this->db->table('recommendations') . " WHERE project_id = ? AND is_obsolete = 0 AND status IN ('new', 'to_review', 'accepted') ORDER BY priority_score DESC LIMIT 500",
			[$project->id]
		);
		$recs = array_map(function ($row) {
			$evidence = $row['evidence'] ? (json_decode((string) $row['evidence'], true) ?: []) : [];
			$row['evidence'] = $evidence['pairs'] ?? [];
			$row['sources'] = $evidence['sources'] ?? [$row['source']];
			$row['impact_micros'] = $row['impact_micros'] === null ? null : (int) $row['impact_micros'];

			return $row;
		}, $recs);

		$monthly = $project->monthlyBudgetMicros;
		$actions = ActionPlanBuilder::build($recs, $this->metrics->campaignOptions($project->id), $monthly);

		return $this->db->transaction(function () use ($project, $actions, $from, $to) {
			$existing = $this->items->byFingerprint($project->id);
			$now = $this->db->now();
			$stats = ['created' => 0, 'updated' => 0, 'obsolete' => 0, 'total' => count($actions)];

			foreach ($actions as $fp => $action) {
				$values = [
					'category' => $action['category'],
					'title' => $action['title'],
					'description' => $action['description'],
					'rationale' => $action['rationale'],
					'details' => $action['details'],
					'sources' => $action['sources'],
					'recommendation_ids' => $action['recommendation_ids'],
					'depends_on' => $action['depends_on'],
					'priority' => $action['priority'],
					'priority_score' => $action['priority_score'],
					'confidence' => $action['confidence'],
					'effort' => $action['effort'],
					'expected_effect' => mb_substr((string) $action['expected_effect'], 0, 255),
					'campaign_id' => $action['campaign_id'],
					'position' => $action['position'],
					'date_from' => $from,
					'date_to' => $to,
					'is_obsolete' => 0,
					'last_seen_at' => $now,
				];

				if (! isset($existing[$fp])) {
					$id = $this->items->insert($values + ['project_id' => $project->id, 'fingerprint' => $fp, 'status' => 'todo', 'first_seen_at' => $now, 'updated_at' => $now]);
					$this->items->addEvent($id, $project->id, 'created', null, null, 'todo');
					$stats['created']++;
					continue;
				}

				if ($existing[$fp]['is_obsolete']) {
					$this->items->addEvent((int) $existing[$fp]['id'], $project->id, 'restored', null, null, null, 'Problem ponownie wykryty.');
				}

				$this->items->update((int) $existing[$fp]['id'], $values);
				$stats['updated']++;
			}

			foreach ($existing as $fp => $item) {
				if (! isset($actions[$fp]) && ! $item['is_obsolete']) {
					$this->items->update((int) $item['id'], ['is_obsolete' => 1]);
					$this->items->addEvent((int) $item['id'], $project->id, 'obsolete', null, null, null, 'Nie występuje w najnowszych rekomendacjach – status bez zmian.');
					$stats['obsolete']++;
				}
			}

			$this->syncBlocked($project);

			return $stats;
		});
	}

	/**
	 * Automatyczna blokada/odblokowanie wyłącznie działań, których status ustawił system (auto_blocked).
	 */
	public function syncBlocked(Project $project): void
	{
		$items = $this->items->byFingerprint($project->id);

		foreach ($items as $item) {
			if ($item['is_obsolete']) {
				continue;
			}

			$open = array_filter($item['depends_on'], fn ($dep) => isset($items[$dep]) && ! $items[$dep]['is_obsolete'] && ! in_array($items[$dep]['status'], ['done', 'rejected'], true));

			if ($open !== [] && $item['status'] === 'todo' && ! $item['auto_blocked'] && $this->neverChanged((int) $item['id'])) {
				$this->items->update((int) $item['id'], ['status' => 'blocked', 'auto_blocked' => 1]);
				$this->items->addEvent((int) $item['id'], $project->id, 'blocked', null, 'todo', 'blocked', 'Czeka na: ' . implode(', ', array_map(fn ($dep) => $items[$dep]['title'], $open)));
			} elseif ($open === [] && $item['status'] === 'blocked' && $item['auto_blocked']) {
				$this->items->update((int) $item['id'], ['status' => 'todo', 'auto_blocked' => 0]);
				$this->items->addEvent((int) $item['id'], $project->id, 'unblocked', null, 'blocked', 'todo', 'Zależności zostały wykonane.');
			}
		}
	}

	public function updateStatus(Project $project, int $id, string $status, int $userId, ?string $note = null, bool $alsoRecommendations = false): array
	{
		if (! array_key_exists($status, ActionItemRepository::STATUSES)) {
			throw new ValidationFailed(['status' => 'Nieprawidłowy status.']);
		}

		$item = $this->find($project, $id);
		$note = self::cleanNote($note);

		$this->db->transaction(function () use ($project, $item, $status, $userId, $note, $alsoRecommendations) {
			if ($item['status'] !== $status) {
				$this->items->update((int) $item['id'], ['status' => $status, 'auto_blocked' => 0]);
				$this->items->addEvent((int) $item['id'], $project->id, 'status', $userId, $item['status'], $status, $note);
			}

			// Opcjonalnie: przeniesienie decyzji na powiązane rekomendacje (z notatką), bez dublowania historii.
			if ($alsoRecommendations && in_array($status, ['done', 'rejected'], true)) {
				foreach ($item['recommendation_ids'] as $recId) {
					try {
						$this->recommendations->updateStatus($project, (int) $recId, $status === 'done' ? 'done' : 'rejected', $userId, 'Z Action Plan: ' . $item['title']);
					} catch (ValidationFailed) {
						// rekomendacja mogła zostać usunięta – pomijamy
					}
				}
			}

			$this->syncBlocked($project);
		});

		return $this->find($project, $id);
	}

	public function updateNote(Project $project, int $id, ?string $note, int $userId): array
	{
		$item = $this->find($project, $id);
		$note = self::cleanNote($note);
		$this->items->update((int) $item['id'], ['note' => $note]);
		$this->items->addEvent((int) $item['id'], $project->id, 'note', $userId, null, null, $note);

		return $this->find($project, $id);
	}

	public function find(Project $project, int $id): array
	{
		$item = $this->items->find($project->id, $id);

		if ($item === null) {
			throw new ValidationFailed(['action' => 'Nie znaleziono działania w tym projekcie.']);
		}

		return $item;
	}

	private function neverChanged(int $itemId): bool
	{
		return $this->db->scalar('SELECT 1 FROM ' . $this->db->table('action_item_events') . " WHERE action_item_id = ? AND event = 'status' LIMIT 1", [$itemId]) === null;
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
