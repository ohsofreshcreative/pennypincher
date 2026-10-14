<?php

namespace App\PennyPincher\Services\Changes;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Planner\BudgetAllocator;
use App\PennyPincher\Domain\Planner\PlanStatus;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\GoogleAds\Changes\StateQueries;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Repositories\CampaignPlanRepository;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\GoogleAds\StateReader;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use App\PennyPincher\Support\Format;

/**
 * „Przygotuj wdrożenie do Google Ads” dla zatwierdzonego planu kampanii.
 *
 * Każda kampania planu to jedna atomowa grupa operacji (budżet → kampania wstrzymana → lokalizacje i język →
 * grupy reklam → słowa → reklamy → wykluczenia) z tymczasowymi identyfikatorami w jednym żądaniu googleAds:mutate:
 * Google zapisuje całą strukturę albo nic, więc nie powstanie kampania bez grup czy grupa bez kampanii.
 * Kampanie już powiązane z Google Ads (wdrożone wcześniej) są pomijane – ponowne kliknięcie nie tworzy duplikatów.
 */
class PlanDeploymentService
{
	public function __construct(
		private CampaignPlanRepository $plans,
		private ChangeSetRepository $sets,
		private ChangeCenterService $center,
		private GoogleAdsConnectionRepository $connections,
		private StateReader $reader,
		private WriteGuard $guard,
	) {
	}

	/**
	 * @param array{geo_ids?: array<int, int>, bidding?: string, eu_political?: string, search_partners?: bool} $options
	 */
	public function prepare(Project $project, int $planId, int $userId, array $options = []): int
	{
		$plan = $this->plans->find($project->id, $planId) ?? throw new ValidationFailed(['plan' => 'Nie znaleziono planu.']);

		if ($plan['status'] !== PlanStatus::APPROVED) {
			throw new ValidationFailed(['plan' => 'Wdrożenie można przygotować tylko dla zatwierdzonego planu.']);
		}

		if (($existing = $this->sets->openForSource($project->id, 'plan', $planId)) !== null) {
			return (int) $existing['id']; // ochrona przed podwójnym przygotowaniem (odświeżenie strony, ponowne kliknięcie)
		}

		$connection = $this->connections->currentForProject($project->id);

		if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
			throw new ValidationFailed(['connection' => 'Połącz projekt z kontem Google Ads, aby przygotować wdrożenie planu.']);
		}

		$customer = (string) $connection['customer_id'];
		$tree = $this->plans->tree($project->id, $planId);
		$linked = $this->sets->links($project->id, $customer, 'plan_campaign', array_keys($tree['campaigns']));
		$campaigns = array_filter($tree['campaigns'], fn ($c) => ! isset($linked[(int) $c['id']]));

		if ($campaigns === []) {
			throw new ValidationFailed(['plan' => 'Wszystkie kampanie tego planu zostały już utworzone w Google Ads.']);
		}

		try {
			$existingNames = array_map(fn ($c) => mb_strtolower((string) $c['name']), $this->reader->list($connection, 'campaign', StateQueries::campaign()));
			$language = $this->reader->languageByCode($connection, (string) ($plan['language'] ?? $project->context()->language()));
		} catch (GoogleAdsApiException $e) {
			throw new ValidationFailed(['google_ads' => 'Nie udało się odczytać konta: ' . $e->userMessage()]);
		}

		$problems = [];

		foreach ($campaigns as $campaign) {
			if (in_array(mb_strtolower((string) $campaign['name']), $existingNames, true)) {
				$problems[] = 'Kampania „' . $campaign['name'] . '” już istnieje na koncie (zmień nazwę w planie).';
			}

			if ($campaign['budget_micros'] === null || (int) $campaign['budget_micros'] <= 0) {
				$problems[] = 'Kampania „' . $campaign['name'] . '” nie ma budżetu – ustaw go w Budget Planner.';
			}

			if (array_sum(array_map(fn ($g) => count($g['keywords']), $campaign['groups'])) === 0) {
				$problems[] = 'Kampania „' . $campaign['name'] . '” nie ma słów kluczowych.';
			}
		}

