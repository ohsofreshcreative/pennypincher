<?php

namespace App\PennyPincher\Services\Planner;

use App\PennyPincher\Domain\Keywords\Concepts;
use App\PennyPincher\Domain\Planner\BudgetAllocator;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\CampaignPlanRepository;

/**
 * Budżet planu kampanii: propozycja podziału (scenariusz), prognozy z niepewnością i kontrola sum.
 * Kwoty w walucie projektu (mikrojednostki); CPC z DataForSEO wchodzi tylko po przeliczeniu kursem z datą i źródłem.
 */
class BudgetPlannerService
{
	public function __construct(private Db $db, private CampaignPlanRepository $plans, private CampaignPlanService $planner)
	{
	}

	/**
	 * @return array<string, mixed>
	 */
	public function summary(Project $project, array $plan, array $tree, array $measurement): array
	{
		$config = BudgetAllocator::config((string) $plan['scenario'], (array) $plan['scenario_config']);
		$keywordsAll = $tree['keywords'];
		$clicks = array_sum(array_map(fn ($k) => (int) ($k['term_clicks'] ?? 0), $keywordsAll));
		$conversions = array_sum(array_map(fn ($k) => (float) ($k['term_conversions'] ?? 0), $keywordsAll));
		$accountRate = $clicks >= 100 && $conversions > 0 ? $conversions / $clicks : null;
		$priorityConcepts = [];

		foreach ($project->context()->priorityTexts() as $text) {
			array_push($priorityConcepts, ...array_diff(Concepts::of($text), Concepts::MODIFIERS, ['service', 'company']));
		}

		$campaigns = [];

		foreach ($tree['campaigns'] as $campaign) {
			$keywords = array_merge(...array_values(array_map(fn ($g) => $g['keywords'], $campaign['groups'])) ?: [[]]);
			$concepts = Concepts::of((string) $campaign['name']);
			$campaigns[] = [
				'id' => (int) $campaign['id'],
				'name' => (string) $campaign['name'],
				'priority' => $campaign['kind'] === 'brand' || array_intersect($concepts, $priorityConcepts) !== [],
				'profile' => BudgetAllocator::profile($keywords, $accountRate),
				'budget_micros' => $campaign['budget_micros'] === null ? null : (int) $campaign['budget_micros'],
			];
		}

		$budget = $plan['monthly_budget_micros'];
		$proposal = BudgetAllocator::allocate((int) ($budget ?? 0), $campaigns, $config);
		$allocated = array_sum(array_map(fn ($c) => (int) ($c['budget_micros'] ?? 0), $campaigns));
		$rows = [];

		foreach ($campaigns as $campaign) {
			$rows[$campaign['id']] = $campaign + [
				'proposed_micros' => $proposal['allocations'][$campaign['id']] ?? null,
				'forecast' => BudgetAllocator::forecast($campaign['budget_micros'] ?? ($proposal['allocations'][$campaign['id']] ?? null), $campaign['profile'], $measurement['reliable']),
				'forecast_basis' => $campaign['budget_micros'] !== null ? 'set' : 'proposal',
			];
		}

		$warnings = [];

		if ($budget !== null && $allocated > $budget) {
			$warnings[] = sprintf('Suma budżetów kampanii przekracza budżet planu o %s.', \App\PennyPincher\Support\Format::money($allocated - $budget, $project->currency, 0));
		}

		if (! $measurement['reliable']) {
			$warnings[] = 'Pomiar konwersji nie jest wiarygodny (' . rtrim($measurement['reason'], '.') . ') – prognozy konwersji i kosztu pozyskania są niedostępne.';
		}

		return [
			'config' => $config,
			'rows' => $rows,
			'proposal' => $proposal,
			'budget' => $budget,
			'allocated' => $allocated,
			'unallocated' => $budget !== null ? $budget - $allocated : null,
			'over' => $budget !== null && $allocated > $budget,
			'daily' => $budget !== null ? (int) round($budget / BudgetAllocator::DAYS_PER_MONTH) : null,
			'warnings' => $warnings,
		];
	}

	/**
	 * Budżet planu, scenariusz i jego ustawienia (udział sprawdzonych kampanii, wagi).
	 */
	public function updateBudget(Project $project, int $planId, array $input, int $userId): void
	{
		$plan = $this->planner->editable($project, $planId);
		$scenario = (string) ($input['scenario'] ?? $plan['scenario']);

		if (! array_key_exists($scenario, BudgetAllocator::SCENARIOS)) {
			throw new ValidationFailed(['scenario' => 'Wybierz scenariusz z listy.']);
		}

		$overrides = [];

		foreach (['proven_share', 'priority', 'potential', 'performance'] as $key) {
			$raw = str_replace(',', '.', trim((string) ($input[$key] ?? '')));

			if ($raw === '') {
				continue;
			}

			if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
				throw new ValidationFailed([$key => 'Podaj wartość procentową 0–100.']);
			}

			$overrides[$key] = (float) $raw / 100;
		}

		$this->plans->updatePlan((int) $plan['id'], [
			'monthly_budget_micros' => $this->planner->money($input['monthly_budget'] ?? null, 'monthly_budget'),
			'scenario' => $scenario,
			'scenario_config' => $overrides,
		]);
		$this->plans->addEvent((int) $plan['id'], $project->id, $userId, 'budget', 'Scenariusz: ' . BudgetAllocator::SCENARIOS[$scenario]['label']);
	}

	public function setCampaignBudget(Project $project, int $planId, int $campaignId, mixed $amount): void
	{
		$plan = $this->planner->editable($project, $planId);

		if ($this->plans->item('plan_campaigns', $project->id, (int) $plan['id'], $campaignId) === null) {
			throw new ValidationFailed(['campaign' => 'Nie znaleziono kampanii w tym planie.']);
		}

		$this->plans->update('plan_campaigns', $campaignId, (int) $plan['id'], ['budget_micros' => $this->planner->money($amount, 'budget')]);
		$this->plans->touch((int) $plan['id']);
	}

	/**
	 * Zastosowanie proponowanego podziału (nadpisuje budżety kampanii planu – nie konta Google Ads).
	 */
	public function applyProposal(Project $project, int $planId, int $userId, array $measurement): int
	{
		$plan = $this->planner->editable($project, $planId);
		$summary = $this->summary($project, $plan, $this->plans->tree($project->id, (int) $plan['id']), $measurement);

		if ($summary['proposal']['allocations'] === []) {
			throw new ValidationFailed(['budget' => implode(' ', $summary['proposal']['notes']) ?: 'Brak propozycji podziału.']);
		}

		$this->db->transaction(function () use ($summary, $plan) {
			foreach ($summary['proposal']['allocations'] as $campaignId => $amount) {
				$this->plans->update('plan_campaigns', (int) $campaignId, (int) $plan['id'], ['budget_micros' => $amount]);
			}

			$this->plans->touch((int) $plan['id']);
		});
		$this->plans->addEvent((int) $plan['id'], $project->id, $userId, 'budget_applied', 'Zastosowano proponowany podział budżetu.');

		return count($summary['proposal']['allocations']);
	}
}
