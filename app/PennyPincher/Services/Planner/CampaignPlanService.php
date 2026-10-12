<?php

namespace App\PennyPincher\Services\Planner;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Domain\Import\NumberParser;
use App\PennyPincher\Domain\Keywords\KeywordStatus;
use App\PennyPincher\Domain\Metrics\Money;
use App\PennyPincher\Domain\Planner\AdTemplates;
use App\PennyPincher\Domain\Planner\AdValidator;
use App\PennyPincher\Domain\Planner\MatchType;
use App\PennyPincher\Domain\Planner\NegativeConflictChecker;
use App\PennyPincher\Domain\Planner\PlanStatus;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\CampaignPlanRepository;
use App\PennyPincher\Repositories\KeywordDecisionRepository;
use App\PennyPincher\Services\Analysis\ConversionBreakdown;
use App\PennyPincher\Services\Analysis\KeywordOpportunityService;

/**
 * Campaign Planner: przygotowanie nowych kampanii (kampanie → grupy reklam → słowa kluczowe, wykluczenia, reklamy).
 * Plan nigdy nie jest wysyłany do Google Ads – to dokument roboczy do ręcznego wdrożenia lub eksportu.
 */
class CampaignPlanService
{
	public const MAX_KEYWORDS_PER_GROUP = 100;

	public function __construct(
		private Db $db,
		private CampaignPlanRepository $plans,
		private AnalysisRepository $analyses,
		private KeywordDecisionRepository $decisions,
		private ConversionBreakdown $breakdown,
	) {
	}

	// --- Plan ---------------------------------------------------------------------------------

	public function create(Project $project, array $input, int $userId): int
	{
		$values = $this->validatePlan($project, $input);
		$values['monthly_budget_micros'] = $this->money($input['monthly_budget'] ?? null, 'monthly_budget') ?? $project->monthlyBudgetMicros;

		return $this->db->transaction(function () use ($project, $values, $userId) {
			$id = $this->plans->createPlan($values + [
				'project_id' => $project->id,
				'currency' => $project->currency,
				'status' => PlanStatus::DRAFT,
				'scenario' => 'balanced',
				'created_by' => $userId,
			]);
			$this->plans->addEvent($id, $project->id, $userId, 'created');

			return $id;
		});
	}

	public function update(Project $project, int $planId, array $input, int $userId): void
	{
		$plan = $this->editable($project, $planId);
		$this->plans->updatePlan((int) $plan['id'], $this->validatePlan($project, $input));
		$this->plans->addEvent((int) $plan['id'], $project->id, $userId, 'updated');
	}

	public function setStatus(Project $project, int $planId, string $status, int $userId): void
	{
		$plan = $this->find($project, $planId);

		if (! array_key_exists($status, PlanStatus::LABELS)) {
			throw new ValidationFailed(['status' => 'Nieprawidłowy status planu.']);
		}

		$values = ['status' => $status];

		if ($status === PlanStatus::APPROVED) {
			$values += ['approved_by' => $userId, 'approved_at' => $this->db->now()];
		}

		$this->plans->updatePlan((int) $plan['id'], $values);
		$this->plans->addEvent((int) $plan['id'], $project->id, $userId, 'status', PlanStatus::LABELS[$plan['status']] . ' → ' . PlanStatus::LABELS[$status]);
	}

