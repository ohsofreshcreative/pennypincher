<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Support\Paginator;

/**
 * Plany kampanii (kampanie → grupy reklam → słowa kluczowe, wykluczenia, reklamy). Każde zapytanie jest zawężone
 * do projektu; drzewo planu wczytywane jest kilkoma zapytaniami po plan_id (bez N+1).
 */
class CampaignPlanRepository
{
	public function __construct(private Db $db)
	{
	}

	/**
	 * @return array<int, string> plany, do których można dodawać frazy (szkic / do przeglądu)
	 */
	public function openOptions(int $projectId): array
	{
		$rows = $this->db->select('SELECT id, name FROM ' . $this->db->table('campaign_plans') . " WHERE project_id = ? AND status IN ('draft', 'review') ORDER BY updated_at DESC LIMIT 50", [$projectId]);

		return array_column($rows, 'name', 'id');
	}

	public function paginate(int $projectId, ?string $status, int $page, int $perPage = 20): Paginator
	{
		$where = 'p.project_id = ?' . ($status ? ' AND p.status = ?' : " AND p.status <> 'archived'");
		$params = $status ? [$projectId, $status] : [$projectId];
		$paginator = new Paginator((int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('campaign_plans') . " p WHERE {$where}", $params), $page, $perPage);
		$rows = $this->db->select(
			sprintf(
				'SELECT p.*, u.display_name AS author_name,
					(SELECT COUNT(*) FROM %2$s c WHERE c.plan_id = p.id) AS campaign_count,
					(SELECT COUNT(*) FROM %3$s k WHERE k.plan_id = p.id) AS keyword_count,
					(SELECT COALESCE(SUM(c2.budget_micros), 0) FROM %2$s c2 WHERE c2.plan_id = p.id) AS allocated_micros
				FROM %1$s p LEFT JOIN %4$s u ON u.ID = p.created_by WHERE %5$s ORDER BY p.updated_at DESC, p.id DESC LIMIT %6$d OFFSET %7$d',
				$this->db->table('campaign_plans'),
				$this->db->table('plan_campaigns'),
				$this->db->table('plan_keywords'),
				$this->db->wpdb()->users,
				$where,
				$paginator->perPage,
				$paginator->offset()
			),
			$params
		);

		return $paginator->withItems(array_map([self::class, 'decodePlan'], $rows));
	}

	public function find(int $projectId, int $planId): ?array
	{
		$row = $this->db->first(
			sprintf('SELECT p.*, u.display_name AS author_name FROM %s p LEFT JOIN %s u ON u.ID = p.created_by WHERE p.id = ? AND p.project_id = ?', $this->db->table('campaign_plans'), $this->db->wpdb()->users),
			[$planId, $projectId]
		);

		return $row ? self::decodePlan($row) : null;
	}

	/**
	 * Pełne drzewo planu.
	 *
	 * @return array{campaigns: array<int, array>, negatives: array<int, array>, keywords: array<int, array>}
	 */
	public function tree(int $projectId, int $planId): array
	{
		$campaigns = [];

		foreach ($this->rows('plan_campaigns', $projectId, $planId, 'position, id') as $row) {
			$row['groups'] = [];
			$campaigns[(int) $row['id']] = $row;
		}

		$groups = [];

		foreach ($this->rows('plan_ad_groups', $projectId, $planId, 'position, id') as $row) {
			$row['keywords'] = [];
			$row['ads'] = [];
			$groups[(int) $row['id']] = $row;
		}

		$keywords = [];

		foreach ($this->rows('plan_keywords', $projectId, $planId, 'score DESC, id') as $row) {
			$groupId = (int) $row['plan_ad_group_id'];

			if (isset($groups[$groupId])) {
				$row['plan_campaign_id'] = (int) $groups[$groupId]['plan_campaign_id'];
				$groups[$groupId]['keywords'][] = $row;
				$keywords[] = $row;
			}
		}

		foreach ($this->rows('plan_ads', $projectId, $planId, 'id') as $row) {
			$row['headlines'] = json_decode((string) $row['headlines'], true) ?: [];
			$row['descriptions'] = json_decode((string) $row['descriptions'], true) ?: [];

			if (isset($groups[(int) $row['plan_ad_group_id']])) {
				$groups[(int) $row['plan_ad_group_id']]['ads'][] = $row;
			}
		}

		foreach ($groups as $id => $group) {
			if (isset($campaigns[(int) $group['plan_campaign_id']])) {
				$campaigns[(int) $group['plan_campaign_id']]['groups'][$id] = $group;
			}
		}

		return ['campaigns' => $campaigns, 'negatives' => $this->rows('plan_negatives', $projectId, $planId, 'level, id'), 'keywords' => $keywords];
	}