		if ($problems !== []) {
			throw new ValidationFailed(['plan' => implode(' ', array_slice($problems, 0, 4))]);
		}

		$geoIds = array_values(array_filter(array_map('intval', (array) ($options['geo_ids'] ?? [$project->context()->locationCode()]))));
		$monthly = array_sum(array_map(fn ($c) => (int) $c['budget_micros'], $campaigns));
		$setId = $this->center->createSet($project, [
			'title' => 'Wdrożenie planu „' . mb_substr($plan['name'], 0, 200) . '”',
			'description' => sprintf("Kampanie: %d (wszystkie wstrzymane po utworzeniu). Budżety miesięczne łącznie: %s (ok. %s dziennie). Budżet planistyczny nie jest gwarantowanym limitem – Google może w pojedynczym dniu wydać do 2× budżetu dziennego.", count($campaigns), Format::money($monthly, $project->currency, 0), Format::money((int) round($monthly / BudgetAllocator::DAYS_PER_MONTH), $project->currency, 0)),
			'source_type' => 'plan', 'source_id' => $planId, 'source_label' => $plan['name'],
		], $userId);
		$n = 0;

		foreach ($campaigns as $campaign) {
			$n++;
			$group = 'plan:' . $planId . ':' . $campaign['id'];
			$campaignRef = 'ref:' . $group . ':campaign';
			$daily = intdiv((int) round((int) $campaign['budget_micros'] / BudgetAllocator::DAYS_PER_MONTH), 10_000) * 10_000;
			$add = fn (array $op) => $this->center->addOperation($project, $setId, $op + ['atomic_group' => $group, 'before_source' => 'api'], $userId);

			$add(['type' => 'campaign_budget.create', 'temp_ref' => $group . ':budget', 'payload' => ['name' => mb_substr('Budżet – ' . $campaign['name'], 0, 255), 'amount_micros' => max(10_000, $daily)], 'label' => sprintf('Budżet dzienny %s dla „%s” (z budżetu miesięcznego %s)', Format::money(max(10_000, $daily), $project->currency), $campaign['name'], Format::money((int) $campaign['budget_micros'], $project->currency, 0))]);
			$add(['type' => 'campaign.create', 'temp_ref' => $group . ':campaign', 'payload' => [
				'name' => (string) $campaign['name'], 'budget' => 'ref:' . $group . ':budget', 'bidding' => (string) ($options['bidding'] ?? 'MANUAL_CPC'),
				'target_search_network' => (bool) ($options['search_partners'] ?? false), 'target_content_network' => false, 'geo_presence_only' => true,
				'eu_political' => $options['eu_political'] ?? null,
			], 'label' => 'Kampania „' . $campaign['name'] . '” (sieć wyszukiwania, wstrzymana)', 'local_type' => 'plan_campaign', 'local_id' => (int) $campaign['id']]);

			foreach ($geoIds as $geo) {
				$add(['type' => 'campaign_criterion.create', 'payload' => ['campaign' => $campaignRef, 'kind' => 'location', 'geo_target_constant' => 'geoTargetConstants/' . $geo], 'label' => 'Lokalizacja geoTargetConstants/' . $geo]);
			}

			if ($language !== null) {
				$add(['type' => 'campaign_criterion.create', 'payload' => ['campaign' => $campaignRef, 'kind' => 'language', 'language_constant' => $language], 'label' => 'Język ' . $language]);
			}

			foreach ($campaign['groups'] as $adGroup) {
				$groupRef = $group . ':ag:' . $adGroup['id'];
				// Grupa aktywna w kampanii wstrzymanej: nic nie wydaje, dopóki kampania nie zostanie świadomie włączona.
				// Przy blokadzie emisji (tryb testów zapisu) grupa też powstaje jako wstrzymana.
				$add(['type' => 'ad_group.create', 'temp_ref' => $groupRef, 'payload' => ['campaign' => $campaignRef, 'name' => (string) $adGroup['name'], 'status' => $this->guard->servingAllowed() ? 'ENABLED' : 'PAUSED'], 'label' => 'Grupa reklam „' . $adGroup['name'] . '”', 'local_type' => 'plan_ad_group', 'local_id' => (int) $adGroup['id']]);

				foreach ($adGroup['keywords'] as $keyword) {
					$add(['type' => 'ad_group_criterion.create', 'payload' => ['ad_group' => 'ref:' . $groupRef, 'text' => (string) $keyword['phrase'], 'match_type' => strtoupper((string) $keyword['match_type']), 'status' => 'ENABLED'], 'label' => 'Słowo „' . $keyword['phrase'] . '” (' . $keyword['match_type'] . ')', 'local_type' => 'plan_keyword', 'local_id' => (int) $keyword['id']]);
				}

				foreach ($adGroup['ads'] as $ad) {
					$url = $ad['final_url'] ?: ($adGroup['final_url'] ?: ($campaign['final_url'] ?: $project->landingPageUrl));
					$add(['type' => 'ad_group_ad.create', 'payload' => ['ad_group' => 'ref:' . $groupRef, 'headlines' => $ad['headlines'], 'descriptions' => $ad['descriptions'], 'final_url' => (string) $url, 'path1' => (string) ($ad['path1'] ?? ''), 'path2' => (string) ($ad['path2'] ?? ''), 'status' => $this->guard->servingAllowed() ? 'ENABLED' : 'PAUSED'], 'label' => 'Elastyczna reklama w grupie „' . $adGroup['name'] . '”', 'local_type' => 'plan_ad', 'local_id' => (int) $ad['id']]);
				}
			}

			foreach ($tree['negatives'] as $negative) {
				$level = $negative['level'];

				if ($level === 'ad_group' && isset($campaign['groups'][(int) $negative['plan_ad_group_id']])) {
					$add(['type' => 'ad_group_criterion.create', 'payload' => ['ad_group' => 'ref:' . $group . ':ag:' . $negative['plan_ad_group_id'], 'negative' => true, 'text' => (string) $negative['phrase'], 'match_type' => strtoupper((string) $negative['match_type'])], 'label' => 'Wykluczenie „' . $negative['phrase'] . '” w grupie', 'local_type' => 'plan_negative', 'local_id' => (int) $negative['id']]);
				} elseif ($level === 'plan' || ($level === 'campaign' && (int) $negative['plan_campaign_id'] === (int) $campaign['id'])) {
					$add(['type' => 'campaign_criterion.create', 'payload' => ['campaign' => $campaignRef, 'kind' => 'negative_keyword', 'text' => (string) $negative['phrase'], 'match_type' => strtoupper((string) $negative['match_type'])], 'label' => 'Wykluczenie „' . $negative['phrase'] . '” w kampanii', 'local_type' => $level === 'campaign' ? 'plan_negative' : null, 'local_id' => $level === 'campaign' ? (int) $negative['id'] : null]);
				}
			}
		}

		$this->plans->addEvent($planId, $project->id, $userId, 'deployment_prepared', 'Przygotowano wdrożenie w Change Center (zestaw #' . $setId . '). Nic nie zostało jeszcze utworzone w Google Ads.');

		return $setId;
	}

	/**
	 * Powiązania kampanii planu z Google Ads (po wdrożeniu).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function links(Project $project, int $planId): array
	{
		$connection = $this->connections->currentForProject($project->id);

		if ($connection === null || ! $connection['customer_id']) {
			return [];
		}

		$tree = $this->plans->tree($project->id, $planId);

		return $this->sets->links($project->id, (string) $connection['customer_id'], 'plan_campaign', array_keys($tree['campaigns']));
	}

	public static function isDeploying(?array $set): bool
	{
		return $set !== null && ! in_array($set['status'], [ChangeStatus::CANCELLED], true);
	}
}