	/**
	 * Kopia planu (szkic) z kampaniami, grupami, słowami, wykluczeniami i reklamami – w jednej transakcji.
	 */
	public function copy(Project $project, int $planId, int $userId): int
	{
		$plan = $this->find($project, $planId);
		$tree = $this->plans->tree($project->id, (int) $plan['id']);

		return $this->db->transaction(function () use ($project, $plan, $tree, $userId) {
			$newId = $this->plans->createPlan([
				'project_id' => $project->id,
				'name' => mb_substr('Kopia: ' . $plan['name'], 0, 190),
				'goal' => $plan['goal'],
				'service' => $plan['service'],
				'audience' => $plan['audience'],
				'location' => $plan['location'],
				'language' => $plan['language'],
				'currency' => $plan['currency'],
				'monthly_budget_micros' => $plan['monthly_budget_micros'],
				'status' => PlanStatus::DRAFT,
				'scenario' => $plan['scenario'],
				'scenario_config' => $plan['scenario_config'] ? wp_json_encode($plan['scenario_config']) : null,
				'notes' => $plan['notes'],
				'copied_from_id' => (int) $plan['id'],
				'created_by' => $userId,
			]);
			$campaignMap = [];
			$groupMap = [];

			foreach ($tree['campaigns'] as $campaign) {
				$campaignMap[(int) $campaign['id']] = $this->plans->insert('plan_campaigns', ['plan_id' => $newId, 'project_id' => $project->id] + array_intersect_key($campaign, array_flip(['name', 'kind', 'budget_micros', 'final_url', 'notes', 'position'])));

				foreach ($campaign['groups'] as $group) {
					$groupMap[(int) $group['id']] = $this->plans->insert('plan_ad_groups', ['plan_id' => $newId, 'project_id' => $project->id, 'plan_campaign_id' => $campaignMap[(int) $campaign['id']]] + array_intersect_key($group, array_flip(['name', 'cluster_key', 'final_url', 'position'])));

					foreach ($group['keywords'] as $keyword) {
						$this->plans->insert('plan_keywords', ['plan_id' => $newId, 'project_id' => $project->id, 'plan_ad_group_id' => $groupMap[(int) $group['id']]] + array_intersect_key($keyword, array_flip(['phrase', 'normalized', 'match_type', 'intent', 'score', 'confidence', 'cpc_micros', 'cpc_currency', 'cpc_project_micros', 'search_volume', 'source', 'term_clicks', 'term_cost_micros', 'term_conversions'])));
					}

					foreach ($group['ads'] as $ad) {
						$this->plans->insert('plan_ads', ['plan_id' => $newId, 'project_id' => $project->id, 'plan_ad_group_id' => $groupMap[(int) $group['id']], 'headlines' => wp_json_encode($ad['headlines']), 'descriptions' => wp_json_encode($ad['descriptions'])] + array_intersect_key($ad, array_flip(['path1', 'path2', 'final_url', 'cta', 'notes'])));
					}
				}
			}

			foreach ($tree['negatives'] as $negative) {
				$campaignId = $negative['plan_campaign_id'] !== null ? ($campaignMap[(int) $negative['plan_campaign_id']] ?? null) : null;
				$groupId = $negative['plan_ad_group_id'] !== null ? ($groupMap[(int) $negative['plan_ad_group_id']] ?? null) : null;
				$this->plans->insert('plan_negatives', [
					'plan_id' => $newId, 'project_id' => $project->id, 'level' => $negative['level'], 'plan_campaign_id' => $campaignId, 'plan_ad_group_id' => $groupId,
					'scope_key' => self::scopeKey($negative['level'], $campaignId, $groupId), 'phrase' => $negative['phrase'], 'normalized' => $negative['normalized'],
					'match_type' => $negative['match_type'], 'source' => $negative['source'],
				]);
			}

			$this->plans->addEvent($newId, $project->id, $userId, 'copied', 'Kopia planu #' . $plan['id']);

			return $newId;
		});
	}

	// --- Kampanie i grupy ---------------------------------------------------------------------

	public function addCampaign(Project $project, int $planId, string $name, string $kind = 'search'): int
	{
		$plan = $this->editable($project, $planId);
		$name = $this->name($name, 'campaign');

		$id = $this->plans->insert('plan_campaigns', [
			'plan_id' => (int) $plan['id'], 'project_id' => $project->id, 'name' => $name, 'kind' => in_array($kind, ['search', 'brand'], true) ? $kind : 'search',
			'position' => $this->plans->nextPosition('plan_campaigns', 'plan_id', (int) $plan['id']),
		]);
		$this->log($project, $plan, 'campaign_added', 'Kampania „' . $name . '”.');

		return $id;
	}

	public function updateCampaign(Project $project, int $planId, int $campaignId, array $input): void
	{
		$plan = $this->editable($project, $planId);
		$this->item('plan_campaigns', $project, $plan, $campaignId);
		$values = ['name' => $this->name((string) ($input['name'] ?? ''), 'name')];

		if (array_key_exists('final_url', $input)) {
			$values['final_url'] = $this->url($input['final_url'], 'final_url');
		}

		if (array_key_exists('kind', $input)) {
			$values['kind'] = in_array($input['kind'], ['search', 'brand'], true) ? $input['kind'] : 'search';
		}

		if (array_key_exists('notes', $input)) {
			$values['notes'] = $this->note($input['notes']);
		}

		$this->plans->update('plan_campaigns', $campaignId, (int) $plan['id'], $values);
		$this->plans->touch((int) $plan['id']);
	}

	public function deleteCampaign(Project $project, int $planId, int $campaignId): void
	{
		$plan = $this->editable($project, $planId);
		$campaign = $this->item('plan_campaigns', $project, $plan, $campaignId);
		$this->log($project, $plan, 'campaign_deleted', 'Kampania „' . $campaign['name'] . '”.');
		$phrases = array_column($this->db->select('SELECT normalized FROM ' . $this->db->table('plan_keywords') . ' k INNER JOIN ' . $this->db->table('plan_ad_groups') . ' g ON g.id = k.plan_ad_group_id WHERE g.plan_campaign_id = ?', [$campaignId]), 'normalized');

		$this->db->transaction(function () use ($plan, $campaignId, $project, $phrases) {
			$this->db->statement('DELETE FROM ' . $this->db->table('plan_negatives') . ' WHERE plan_id = ? AND plan_campaign_id = ?', [(int) $plan['id'], $campaignId]);
			$this->plans->delete('plan_campaigns', $campaignId, (int) $plan['id']);
			$this->releaseDecisions($project, $phrases);
		});
	}