	public function createPlan(array $values): int
	{
		$now = $this->db->now();

		return $this->db->insert('campaign_plans', $values + ['created_at' => $now, 'updated_at' => $now]);
	}

	public function updatePlan(int $planId, array $values): void
	{
		if (isset($values['scenario_config']) && is_array($values['scenario_config'])) {
			$values['scenario_config'] = wp_json_encode($values['scenario_config']);
		}

		$this->db->update('campaign_plans', $values + ['updated_at' => $this->db->now()], ['id' => $planId]);
	}

	public function touch(int $planId): void
	{
		$this->db->update('campaign_plans', ['updated_at' => $this->db->now()], ['id' => $planId]);
	}

	public function insert(string $table, array $values): int
	{
		$now = $this->db->now();
		$values += ['created_at' => $now];

		if (in_array($table, ['plan_campaigns', 'plan_ad_groups', 'plan_ads'], true)) {
			$values += ['updated_at' => $now];
		}

		return $this->db->insert($table, $values);
	}

	public function update(string $table, int $id, int $planId, array $values): int
	{
		if (in_array($table, ['plan_campaigns', 'plan_ad_groups', 'plan_ads'], true)) {
			$values += ['updated_at' => $this->db->now()];
		}

		return $this->db->update($table, $values, ['id' => $id, 'plan_id' => $planId]);
	}

	public function delete(string $table, int $id, int $planId): int
	{
		return $this->db->statement('DELETE FROM ' . $this->db->table($table) . ' WHERE id = ? AND plan_id = ?', [$id, $planId]);
	}

	/**
	 * Element planu (kampania, grupa, słowo, wykluczenie, reklama) – tylko z tego planu i projektu.
	 */
	public function item(string $table, int $projectId, int $planId, int $id): ?array
	{
		return $this->db->first('SELECT * FROM ' . $this->db->table($table) . ' WHERE id = ? AND plan_id = ? AND project_id = ?', [$id, $planId, $projectId]);
	}

	public function nextPosition(string $table, string $column, int $parentId): int
	{
		return (int) $this->db->scalar('SELECT COALESCE(MAX(position), -1) + 1 FROM ' . $this->db->table($table) . " WHERE {$column} = ?", [$parentId]);
	}

	public function addEvent(int $planId, int $projectId, ?int $userId, string $event, ?string $note = null): void
	{
		$this->db->insert('plan_events', ['plan_id' => $planId, 'project_id' => $projectId, 'user_id' => $userId, 'event' => $event, 'note' => $note, 'created_at' => $this->db->now()]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function events(int $projectId, int $planId): array
	{
		return $this->db->select(
			sprintf('SELECT e.*, u.display_name AS user_name FROM %s e LEFT JOIN %s u ON u.ID = e.user_id WHERE e.plan_id = ? AND e.project_id = ? ORDER BY e.id DESC LIMIT 30', $this->db->table('plan_events'), $this->db->wpdb()->users),
			[$planId, $projectId]
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $table, int $projectId, int $planId, string $order): array
	{
		return $this->db->select('SELECT * FROM ' . $this->db->table($table) . " WHERE plan_id = ? AND project_id = ? ORDER BY {$order}", [$planId, $projectId]);
	}

	private static function decodePlan(array $row): array
	{
		$row['scenario_config'] = $row['scenario_config'] ? (json_decode((string) $row['scenario_config'], true) ?: []) : [];
		$row['monthly_budget_micros'] = $row['monthly_budget_micros'] === null ? null : (int) $row['monthly_budget_micros'];

		return $row;
	}
}