	public function addGroup(Project $project, int $planId, int $campaignId, string $name, ?string $clusterKey = null): int
	{
		$plan = $this->editable($project, $planId);
		$this->item('plan_campaigns', $project, $plan, $campaignId);
		$name = $this->name($name, 'group');
		$id = $this->plans->insert('plan_ad_groups', [
			'plan_campaign_id' => $campaignId, 'plan_id' => (int) $plan['id'], 'project_id' => $project->id, 'name' => $name,
			'cluster_key' => $clusterKey, 'position' => $this->plans->nextPosition('plan_ad_groups', 'plan_campaign_id', $campaignId),
		]);
		$this->log($project, $plan, 'group_added', 'Grupa reklam „' . $name . '”.');

		return $id;
	}

	public function updateGroup(Project $project, int $planId, int $groupId, array $input): void
	{
		$plan = $this->editable($project, $planId);
		$group = $this->item('plan_ad_groups', $project, $plan, $groupId);
		$values = ['name' => $this->name((string) ($input['name'] ?? ''), 'name')];

		if (array_key_exists('final_url', $input)) {
			$values['final_url'] = $this->url($input['final_url'], 'final_url');
		}

		// Przeniesienie grupy do innej kampanii tego samego planu.
		if (! empty($input['plan_campaign_id']) && (int) $input['plan_campaign_id'] !== (int) $group['plan_campaign_id']) {
			$this->item('plan_campaigns', $project, $plan, (int) $input['plan_campaign_id']);
			$values['plan_campaign_id'] = (int) $input['plan_campaign_id'];
			$this->db->statement('DELETE FROM ' . $this->db->table('plan_negatives') . ' WHERE plan_id = ? AND plan_ad_group_id = ?', [(int) $plan['id'], $groupId]);
		}

		$this->plans->update('plan_ad_groups', $groupId, (int) $plan['id'], $values);
		$this->plans->touch((int) $plan['id']);
	}

	public function deleteGroup(Project $project, int $planId, int $groupId): void
	{
		$plan = $this->editable($project, $planId);
		$group = $this->item('plan_ad_groups', $project, $plan, $groupId);
		$phrases = array_column($this->db->select('SELECT normalized FROM ' . $this->db->table('plan_keywords') . ' WHERE plan_ad_group_id = ?', [$groupId]), 'normalized');
		$this->log($project, $plan, 'group_deleted', 'Grupa reklam „' . $group['name'] . '”.');

		$this->db->transaction(function () use ($plan, $groupId, $project, $phrases) {
			$this->db->statement('DELETE FROM ' . $this->db->table('plan_negatives') . ' WHERE plan_id = ? AND plan_ad_group_id = ?', [(int) $plan['id'], $groupId]);
			$this->plans->delete('plan_ad_groups', $groupId, (int) $plan['id']);
			$this->releaseDecisions($project, $phrases);
		});
	}

	// --- Słowa kluczowe -------------------------------------------------------------------------

	/**
	 * Dodanie fraz do grupy reklam. Dane (score, CPC, wolumen, intencja, historia) pochodzą z najnowszej analizy
	 * słów kluczowych projektu; fraza spoza analizy trafia jako „ręczna” bez wymyślonych metryk.
	 *
	 * @param array<int, string> $phrases
	 * @return array{added: int, skipped: int}
	 */
	public function addKeywords(Project $project, int $planId, int $groupId, array $phrases, ?string $matchType = null, string $source = 'manual'): array
	{
		$plan = $this->editable($project, $planId);
		$group = $this->item('plan_ad_groups', $project, $plan, $groupId);

		if ($matchType !== null && ! MatchType::valid($matchType)) {
			throw new ValidationFailed(['match_type' => 'Nieprawidłowy typ dopasowania.']);
		}

		$clean = [];

		foreach ($phrases as $phrase) {
			$phrase = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $phrase)));
			$phrase = trim($phrase, '[]"+ ');
			$normalized = mb_substr(Text::normalize($phrase), 0, 255);

			if ($normalized === '') {
				continue;
			}

			if (mb_strlen($phrase) > 80 || count(explode(' ', $normalized)) > 10) {
				throw new ValidationFailed(['phrases' => 'Słowo kluczowe może mieć maks. 80 znaków i 10 słów (limit Google Ads): „' . mb_substr($phrase, 0, 40) . '…”.']);
			}

			$clean[$normalized] = mb_strtolower($phrase);
		}

		if ($clean === []) {
			throw new ValidationFailed(['phrases' => 'Podaj co najmniej jedną frazę.']);
		}

		$existing = (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('plan_keywords') . ' WHERE plan_ad_group_id = ?', [$groupId]);

		if ($existing + count($clean) > self::MAX_KEYWORDS_PER_GROUP) {
			throw new ValidationFailed(['phrases' => sprintf('Grupa reklam może mieć w planie maks. %d słów (jest %d). Utwórz kolejną grupę.', self::MAX_KEYWORDS_PER_GROUP, $existing)]);
		}

		$data = $this->opportunityData($project, array_keys($clean));
		$added = 0;
		$skipped = 0;

		$this->db->transaction(function () use ($clean, $data, $matchType, $source, $group, $plan, $project, &$added, &$skipped) {
			foreach ($clean as $normalized => $phrase) {
				$row = $data[$normalized] ?? null;
				$term = $row['evidence']['term'] ?? null;
				$type = $matchType ?? MatchType::suggest($phrase, $term['conversions'] ?? null);

				$exists = $this->db->scalar('SELECT 1 FROM ' . $this->db->table('plan_keywords') . ' WHERE plan_ad_group_id = ? AND normalized = ? AND match_type = ?', [(int) $group['id'], $normalized, $type]);

				if ($exists !== null) {
					$skipped++;
					continue;
				}

				$this->plans->insert('plan_keywords', [
					'plan_ad_group_id' => (int) $group['id'],
					'plan_id' => (int) $plan['id'],
					'project_id' => $project->id,
					'phrase' => $row['phrase'] ?? $phrase,
					'normalized' => $normalized,
					'match_type' => $type,
					'intent' => $row['intent'] ?? Text::primaryIntent(Text::intents($phrase, $project->context()->excludedAudiences())),
					'score' => $row['score'] ?? null,
					'confidence' => $row['confidence'] ?? null,
					'cpc_micros' => $row['cpc_micros'] ?? null,
					'cpc_currency' => $row['cpc_currency'] ?? null,
					'cpc_project_micros' => $row['cpc_project_micros'] ?? null,
					'search_volume' => $row['search_volume'] ?? null,
					'source' => $row !== null ? (string) $row['sources'] : $source,
					'term_clicks' => $term['clicks'] ?? null,
					'term_cost_micros' => $term['cost_micros'] ?? null,
					'term_conversions' => $term['conversions'] ?? null,
				]);
				$added++;

				[$id, $from] = $this->decisions->setStatus($project->id, $normalized, $phrase, KeywordStatus::IN_PLAN, null, ['plan_id' => (int) $plan['id']]);

				if ($from !== KeywordStatus::IN_PLAN) {
					$this->decisions->addEvent($id, $project->id, 'in_plan', null, $from, KeywordStatus::IN_PLAN, 'Dodana do planu „' . $plan['name'] . '”.');
				}
			}

			$this->plans->touch((int) $plan['id']);
		});

		if ($added > 0 && $source === 'manual') {
			$this->log($project, $plan, 'keywords_added', sprintf('Dodano %d %s do grupy „%s”.', $added, $added === 1 ? 'słowo' : 'słów', $group['name']));
		}

		return ['added' => $added, 'skipped' => $skipped];
	}

	/**
	 * Dodanie fraz lub całych klastrów z Keyword Opportunities: kampania wg usługi klastra, grupa reklam = klaster.
	 * Bez planu ($planId = null) tworzy nowy szkic.
	 *
	 * @param array<int, string> $phrases
	 * @param array<int, string> $clusterKeys
	 * @return array{plan_id: int, added: int, skipped: int}
	 */
	public function addFromOpportunities(Project $project, ?int $planId, int $runId, array $phrases, array $clusterKeys, int $userId): array
	{
		$run = $this->analyses->findRun($project->id, $runId);

		if ($run === null || $run['type'] !== KeywordOpportunityService::TYPE) {
			throw new ValidationFailed(['run' => 'Nie znaleziono analizy słów kluczowych.']);
		}

		$clusters = [];

		foreach ($this->analyses->clusters($runId, $project->id) as $cluster) {
			$clusters[$cluster['cluster_key']] = $cluster;
		}

		$normalized = array_map(fn ($p) => mb_substr(Text::normalize((string) $p), 0, 255), $phrases);

		foreach ($clusterKeys as $key) {
			if (! isset($clusters[$key])) {
				throw new ValidationFailed(['cluster' => 'Nie znaleziono klastra w tej analizie.']);
			}

			array_push($normalized, ...$clusters[$key]['members']);
		}

		$rows = $this->analyses->opportunitiesByNormalized($runId, $project->id, array_values(array_unique(array_filter($normalized))));

		if ($rows === []) {
			throw new ValidationFailed(['phrases' => 'Zaznacz frazy lub klastry z bieżącej analizy.']);
		}

		$planId ??= $this->create($project, ['name' => 'Plan z Keyword Opportunities ' . wp_date('d.m.Y'), 'language' => $project->context()->language()], $userId);
		$plan = $this->editable($project, $planId);
		$tree = $this->plans->tree($project->id, (int) $plan['id']);
		$byCluster = [];

		foreach ($rows as $row) {
			$byCluster[$row['cluster_key'] ?? 'none'][] = $row;
		}

		$added = 0;
		$skipped = 0;

		foreach ($byCluster as $key => $members) {
			$cluster = $clusters[$key] ?? null;
			$campaignName = $cluster['service'] ?? null ?: 'Nowe frazy';
			$campaignId = null;

			foreach ($tree['campaigns'] as $campaign) {
				if (mb_strtolower($campaign['name']) === mb_strtolower($campaignName)) {
					$campaignId = (int) $campaign['id'];
				}
			}

			$campaignId ??= $this->addCampaign($project, (int) $plan['id'], $campaignName);
			$groupId = null;

			foreach ($tree['campaigns'][$campaignId]['groups'] ?? [] as $group) {
				if ($cluster !== null && $group['cluster_key'] === $key) {
					$groupId = (int) $group['id'];
				}
			}

			$groupId ??= $this->addGroup($project, (int) $plan['id'], $campaignId, $cluster['name'] ?? 'Pojedyncze frazy', $cluster ? $key : null);
			$tree = $this->plans->tree($project->id, (int) $plan['id']);
			$result = $this->addKeywords($project, (int) $plan['id'], $groupId, array_column($members, 'phrase'), null, 'keyword_opportunities');
			$added += $result['added'];
			$skipped += $result['skipped'];
		}

		$this->plans->addEvent((int) $plan['id'], $project->id, $userId, 'keywords_added', sprintf('Dodano %d fraz z Keyword Opportunities.', $added));

		return ['plan_id' => (int) $plan['id'], 'added' => $added, 'skipped' => $skipped];
	}

	public function removeKeyword(Project $project, int $planId, int $keywordId): void
	{
		$plan = $this->editable($project, $planId);
		$keyword = $this->item('plan_keywords', $project, $plan, $keywordId);

		$this->db->transaction(function () use ($plan, $keyword, $project) {
			$this->plans->delete('plan_keywords', (int) $keyword['id'], (int) $plan['id']);
			$this->releaseDecisions($project, [$keyword['normalized']]);
			$this->log($project, $plan, 'keyword_removed', '„' . $keyword['phrase'] . '”.');
			$this->plans->touch((int) $plan['id']);
		});
	}

	public function moveKeyword(Project $project, int $planId, int $keywordId, int $targetGroupId): void
	{
		$plan = $this->editable($project, $planId);
		$keyword = $this->item('plan_keywords', $project, $plan, $keywordId);
		$this->item('plan_ad_groups', $project, $plan, $targetGroupId);

		if ($this->db->scalar('SELECT 1 FROM ' . $this->db->table('plan_keywords') . ' WHERE plan_ad_group_id = ? AND normalized = ? AND match_type = ? AND id <> ?', [$targetGroupId, $keyword['normalized'], $keyword['match_type'], $keywordId]) !== null) {
			throw new ValidationFailed(['move' => 'W docelowej grupie jest już to słowo z tym samym dopasowaniem.']);
		}

		$this->plans->update('plan_keywords', $keywordId, (int) $plan['id'], ['plan_ad_group_id' => $targetGroupId]);
		$this->log($project, $plan, 'keyword_moved', '„' . $keyword['phrase'] . '”.');
	}

	public function setMatchType(Project $project, int $planId, int $keywordId, string $matchType): void
	{
		$plan = $this->editable($project, $planId);
		$keyword = $this->item('plan_keywords', $project, $plan, $keywordId);

		if (! MatchType::valid($matchType)) {
			throw new ValidationFailed(['match_type' => 'Nieprawidłowy typ dopasowania.']);
		}

		if ($this->db->scalar('SELECT 1 FROM ' . $this->db->table('plan_keywords') . ' WHERE plan_ad_group_id = ? AND normalized = ? AND match_type = ? AND id <> ?', [(int) $keyword['plan_ad_group_id'], $keyword['normalized'], $matchType, $keywordId]) !== null) {
			throw new ValidationFailed(['match_type' => 'To słowo z tym dopasowaniem już jest w grupie.']);
		}

		$this->plans->update('plan_keywords', $keywordId, (int) $plan['id'], ['match_type' => $matchType]);
		$this->log($project, $plan, 'match_type', sprintf('„%s”: %s → %s.', $keyword['phrase'], MatchType::LABELS[$keyword['match_type']] ?? $keyword['match_type'], MatchType::LABELS[$matchType]));
	}

	// --- Wykluczenia ----------------------------------------------------------------------------

	/**
	 * @param array<int, string> $phrases
	 * @return array{added: int, conflicts: array<int, array>}
	 */
	public function addNegatives(Project $project, int $planId, string $level, ?int $scopeId, array $phrases, string $matchType, string $source = 'manual'): array
	{
		$plan = $this->editable($project, $planId);

		if (! array_key_exists($level, PlanStatus::LEVELS) || ! MatchType::valid($matchType)) {
			throw new ValidationFailed(['level' => 'Nieprawidłowy poziom lub typ dopasowania wykluczenia.']);
		}

		$campaignId = null;
		$groupId = null;

		if ($level === 'campaign') {
			$campaignId = (int) $this->item('plan_campaigns', $project, $plan, (int) $scopeId)['id'];
		} elseif ($level === 'ad_group') {
			$group = $this->item('plan_ad_groups', $project, $plan, (int) $scopeId);
			$groupId = (int) $group['id'];
			$campaignId = (int) $group['plan_campaign_id'];
		}

		$added = 0;
		$new = [];

		foreach ($phrases as $phrase) {
			$phrase = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', trim(wp_strip_all_tags((string) $phrase), '[]"+- '))));
			$normalized = mb_substr(Text::normalize($phrase), 0, 255);

			if ($normalized === '' || mb_strlen($phrase) > 80) {
				continue;
			}

			$scope = self::scopeKey($level, $campaignId, $groupId);

			if ($this->db->scalar('SELECT 1 FROM ' . $this->db->table('plan_negatives') . ' WHERE plan_id = ? AND scope_key = ? AND normalized = ? AND match_type = ?', [(int) $plan['id'], $scope, $normalized, $matchType]) !== null) {
				continue;
			}

			$this->plans->insert('plan_negatives', [
				'plan_id' => (int) $plan['id'], 'project_id' => $project->id, 'level' => $level, 'plan_campaign_id' => $level === 'plan' ? null : $campaignId,
				'plan_ad_group_id' => $groupId, 'scope_key' => $scope, 'phrase' => $phrase, 'normalized' => $normalized, 'match_type' => $matchType,
				'source' => in_array($source, ['manual', 'negative_suggestions'], true) ? $source : 'manual',
			]);
			$new[] = ['phrase' => $phrase, 'match_type' => $matchType, 'level' => $level, 'plan_campaign_id' => $campaignId, 'plan_ad_group_id' => $groupId];
			$added++;
		}

		if ($added > 0) {
			$this->log($project, $plan, 'negatives_added', sprintf('Dodano %d %s (%s).', $added, $added === 1 ? 'wykluczenie' : 'wykluczeń', PlanStatus::LEVELS[$level]));
		}

		$tree = $this->plans->tree($project->id, (int) $plan['id']);

		return ['added' => $added, 'conflicts' => NegativeConflictChecker::conflicts($new, $tree['keywords'])];
	}

	public function removeNegative(Project $project, int $planId, int $negativeId): void
	{
		$plan = $this->editable($project, $planId);
		$negative = $this->item('plan_negatives', $project, $plan, $negativeId);
		$this->plans->delete('plan_negatives', $negativeId, (int) $plan['id']);
		$this->log($project, $plan, 'negative_removed', '„' . $negative['phrase'] . '”.');
	}

	/**
	 * @return array<int, array>
	 */
	public function conflicts(array $tree): array
	{
		$negatives = array_map(fn ($n) => $n + ['plan_campaign_id' => null, 'plan_ad_group_id' => null], $tree['negatives']);

		return NegativeConflictChecker::conflicts($negatives, $tree['keywords']);
	}

	// --- Reklamy --------------------------------------------------------------------------------

	/**
	 * @return array{warnings: array<int, string>}
	 */
	public function saveAd(Project $project, int $planId, int $groupId, array $input): array
	{
		$plan = $this->editable($project, $planId);
		$group = $this->item('plan_ad_groups', $project, $plan, $groupId);
		$headlines = array_slice(array_map(fn ($h) => trim(wp_strip_all_tags((string) $h)), (array) ($input['headlines'] ?? [])), 0, 20);
		$descriptions = array_slice(array_map(fn ($d) => trim(wp_strip_all_tags((string) $d)), (array) ($input['descriptions'] ?? [])), 0, 10);
		$path1 = trim(wp_strip_all_tags((string) ($input['path1'] ?? '')));
		$path2 = trim(wp_strip_all_tags((string) ($input['path2'] ?? '')));
		$finalUrl = trim((string) ($input['final_url'] ?? ''));
		$result = AdValidator::validate($headlines, $descriptions, $path1, $path2, $finalUrl);

		if ($result['errors'] !== []) {
			throw new ValidationFailed($result['errors']);
		}

		$values = [
			'headlines' => wp_json_encode(array_values(array_filter($headlines, fn ($h) => $h !== ''))),
			'descriptions' => wp_json_encode(array_values(array_filter($descriptions, fn ($d) => $d !== ''))),
			'path1' => $path1 === '' ? null : $path1,
			'path2' => $path2 === '' ? null : $path2,
			'final_url' => $finalUrl === '' ? null : esc_url_raw($finalUrl, ['http', 'https']),
			'cta' => mb_substr(trim(wp_strip_all_tags((string) ($input['cta'] ?? ''))), 0, 60) ?: null,
			'notes' => $this->note($input['notes'] ?? null),
		];

		$existing = $this->db->first('SELECT id FROM ' . $this->db->table('plan_ads') . ' WHERE plan_ad_group_id = ? AND plan_id = ? ORDER BY id LIMIT 1', [(int) $group['id'], (int) $plan['id']]);

		if ($existing) {
			$this->plans->update('plan_ads', (int) $existing['id'], (int) $plan['id'], $values);
		} else {
			$this->plans->insert('plan_ads', $values + ['plan_ad_group_id' => (int) $group['id'], 'plan_id' => (int) $plan['id'], 'project_id' => $project->id]);
		}

		$this->log($project, $plan, 'ad_saved', 'Reklama w grupie „' . $group['name'] . '”.');

		return ['warnings' => $result['warnings']];
	}

	/**
	 * Propozycja tekstów z kontekstu biznesowego (do ręcznej edycji) – tylko gdy grupa nie ma jeszcze reklamy.
	 */
	public function suggestAd(Project $project, int $planId, int $groupId): array
	{
		$plan = $this->editable($project, $planId);
		$group = $this->item('plan_ad_groups', $project, $plan, $groupId);

		if ($this->db->scalar('SELECT 1 FROM ' . $this->db->table('plan_ads') . ' WHERE plan_ad_group_id = ?', [$groupId]) !== null) {
			throw new ValidationFailed(['ad' => 'Grupa ma już reklamę – popraw ją ręcznie (propozycja nie nadpisuje Twoich tekstów).']);
		}

		$keywords = array_column($this->db->select('SELECT phrase FROM ' . $this->db->table('plan_keywords') . ' WHERE plan_ad_group_id = ? ORDER BY score DESC LIMIT 6', [$groupId]), 'phrase');
		$suggestion = AdTemplates::suggest($project->context(), (string) $group['name'], $keywords, $plan['location'] ?: null);

		return $this->saveAd($project, (int) $plan['id'], $groupId, $suggestion + ['final_url' => $group['final_url'] ?? $project->landingPageUrl, 'cta' => AdTemplates::CTAS[0]]);
	}

	// --- Pomiar ---------------------------------------------------------------------------------

	/**
	 * Jakość pomiaru konwersji (ostatnie 90 dni): wiarygodny = są konwersje celów biznesowych i główne konwersje
	 * nie są w większości mikrokonwersjami. Używane do ostrzeżeń przy dopasowaniu przybliżonym i prognozach.
	 *
	 * @return array{reliable: bool, reason: string}
	 */
	public function measurement(Project $project): array
	{
		$period = AnalysisPeriod::fromPreset('last_90', $project->timezone());
		$data = $this->breakdown->forPeriod($project->id, $period->range);
		$account = $data['account'];
		$goals = $account['goals'] ?? null;
		$google = (float) ($account['google'] ?? 0);
		$micro = (float) ($account['google_micro'] ?? 0);

		return match (true) {
			$goals === null => ['reliable' => false, 'reason' => 'Brak danych o konwersjach per działanie – nie wiadomo, czy konwersje Google Ads to wartościowe kontakty.'],
			(float) $goals <= 0 => ['reliable' => false, 'reason' => 'W ostatnich 90 dniach nie ma konwersji oznaczonych jako cel biznesowy.'],
			$google > 0 && $micro / $google >= 0.5 => ['reliable' => false, 'reason' => 'Większość konwersji Google Ads to mikrokonwersje (np. wizyty, kliknięcia).'],
			default => ['reliable' => true, 'reason' => 'Konwersje celów biznesowych są mierzone.'],
		};
	}

	// --- Pomocnicze -----------------------------------------------------------------------------

	public function find(Project $project, int $planId): array
	{
		$plan = $this->plans->find($project->id, $planId);

		if ($plan === null) {
			throw new ValidationFailed(['plan' => 'Nie znaleziono planu w tym projekcie.']);
		}

		return $plan;
	}

	public function editable(Project $project, int $planId): array
	{
		$plan = $this->find($project, $planId);

		if (! in_array($plan['status'], PlanStatus::EDITABLE, true)) {
			throw new ValidationFailed(['plan' => 'Plan jest ' . mb_strtolower(PlanStatus::LABELS[$plan['status']]) . ' – aby go edytować, zmień status na „Szkic” albo utwórz kopię.']);
		}

		return $plan;
	}

	private function item(string $table, Project $project, array $plan, int $id): array
	{
		$item = $this->plans->item($table, $project->id, (int) $plan['id'], $id);

		if ($item === null) {
			throw new ValidationFailed(['item' => 'Nie znaleziono elementu w tym planie.']);
		}

		return $item;
	}

	/**
	 * Fraza usunięta z ostatniego planu wraca do statusu „Zaakceptowana”.
	 */
	private function releaseDecisions(Project $project, array $normalized): void
	{
		foreach (array_unique($normalized) as $phrase) {
			if ($this->db->scalar('SELECT 1 FROM ' . $this->db->table('plan_keywords') . ' WHERE project_id = ? AND normalized = ?', [$project->id, $phrase]) !== null) {
				continue;
			}

			$decision = $this->decisions->find($project->id, (string) $phrase);

			if ($decision !== null && $decision['status'] === KeywordStatus::IN_PLAN) {
				$this->decisions->update((int) $decision['id'], ['status' => KeywordStatus::ACCEPTED, 'plan_id' => null]);
				$this->decisions->addEvent((int) $decision['id'], $project->id, 'removed_from_plan', null, KeywordStatus::IN_PLAN, KeywordStatus::ACCEPTED, 'Usunięta z planu kampanii.');
			}
		}
	}

	/**
	 * @param array<int, string> $normalized
	 * @return array<string, array<string, mixed>>
	 */
	private function opportunityData(Project $project, array $normalized): array
	{
		$run = $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE);

		if ($run === null) {
			return [];
		}

		$map = [];

		foreach ($this->analyses->opportunitiesByNormalized((int) $run['id'], $project->id, $normalized) as $row) {
			$map[$row['normalized']] = $row;
		}

		return $map;
	}

	private function validatePlan(Project $project, array $input): array
	{
		$errors = [];
		$name = trim(wp_strip_all_tags((string) ($input['name'] ?? '')));

		if ($name === '' || mb_strlen($name) > 190) {
			$errors['name'] = 'Podaj nazwę planu (maks. 190 znaków).';
		}

		$goal = (string) ($input['goal'] ?? '');

		if ($goal !== '' && ! array_key_exists($goal, BusinessContext::GOALS)) {
			$errors['goal'] = 'Wybierz cel z listy.';
		}

		$language = (string) ($input['language'] ?? $project->context()->language());

		if (! array_key_exists($language, BusinessContext::LANGUAGES)) {
			$errors['language'] = 'Wybierz język z listy.';
		}

		$values = [
			'name' => $name,
			'goal' => $goal === '' ? null : $goal,
			'service' => mb_substr(trim(wp_strip_all_tags((string) ($input['service'] ?? ''))), 0, 255) ?: null,
			'audience' => mb_substr(trim(sanitize_textarea_field((string) ($input['audience'] ?? ''))), 0, 2000) ?: null,
			'location' => mb_substr(trim(wp_strip_all_tags((string) ($input['location'] ?? ''))), 0, 255) ?: null,
			'language' => $language,
			'notes' => $this->note($input['notes'] ?? null),
		];

		if ($errors !== []) {
			throw new ValidationFailed($errors);
		}

		return $values;
	}

	public function money(mixed $value, string $field): ?int
	{
		$value = trim((string) $value);

		if ($value === '') {
			return null;
		}

		try {
			$number = NumberParser::parse($value, NumberParser::COMMA);
			$micros = $number === null ? null : Money::toMicros($number);

			if ($micros === null || $micros < 0 || $micros > 100_000_000 * Money::MICROS) {
				throw new InvalidValue('range');
			}

			return $micros;
		} catch (InvalidValue|\InvalidArgumentException) {
			throw new ValidationFailed([$field => 'Podaj kwotę, np. 3000 albo 3 000,00.']);
		}
	}

	private function name(string $name, string $field): string
	{
		$name = trim(wp_strip_all_tags($name));

		if ($name === '' || mb_strlen($name) > 190) {
			throw new ValidationFailed([$field => 'Podaj nazwę (maks. 190 znaków).']);
		}

		return $name;
	}

	private function url(mixed $url, string $field): ?string
	{
		$url = trim((string) $url);

		if ($url === '') {
			return null;
		}

		if (mb_strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
			throw new ValidationFailed([$field => 'Podaj poprawny adres zaczynający się od https://.']);
		}

		return esc_url_raw($url, ['http', 'https']);
	}

	private function note(mixed $note): ?string
	{
		$note = trim(sanitize_textarea_field((string) $note));

		if (mb_strlen($note) > 5000) {
			throw new ValidationFailed(['notes' => 'Notatka może mieć maks. 5000 znaków.']);
		}

		return $note === '' ? null : $note;
	}

	/**
	 * Wpis w historii planu (autor = zalogowany użytkownik) i aktualizacja daty zmiany.
	 */
	private function log(Project $project, array $plan, string $event, ?string $note = null): void
	{
		$this->plans->addEvent((int) $plan['id'], $project->id, get_current_user_id() ?: null, $event, $note === null ? null : mb_substr($note, 0, 500));
		$this->plans->touch((int) $plan['id']);
	}

	public static function scopeKey(string $level, ?int $campaignId, ?int $groupId): string
	{
		return match ($level) {
			'campaign' => 'c:' . $campaignId,
			'ad_group' => 'g:' . $groupId,
			default => 'plan',
		};
	}
}
