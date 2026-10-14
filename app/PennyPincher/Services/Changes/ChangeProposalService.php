<?php

namespace App\PennyPincher\Services\Changes;

use App\PennyPincher\Domain\Conversions\ConversionFieldPolicy;
use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\GoogleAds\Changes\ResourceName;
use App\PennyPincher\Domain\Planner\MatchType;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\Analysis\KeywordOpportunityService;
use App\PennyPincher\Services\GoogleAds\StateReader;

/**
 * Przygotowanie szkiców zmian (Change Center) z ręcznych akcji w Google Ads Management i z modułów analizy
 * (rekomendacje, Action Plan, Budget Leaks, Keyword Opportunities, wykluczenia, Conversion Health Check).
 *
 * Tworzy wyłącznie szkice – nic nie jest wysyłane do Google Ads. Rekomendacje, których nie da się wyrazić
 * operacją API (np. poprawa strony docelowej), nie dostają szkicu (`deployable()` zwraca powód).
 */
class ChangeProposalService
{
	/** Typy rekomendacji z obsługiwanym przygotowaniem zmiany. */
	/** Rekomendacje konwersji → pole działania konwersji, które zmieniają. */
	public const CONVERSION_REC_FIELDS = ['audit_ct001' => 'primary_for_goal', 'audit_ct003' => 'counting_type'];

	public const DEPLOYABLE = ['negatives', 'keyword_opportunities', 'group_terms', 'group_keywords', 'leak_bl101', 'leak_bl102', 'leak_bl201', 'leak_bl304', 'audit_cs001', 'audit_cs002', 'audit_ct001', 'audit_ct003'];

	public function __construct(
		private Db $db,
		private ChangeCenterService $center,
		private ChangeSetRepository $sets,
		private GoogleAdsConnectionRepository $connections,
		private StateReader $reader,
		private AnalysisRepository $analyses,
		private RecommendationRepository $recommendations,
	) {
	}

	// --- Kampanie ---------------------------------------------------------------------------------

	public function campaignUpdate(Project $project, int $userId, int $campaignId, array $values, ?int $setId = null): int
	{
		$campaign = $this->campaign($project, $campaignId);
		$payload = array_intersect_key($values, array_flip(['name', 'status', 'target_search_network', 'target_content_network']));

		if ($payload === []) {
			throw new ValidationFailed(['change' => 'Nie wybrano żadnej zmiany.']);
		}

		$label = match (true) {
			($payload['status'] ?? null) === 'PAUSED' => 'Wstrzymaj kampanię „' . $campaign['name'] . '”',
			($payload['status'] ?? null) === 'ENABLED' => 'Włącz kampanię „' . $campaign['name'] . '” (zacznie wydawać budżet)',
			isset($payload['name']) => 'Zmień nazwę kampanii „' . $campaign['name'] . '”',
			default => 'Zmień sieci reklamowe kampanii „' . $campaign['name'] . '”',
		};
		$setId = $this->target($project, $userId, $setId, $label);
		$this->center->addOperation($project, $setId, [
			'type' => 'campaign.update', 'resource_name' => $campaign['resource_name'], 'payload' => $payload, 'label' => $label,
			'before_state' => $this->campaignSyncState($campaign),
		], $userId);

		return $setId;
	}

	/**
	 * Zmiana budżetu dziennego – wymaga odczytu kampanii z Google Ads (identyfikator budżetu i współdzielenie).
	 */
	public function campaignBudget(Project $project, int $userId, int $campaignId, int $amountMicros, bool $sharedAck, ?int $setId = null): int
	{
		$campaign = $this->campaign($project, $campaignId);
		$connection = $this->activeConnection($project);

		try {
			$state = $this->reader->read($connection, 'campaign', $campaign['resource_name']);
			$budgetName = $state['budget'] ?? null;
			$budget = $budgetName ? $this->reader->read($connection, 'campaign_budget', $budgetName) : null;
		} catch (GoogleAdsApiException $e) {
			throw new ValidationFailed(['google_ads' => 'Nie udało się odczytać budżetu kampanii: ' . $e->userMessage()]);
		}

		if ($budget === null) {
			throw new ValidationFailed(['budget' => 'Nie znaleziono budżetu kampanii w Google Ads.']);
		}

		$budget['campaigns'] = array_map(fn ($c) => ['name' => $c['name'], 'status' => $c['status'], 'resource_name' => $c['resource_name']], $this->reader->campaignsUsingBudget($connection, (string) $budgetName));
		$label = sprintf('Budżet dzienny kampanii „%s”', $campaign['name']);
		$setId = $this->target($project, $userId, $setId, 'Zmiana budżetu: ' . $campaign['name']);
		$this->center->addOperation($project, $setId, [
			'type' => 'campaign_budget.update', 'resource_name' => (string) $budgetName, 'payload' => array_filter(['amount_micros' => $amountMicros, 'shared_ack' => $sharedAck ?: null]),
			'label' => $label, 'before_state' => $budget, 'before_source' => 'api',
		], $userId);

		return $setId;
	}

	public function campaignBidding(Project $project, int $userId, int $campaignId, string $strategy, ?int $targetCpa, ?int $ceiling, ?int $setId = null): int
	{
		$campaign = $this->campaign($project, $campaignId);
		$setId = $this->target($project, $userId, $setId, 'Strategia stawek: ' . $campaign['name']);
		$this->center->addOperation($project, $setId, [
			'type' => 'campaign.bidding', 'resource_name' => $campaign['resource_name'],
			'payload' => array_filter(['strategy' => $strategy, 'target_cpa_micros' => $strategy === 'MAXIMIZE_CONVERSIONS' ? $targetCpa : null, 'cpc_bid_ceiling_micros' => $strategy === 'TARGET_SPEND' ? $ceiling : null], fn ($v) => $v !== null),
			'label' => 'Zmień strategię stawek kampanii „' . $campaign['name'] . '” na: ' . (OperationCatalog::BIDDING[$strategy] ?? $strategy),
			'before_state' => $this->campaignSyncState($campaign),
		], $userId);

		return $setId;
	}

	/**
	 * Nowa kampania w sieci wyszukiwania (zawsze wstrzymana): budżet + kampania + lokalizacje + język
	 * (+ opcjonalnie pierwsza grupa reklam i słowa) w jednej atomowej grupie z tymczasowymi identyfikatorami.
	 */
	public function newCampaign(Project $project, int $userId, array $input, ?int $setId = null): int
	{
		$connection = $this->activeConnection($project);
		$name = trim(wp_strip_all_tags((string) ($input['name'] ?? '')));
		$group = 'campaign:' . substr(md5($name . microtime()), 0, 8);
		$setId = $this->target($project, $userId, $setId, 'Nowa kampania: ' . $name);
		$languages = [];

		try {
			foreach ((array) ($input['languages'] ?? [$project->context()->language()]) as $code) {
				$constant = $this->reader->languageByCode($connection, (string) $code);

				if ($constant === null) {
					throw new ValidationFailed(['language' => 'Nie znaleziono języka „' . $code . '” w Google Ads.']);
				}

				$languages[] = $constant;
			}
		} catch (GoogleAdsApiException $e) {
			throw new ValidationFailed(['google_ads' => 'Nie udało się odczytać języków z Google Ads: ' . $e->userMessage()]);
		}

		$this->center->addOperation($project, $setId, ['type' => 'campaign_budget.create', 'temp_ref' => $group . ':budget', 'atomic_group' => $group, 'payload' => ['name' => mb_substr('Budżet – ' . $name, 0, 255), 'amount_micros' => (int) ($input['budget_micros'] ?? 0)], 'label' => 'Budżet dzienny nowej kampanii „' . $name . '”', 'before_source' => 'api', 'local_type' => $input['local_type'] ?? null, 'local_id' => null], $userId);
		$this->center->addOperation($project, $setId, [
			'type' => 'campaign.create', 'temp_ref' => $group . ':campaign', 'atomic_group' => $group, 'label' => 'Nowa kampania „' . $name . '” (wstrzymana)',
			'payload' => array_filter([
				'name' => $name, 'budget' => 'ref:' . $group . ':budget', 'bidding' => (string) ($input['bidding'] ?? 'MANUAL_CPC'),
				'target_cpa_micros' => $input['target_cpa_micros'] ?? null, 'cpc_bid_ceiling_micros' => $input['cpc_bid_ceiling_micros'] ?? null,
				'target_search_network' => (bool) ($input['search_partners'] ?? false), 'target_content_network' => false,
				'geo_presence_only' => (bool) ($input['presence_only'] ?? true), 'eu_political' => $input['eu_political'] ?? null,
			], fn ($v) => $v !== null),
			'local_type' => $input['local_type'] ?? null, 'local_id' => $input['local_id'] ?? null,
		], $userId);

		foreach (array_unique(array_map('intval', (array) ($input['geo_ids'] ?? []))) as $geo) {
			if ($geo > 0) {
				$this->center->addOperation($project, $setId, ['type' => 'campaign_criterion.create', 'atomic_group' => $group, 'payload' => ['campaign' => 'ref:' . $group . ':campaign', 'kind' => 'location', 'geo_target_constant' => 'geoTargetConstants/' . $geo], 'label' => 'Lokalizacja: geoTargetConstants/' . $geo, 'before_source' => 'api'], $userId);
			}
		}

		foreach ($languages as $language) {
			$this->center->addOperation($project, $setId, ['type' => 'campaign_criterion.create', 'atomic_group' => $group, 'payload' => ['campaign' => 'ref:' . $group . ':campaign', 'kind' => 'language', 'language_constant' => $language], 'label' => 'Język: ' . $language, 'before_source' => 'api'], $userId);
		}

		return $setId;
	}

	// --- Kierowanie i wykluczenia kampanii --------------------------------------------------------

	public function campaignCriterionAdd(Project $project, int $userId, int $campaignId, string $kind, array $values, ?int $setId = null): int
	{
		$campaign = $this->campaign($project, $campaignId);
		$setId = $this->target($project, $userId, $setId, 'Kampania „' . $campaign['name'] . '”: kierowanie i wykluczenia');

		if ($kind === 'negative_keyword') {
			foreach (self::phrases($values['phrases'] ?? '') as $phrase) {
				$this->center->addOperation($project, $setId, ['type' => 'campaign_criterion.create', 'payload' => ['campaign' => $campaign['resource_name'], 'kind' => 'negative_keyword', 'text' => $phrase, 'match_type' => strtoupper((string) ($values['match_type'] ?? 'PHRASE'))], 'label' => sprintf('Wykluczenie %s w kampanii „%s”', MatchType::notation($phrase, strtolower((string) ($values['match_type'] ?? 'phrase'))), $campaign['name'])], $userId);
			}

			return $setId;
		}

		if ($kind === 'language') {
			$connection = $this->activeConnection($project);

			try {
				$constant = $this->reader->languageByCode($connection, (string) ($values['language'] ?? ''));
			} catch (GoogleAdsApiException $e) {
				throw new ValidationFailed(['google_ads' => $e->userMessage()]);
			}

			if ($constant === null) {
				throw new ValidationFailed(['language' => 'Nie znaleziono języka w Google Ads.']);
			}

			$this->center->addOperation($project, $setId, ['type' => 'campaign_criterion.create', 'payload' => ['campaign' => $campaign['resource_name'], 'kind' => 'language', 'language_constant' => $constant], 'label' => 'Dodaj język ' . $constant . ' w kampanii „' . $campaign['name'] . '”'], $userId);

			return $setId;
		}

		$geo = (int) ($values['geo_id'] ?? 0);

		if ($geo <= 0) {
			throw new ValidationFailed(['geo_id' => 'Podaj identyfikator lokalizacji Google Ads (np. 2616 – Polska).']);
		}

		$this->center->addOperation($project, $setId, ['type' => 'campaign_criterion.create', 'payload' => ['campaign' => $campaign['resource_name'], 'kind' => 'location', 'geo_target_constant' => 'geoTargetConstants/' . $geo], 'label' => 'Dodaj lokalizację geoTargetConstants/' . $geo . ' w kampanii „' . $campaign['name'] . '”'], $userId);

		return $setId;
	}

	public function campaignCriterionRemove(Project $project, int $userId, int $campaignId, string $resourceName, ?int $setId = null): int
	{
		$campaign = $this->campaign($project, $campaignId);

		if (! str_starts_with($resourceName, 'customers/' . $campaign['customer_id'] . '/campaignCriteria/' . $campaign['external_id'] . '~')) {
			throw new ValidationFailed(['criterion' => 'Kryterium nie należy do tej kampanii.']);
		}

		$setId = $this->target($project, $userId, $setId, 'Kampania „' . $campaign['name'] . '”: kierowanie i wykluczenia');
		$this->center->addOperation($project, $setId, ['type' => 'campaign_criterion.remove', 'resource_name' => $resourceName, 'payload' => [], 'label' => 'Usuń kryterium ' . ResourceName::childId($resourceName) . ' z kampanii „' . $campaign['name'] . '”'], $userId);

		return $setId;
	}

	// --- Grupy reklam ---------------------------------------------------------------------------

	public function adGroupCreate(Project $project, int $userId, int $campaignId, array $values, ?int $setId = null): int
	{
		$campaign = $this->campaign($project, $campaignId);

		if ($campaign['channel'] !== 'search') {
			throw new ValidationFailed(['campaign' => 'Grupy reklam Penny Pincher tworzy tylko w kampaniach w sieci wyszukiwania.']);
		}

		$setId = $this->target($project, $userId, $setId, 'Nowa grupa reklam w „' . $campaign['name'] . '”');
		$this->center->addOperation($project, $setId, ['type' => 'ad_group.create', 'payload' => array_filter(['campaign' => $campaign['resource_name'], 'name' => trim(wp_strip_all_tags((string) ($values['name'] ?? ''))), 'status' => ($values['status'] ?? 'PAUSED') === 'ENABLED' ? 'ENABLED' : 'PAUSED', 'cpc_bid_micros' => $values['cpc_bid_micros'] ?? null], fn ($v) => $v !== null), 'label' => 'Nowa grupa reklam „' . trim((string) ($values['name'] ?? '')) . '” w kampanii „' . $campaign['name'] . '”'], $userId);

		return $setId;
	}

	public function adGroupUpdate(Project $project, int $userId, int $adGroupId, array $values, ?int $setId = null): int
	{
		$group = $this->adGroup($project, $adGroupId);
		$payload = array_intersect_key($values, array_flip(['name', 'status', 'cpc_bid_micros']));
		$setId = $this->target($project, $userId, $setId, 'Grupa reklam „' . $group['name'] . '”');
		$this->center->addOperation($project, $setId, ['type' => 'ad_group.update', 'resource_name' => $group['resource_name'], 'payload' => $payload, 'label' => match ($payload['status'] ?? null) {
			'PAUSED' => 'Wstrzymaj grupę reklam „' . $group['name'] . '”',
			'ENABLED' => 'Włącz grupę reklam „' . $group['name'] . '”',
			default => 'Zmień grupę reklam „' . $group['name'] . '”',
		}, 'before_state' => ['name' => $group['name'], 'status' => strtoupper((string) ($group['status'] ?? '')) ?: null]], $userId);

		return $setId;
	}

	// --- Słowa kluczowe -------------------------------------------------------------------------

	/**
	 * @param array<int, string> $phrases
	 */
	public function keywordsAdd(Project $project, int $userId, int $adGroupId, array $phrases, string $matchType, string $status = 'ENABLED', ?int $setId = null, string $sourceType = 'manual', ?int $sourceId = null, ?string $sourceLabel = null): int
	{
		$group = $this->adGroup($project, $adGroupId);
		$setId = $this->target($project, $userId, $setId, 'Nowe słowa kluczowe w „' . $group['name'] . '”', $sourceType, $sourceId, $sourceLabel);

		foreach ($phrases as $phrase) {
			$match = strtoupper($matchType === 'auto' ? MatchType::suggest($phrase) : $matchType);
			$this->center->addOperation($project, $setId, ['type' => 'ad_group_criterion.create', 'payload' => ['ad_group' => $group['resource_name'], 'text' => $phrase, 'match_type' => $match, 'status' => $status === 'PAUSED' ? 'PAUSED' : 'ENABLED'], 'label' => sprintf('Dodaj słowo %s do grupy „%s”', MatchType::notation($phrase, strtolower($match)), $group['name'])], $userId);
		}

		return $setId;
	}

	public function keywordStatus(Project $project, int $userId, int $keywordId, string $status, ?int $setId = null, string $sourceType = 'manual', ?int $sourceId = null, ?string $sourceLabel = null): int
	{
		$keyword = $this->keyword($project, $keywordId);
		$setId = $this->target($project, $userId, $setId, ($status === 'PAUSED' ? 'Wstrzymanie' : 'Włączenie') . ' słów kluczowych', $sourceType, $sourceId, $sourceLabel);
		$this->center->addOperation($project, $setId, ['type' => 'ad_group_criterion.update', 'resource_name' => $keyword['resource_name'], 'payload' => ['status' => $status === 'PAUSED' ? 'PAUSED' : 'ENABLED'], 'label' => sprintf('%s słowo %s (grupa „%s”)', $status === 'PAUSED' ? 'Wstrzymaj' : 'Włącz', MatchType::notation($keyword['text'], (string) $keyword['match_type']), $keyword['ad_group_name']), 'before_state' => ['status' => strtoupper((string) $keyword['status']), 'keyword_text' => mb_strtolower($keyword['text']), 'match_type' => strtoupper((string) $keyword['match_type']), 'negative' => false]], $userId);

		return $setId;
	}

	public function keywordRemove(Project $project, int $userId, int $keywordId, ?int $setId = null): int
	{
		$keyword = $this->keyword($project, $keywordId);
		$setId = $this->target($project, $userId, $setId, 'Usunięcie słów kluczowych');
		$this->center->addOperation($project, $setId, ['type' => 'ad_group_criterion.remove', 'resource_name' => $keyword['resource_name'], 'payload' => [], 'label' => sprintf('Usuń słowo %s z grupy „%s” (nieodwracalne – historia słowa zostaje w raportach)', MatchType::notation($keyword['text'], (string) $keyword['match_type']), $keyword['ad_group_name']), 'before_state' => ['status' => strtoupper((string) $keyword['status']), 'negative' => false]], $userId);

		return $setId;
	}

	/**
	 * Zmiana dopasowania = zastąpienie: nowe kryterium + usunięcie starego w JEDNYM atomowym żądaniu
	 * (Google zapisuje oba albo żadne – nie zostaniemy bez słowa ani z duplikatem).
	 */
	public function keywordReplaceMatch(Project $project, int $userId, int $keywordId, string $matchType, ?int $setId = null): int
	{
		$keyword = $this->keyword($project, $keywordId);
		$match = strtoupper($matchType);

		if ($match === strtoupper((string) $keyword['match_type'])) {
			throw new ValidationFailed(['match_type' => 'Słowo ma już ten typ dopasowania.']);
		}

		$group = 'replace:' . $keywordId . ':' . substr(md5(microtime()), 0, 6);
		$setId = $this->target($project, $userId, $setId, 'Zmiana dopasowania słów kluczowych');
		$createId = $this->center->addOperation($project, $setId, ['type' => 'ad_group_criterion.create', 'atomic_group' => $group, 'payload' => ['ad_group' => $keyword['ad_group_resource_name'], 'text' => $keyword['text'], 'match_type' => $match, 'status' => strtoupper((string) $keyword['status']) === 'PAUSED' ? 'PAUSED' : 'ENABLED'], 'label' => sprintf('Zastąpienie (1/2): nowe słowo %s', MatchType::notation($keyword['text'], strtolower($match)))], $userId);
		$create = $this->sets->findOperation($setId, $createId);
		$this->center->addOperation($project, $setId, ['type' => 'ad_group_criterion.remove', 'resource_name' => $keyword['resource_name'], 'atomic_group' => $group, 'depends_on' => [$create['op_uuid']], 'payload' => [], 'label' => sprintf('Zastąpienie (2/2): usunięcie starego %s – statystyki zostają przy starym słowie', MatchType::notation($keyword['text'], (string) $keyword['match_type'])), 'before_state' => ['status' => strtoupper((string) $keyword['status']), 'negative' => false]], $userId);

		return $setId;
	}

	/**
	 * @param array<int, string> $phrases
	 */
	public function adGroupNegativesAdd(Project $project, int $userId, int $adGroupId, array $phrases, string $matchType, ?int $setId = null): int
	{
		$group = $this->adGroup($project, $adGroupId);
		$setId = $this->target($project, $userId, $setId, 'Wykluczenia w grupie „' . $group['name'] . '”');

		foreach ($phrases as $phrase) {
			$this->center->addOperation($project, $setId, ['type' => 'ad_group_criterion.create', 'payload' => ['ad_group' => $group['resource_name'], 'negative' => true, 'text' => $phrase, 'match_type' => strtoupper($matchType)], 'label' => sprintf('Wykluczenie %s w grupie „%s”', MatchType::notation($phrase, strtolower($matchType)), $group['name'])], $userId);
		}

		return $setId;
	}

	public function criterionRemove(Project $project, int $userId, int $adGroupId, string $resourceName, ?int $setId = null): int
	{
		$group = $this->adGroup($project, $adGroupId);

		if (! str_starts_with($resourceName, 'customers/' . $group['customer_id'] . '/adGroupCriteria/' . $group['external_id'] . '~')) {
			throw new ValidationFailed(['criterion' => 'Kryterium nie należy do tej grupy reklam.']);
		}

		$setId = $this->target($project, $userId, $setId, 'Grupa „' . $group['name'] . '”: usunięcie kryterium');
		$this->center->addOperation($project, $setId, ['type' => 'ad_group_criterion.remove', 'resource_name' => $resourceName, 'payload' => [], 'label' => 'Usuń kryterium ' . ResourceName::childId($resourceName) . ' z grupy „' . $group['name'] . '”'], $userId);

		return $setId;
	}

	// --- Reklamy --------------------------------------------------------------------------------

	public function adCreate(Project $project, int $userId, int $adGroupId, array $values, ?int $setId = null, ?string $replaceResource = null): int
	{
		$group = $this->adGroup($project, $adGroupId);
		$payload = [
			'ad_group' => $group['resource_name'],
			'headlines' => array_values(array_filter(array_map(fn ($h) => trim((string) $h), (array) ($values['headlines'] ?? [])), fn ($h) => $h !== '')),
			'descriptions' => array_values(array_filter(array_map(fn ($d) => trim((string) $d), (array) ($values['descriptions'] ?? [])), fn ($d) => $d !== '')),
			'final_url' => trim((string) ($values['final_url'] ?? '')),
			'path1' => trim((string) ($values['path1'] ?? '')),
			'path2' => trim((string) ($values['path2'] ?? '')),
			'status' => ($values['status'] ?? 'PAUSED') === 'ENABLED' ? 'ENABLED' : 'PAUSED',
		];
		$setId = $this->target($project, $userId, $setId, 'Reklama w grupie „' . $group['name'] . '”');

		if ($replaceResource === null) {
			$this->center->addOperation($project, $setId, ['type' => 'ad_group_ad.create', 'payload' => $payload, 'label' => 'Nowa elastyczna reklama w wyszukiwarce w grupie „' . $group['name'] . '”'], $userId);

			return $setId;
		}

		// Nowa wersja reklamy: utworzenie nowej i wstrzymanie poprzedniej w jednym atomowym żądaniu (statystyki starej zostają).
		if (! str_starts_with($replaceResource, 'customers/' . $group['customer_id'] . '/adGroupAds/' . $group['external_id'] . '~')) {
			throw new ValidationFailed(['ad' => 'Reklama nie należy do tej grupy.']);
		}

		$atomic = 'ad-version:' . substr(md5($replaceResource . microtime()), 0, 8);
		$create = $this->center->addOperation($project, $setId, ['type' => 'ad_group_ad.create', 'atomic_group' => $atomic, 'payload' => $payload, 'label' => 'Nowa wersja reklamy w grupie „' . $group['name'] . '”'], $userId);
		$this->center->addOperation($project, $setId, ['type' => 'ad_group_ad.update', 'resource_name' => $replaceResource, 'atomic_group' => $atomic, 'depends_on' => [$this->sets->findOperation($setId, $create)['op_uuid']], 'payload' => ['status' => 'PAUSED'], 'label' => 'Wstrzymanie poprzedniej wersji reklamy (' . ResourceName::childId($replaceResource) . ')'], $userId);

		return $setId;
	}

	public function adStatus(Project $project, int $userId, int $adGroupId, string $resourceName, string $status, ?int $setId = null): int
	{
		$group = $this->adGroup($project, $adGroupId);

		if (! str_starts_with($resourceName, 'customers/' . $group['customer_id'] . '/adGroupAds/' . $group['external_id'] . '~')) {
			throw new ValidationFailed(['ad' => 'Reklama nie należy do tej grupy.']);
		}

		$setId = $this->target($project, $userId, $setId, 'Reklamy w grupie „' . $group['name'] . '”');
		$this->center->addOperation($project, $setId, ['type' => 'ad_group_ad.update', 'resource_name' => $resourceName, 'payload' => ['status' => $status === 'ENABLED' ? 'ENABLED' : 'PAUSED'], 'label' => ($status === 'ENABLED' ? 'Wznów' : 'Wstrzymaj') . ' reklamę ' . ResourceName::childId($resourceName) . ' w grupie „' . $group['name'] . '”'], $userId);

		return $setId;
	}

	/**
	 * Usunięcie reklamy (nieodwracalne – Google nie przywraca usuniętych reklam; statystyki zostają w raportach).
	 */
	public function adRemove(Project $project, int $userId, int $adGroupId, string $resourceName, ?int $setId = null): int
	{
		$group = $this->adGroup($project, $adGroupId);

		if (! str_starts_with($resourceName, 'customers/' . $group['customer_id'] . '/adGroupAds/' . $group['external_id'] . '~')) {
			throw new ValidationFailed(['ad' => 'Reklama nie należy do tej grupy.']);
		}

		$setId = $this->target($project, $userId, $setId, 'Reklamy w grupie „' . $group['name'] . '”');
		$this->center->addOperation($project, $setId, ['type' => 'ad_group_ad.remove', 'resource_name' => $resourceName, 'payload' => [], 'label' => 'Usuń reklamę ' . ResourceName::childId($resourceName) . ' z grupy „' . $group['name'] . '” (nieodwracalne – zamiast tego możesz ją wstrzymać)'], $userId);

		return $setId;
	}

	// --- Konwersje ------------------------------------------------------------------------------

	public function conversionCreate(Project $project, int $userId, array $values, ?int $setId = null, string $sourceType = 'manual'): int
	{
		$this->activeConnection($project);
		$name = trim(wp_strip_all_tags((string) ($values['name'] ?? '')));
		$setId = $this->target($project, $userId, $setId, 'Nowe działanie konwersji: ' . $name, $sourceType);
		$this->center->addOperation($project, $setId, ['type' => 'conversion_action.create', 'payload' => array_filter([
			'name' => $name, 'category' => (string) ($values['category'] ?? ''), 'counting_type' => (string) ($values['counting_type'] ?? 'ONE_PER_CLICK'),
			'click_through_lookback_window_days' => isset($values['click_window']) && $values['click_window'] !== '' ? (int) $values['click_window'] : null,
			'view_through_lookback_window_days' => isset($values['view_window']) && $values['view_window'] !== '' ? (int) $values['view_window'] : null,
			'default_value' => isset($values['default_value']) && $values['default_value'] !== '' ? (float) str_replace(',', '.', (string) $values['default_value']) : null,
			'always_use_default_value' => isset($values['default_value']) && $values['default_value'] !== '' ? ! empty($values['always_use_default_value']) : null,
			'primary_for_goal' => ! empty($values['primary_for_goal']),
		], fn ($v) => $v !== null), 'label' => 'Utwórz działanie konwersji „' . $name . '” (strona internetowa)'], $userId);

		return $setId;
	}

	public function conversionUpdate(Project $project, int $userId, int $actionId, array $payload, ?int $setId = null, string $sourceType = 'manual', ?string $label = null): int
	{
		$action = $this->conversionAction($project, $actionId);
		$state = $this->conversionState($project, $action);

		// Centralna polityka pól: nie przygotowujemy operacji, której Google Ads API nie wykona.
		if (($violations = ConversionFieldPolicy::violations($state, $payload)) !== []) {
			throw new ValidationFailed(['conversion' => '„' . $action['name'] . '”: ' . ConversionFieldPolicy::message($violations)]);
		}

		$setId = $this->target($project, $userId, $setId, 'Konwersja „' . $action['name'] . '”', $sourceType);
		$this->center->addOperation($project, $setId, [
			'type' => 'conversion_action.update', 'resource_name' => $action['resource_name'], 'payload' => $payload,
			'label' => $label ?? 'Zmień ustawienia działania konwersji „' . $action['name'] . '”',
			'before_state' => array_filter([
				'name' => $state['name'] ?? $action['name'], 'type' => $state['type'] ?? $action['type'], 'status' => $state['status'] ?? null,
				'category' => $state['category'] ?? $action['category_code'], 'counting_type' => $state['counting_type'] ?? $action['counting_type'],
				'primary_for_goal' => array_key_exists('primary_for_goal', $state) ? (bool) $state['primary_for_goal'] : ($action['primary_for_goal'] === null ? null : (bool) $action['primary_for_goal']),
			], fn ($v) => $v !== null) + (array_key_exists('owner_customer', $state) ? ['owner_customer' => $state['owner_customer']] : []),
		], $userId);

		return $setId;
	}

	/**
	 * Najlepiej znany stan działania konwersji: odczyt z Google Ads (Conversion Management → „Odczytaj z Google Ads”),
	 * a bez niego – dane z synchronizacji (typ, status).
	 *
	 * @return array<string, mixed>
	 */
	public function conversionState(Project $project, array $action): array
	{
		$state = array_filter(['type' => $action['type'] ?? null, 'status' => $action['status_raw'] ?? (isset($action['status']) ? strtoupper((string) $action['status']) : null)], fn ($v) => $v !== null && $v !== '');
		$connection = $this->connections->currentForProject($project->id);

		if (! empty($action['external_id']) && $connection !== null && $connection['customer_id']) {
			$snapshot = $this->sets->snapshot($project->id, (string) $connection['customer_id'], ConversionManagementService::SNAPSHOT_ACTIONS, 'all');

			foreach ((array) ($snapshot['data'] ?? []) as $row) {
				if ((string) ($row['id'] ?? '') === (string) $action['external_id']) {
					return $row + $state;
				}
			}
		}

		return $state;
	}

	/**
	 * Powód, dla którego rekomendacji nie da się zamienić na operacje Google Ads API (null – da się).
	 * Dla rekomendacji konwersji (CT001, CT003) sprawdza każde działanie wg ConversionFieldPolicy – jeśli żadnego
	 * nie można zmienić przez API, rekomendacja jest działaniem ręcznym/informacyjnym.
	 */
	public function recommendationBlocker(Project $project, array $recommendation): ?string
	{
		if (($reason = self::deployable($recommendation)) !== null) {
			return $reason;
		}

		$field = self::CONVERSION_REC_FIELDS[$recommendation['type']] ?? null;

		if ($field === null) {
			return null;
		}

		$reasons = [];

		foreach ($this->findingEntities($project, $recommendation, 'conversion_action') as $actionId) {
			$row = $this->db->first('SELECT * FROM ' . $this->db->table('conversion_actions') . ' WHERE project_id = ? AND id = ?', [$project->id, $actionId]);

			if ($row === null) {
				continue;
			}

			if (empty($row['external_id'])) {
				$reasons[] = 'Działanie pochodzi z importu (brak identyfikatora Google Ads) – zsynchronizuj konto przez API.';
				continue;
			}

			$result = ConversionFieldPolicy::field($this->conversionState($project, $row), $field);

			if (in_array($result['status'], [ConversionFieldPolicy::EDITABLE, ConversionFieldPolicy::CONFIRM], true)) {
				return null;
			}

			$reasons[] = (string) $result['reason'];
		}

		return $reasons === [] ? null : 'Działanie ręczne: ' . implode(' ', array_values(array_unique($reasons)));
	}

	public function goalUpdate(Project $project, int $userId, string $resourceName, bool $biddable, ?int $setId = null): int
	{
		$customer = $this->activeConnection($project)['customer_id'];
		$type = str_contains($resourceName, '/customerConversionGoals/') ? 'customer_conversion_goal.update' : 'campaign_conversion_goal.update';

		if (! ResourceName::isValid($resourceName, (string) $customer)) {
			throw new ValidationFailed(['goal' => 'Cel nie należy do połączonego konta.']);
		}

		$setId = $this->target($project, $userId, $setId, 'Cele konwersji (optymalizacja stawek)', 'manual');
		$this->center->addOperation($project, $setId, ['type' => $type, 'resource_name' => $resourceName, 'payload' => ['biddable' => $biddable, 'confirm_goal_change' => false], 'label' => ($biddable ? 'Uwzględnij' : 'Wyłącz z optymalizacji') . ' cel ' . ResourceName::id($resourceName)], $userId);

		return $setId;
	}

	/**
	 * Szkic z propozycji Conversion Health Check (np. mikrokonwersja jako główna → dodatkowa).
	 */
	public function fromHealthIssue(Project $project, int $userId, array $issue): int
	{
		if ($issue['operations'] === []) {
			throw new ValidationFailed(['issue' => $issue['not_editable'] ?? 'Tego problemu nie da się poprawić przez Google Ads API.']);
		}

		$setId = $this->center->createSet($project, ['title' => 'Conversion Health Check: ' . mb_substr($issue['title'], 0, 200), 'description' => $issue['why'] . "\n\n" . $issue['proposal'], 'source_type' => 'conversion_health', 'source_label' => $issue['key']], $userId);

		foreach ($issue['operations'] as $op) {
			if ($op['type'] === 'conversion_action.update') {
				$this->conversionUpdate($project, $userId, (int) $op['action_id'], $op['payload'] + ['confirm_goal_change' => false], $setId, 'conversion_health', $issue['proposal']);
			} else {
				$this->goalUpdate($project, $userId, (string) $op['resource_name'], (bool) $op['payload']['biddable'], $setId);
			}
		}

		return $setId;
	}

	// --- Ze źródeł analizy ------------------------------------------------------------------------

	/**
	 * Czy rekomendację da się przełożyć na operacje Google Ads (null = tak; inaczej powód).
	 */
	public static function deployable(array $recommendation): ?string
	{
		return in_array($recommendation['type'], self::DEPLOYABLE, true) ? null : 'Ta rekomendacja wymaga działań poza Google Ads API (np. na stronie) albo decyzji, której nie da się bezpiecznie zapisać jako operacji.';
	}

	public function fromRecommendation(Project $project, int $userId, int $recommendationId, ?int $setId = null, string $sourceType = 'recommendation', ?int $sourceId = null): int
	{
		$rec = $this->recommendations->find($project->id, $recommendationId) ?? throw new ValidationFailed(['recommendation' => 'Nie znaleziono rekomendacji.']);

		if (($reason = $this->recommendationBlocker($project, $rec)) !== null) {
			throw new ValidationFailed(['recommendation' => $reason]);
		}

		$sourceId ??= (int) $rec['id'];

		if ($setId === null && ($existing = $this->sets->openForSource($project->id, $sourceType, $sourceId)) !== null) {
			return (int) $existing['id']; // nie dublujemy szkiców przy ponownym kliknięciu
		}

		$phrases = array_values(array_filter(array_map(fn ($pair) => (string) ($pair[0] ?? ''), (array) $rec['evidence'])));
		$created = $setId === null;
		$setId ??= $this->center->createSet($project, ['title' => mb_substr($rec['title'], 0, 255), 'description' => $rec['action'], 'source_type' => $sourceType, 'source_id' => $sourceId, 'source_label' => mb_substr($rec['title'], 0, 255)], $userId);
		$added = 0;

		try {
			$added = $this->recommendationOperations($project, $userId, $rec, $phrases, $setId, $sourceType);
		} catch (ValidationFailed $e) {
			if ($created) {
				$this->abandon($project, $setId, $userId, $e);
			}

			throw $e;
		}

		if ($added === 0) {
			if ($created) {
				$this->abandon($project, $setId, $userId, null);
			}

			throw new ValidationFailed(['recommendation' => 'Nie udało się przygotować operacji – brak danych z Google Ads (identyfikatorów kampanii, grup lub słów) dla tej rekomendacji. Zsynchronizuj konto przez API.']);
		}

		return $setId;
	}

	/**
	 * Operacje dla rekomendacji (dodawane do zestawu $setId). Zwraca liczbę dodanych operacji.
	 */
	private function recommendationOperations(Project $project, int $userId, array $rec, array $phrases, int $setId, string $sourceType): int
	{
		$added = 0;

		switch ($rec['type']) {
			case 'negatives':
				foreach ($this->negativeSuggestions($project, $phrases) as $suggestion) {
					if ($suggestion['level'] !== 'campaign' || $suggestion['campaign_id'] === null) {
						continue; // listy wykluczeń konta – poza zakresem operacji Penny Pincher
					}

					$this->campaignCriterionAdd($project, $userId, (int) $suggestion['campaign_id'], 'negative_keyword', ['phrases' => $suggestion['phrase'], 'match_type' => $suggestion['match_type']], $setId);
					$added++;
				}

				break;

			case 'group_terms':
			case 'leak_bl101':
			case 'leak_bl102':
				$terms = $rec['type'] === 'group_terms' ? $phrases : $this->leakSubjects($project, $rec);

				foreach ($terms as $term) {
					if ($rec['campaign_id'] !== null) {
						$this->campaignCriterionAdd($project, $userId, (int) $rec['campaign_id'], 'negative_keyword', ['phrases' => $term, 'match_type' => 'EXACT'], $setId);
						$added++;
					}
				}

				break;

			case 'group_keywords':
			case 'leak_bl201':
				$texts = $rec['type'] === 'group_keywords' ? $phrases : $this->leakSubjects($project, $rec);

				foreach ($this->keywordsByText($project, (int) $rec['campaign_id'], $texts) as $keywordId) {
					$this->keywordStatus($project, $userId, $keywordId, 'PAUSED', $setId);
					$added++;
				}

				break;

			case 'keyword_opportunities':
				if ($rec['ad_group_id'] !== null && $phrases !== []) {
					$this->keywordsAdd($project, $userId, (int) $rec['ad_group_id'], $phrases, 'auto', 'ENABLED', $setId);
					$added = count($phrases);
				}

				break;

			case 'leak_bl304':
			case 'audit_cs001':
			case 'audit_cs002':
				foreach ($rec['campaign_id'] !== null ? [(int) $rec['campaign_id']] : $this->findingEntities($project, $rec, 'campaign') as $campaignId) {
					$payload = $rec['type'] === 'audit_cs001' ? ['target_search_network' => false] : ($rec['type'] === 'audit_cs002' ? ['target_content_network' => false] : ['target_search_network' => false, 'target_content_network' => false]);
					$this->campaignUpdate($project, $userId, $campaignId, $payload, $setId);
					$added++;
				}

				break;

			case 'audit_ct001':
			case 'audit_ct003':
				$blocked = [];

				foreach ($this->findingEntities($project, $rec, 'conversion_action') as $actionId) {
					$payload = $rec['type'] === 'audit_ct001' ? ['primary_for_goal' => false, 'confirm_goal_change' => false] : ['counting_type' => 'ONE_PER_CLICK'];

					try {
						$this->conversionUpdate($project, $userId, $actionId, $payload, $setId, $sourceType);
						$added++;
					} catch (ValidationFailed $e) {
						// Działanie, którego pola API nie pozwala zmienić (np. typ Smart, systemowe) – bez operacji, zostaje działaniem ręcznym.
						$blocked[] = (string) (array_values($e->errors)[0] ?? $e->getMessage());
					}
				}

				if ($added === 0 && $blocked !== []) {
					throw new ValidationFailed(['recommendation' => 'Tej rekomendacji nie da się wdrożyć przez Google Ads API – to działanie ręczne. ' . implode(' ', array_values(array_unique($blocked)))]);
				}

				break;
		}

		return $added;
	}

	/**
	 * Wyciek budżetu (Budget Leaks) → operacje, tylko dla reguł, które mają bezpieczny odpowiednik w Google Ads API:
	 * BL101/BL102 – wykluczenie wyszukiwanego hasła (dopasowanie ścisłe) w kampanii, BL201 – wstrzymanie słowa,
	 * BL304 – wyłączenie sieci partnerskich / Display w kampanii w wyszukiwarce. Pozostałe reguły wymagają decyzji lub
	 * pracy poza Google Ads (pomiar, strona, struktura konta).
	 */
	public const LEAK_RULES = ['BL101', 'BL102', 'BL201', 'BL304'];

	public function fromLeak(Project $project, int $userId, int $leakId): int
	{
		$leak = $this->db->first('SELECT * FROM ' . $this->db->table('budget_leaks') . ' WHERE project_id = ? AND id = ?', [$project->id, $leakId])
			?? throw new ValidationFailed(['leak' => 'Nie znaleziono wycieku.']);

		if (! in_array($leak['rule_id'], self::LEAK_RULES, true)) {
			throw new ValidationFailed(['leak' => 'Ten wyciek wymaga działań poza Google Ads API (np. pomiar, strona, decyzja o strukturze) – Penny Pincher nie tworzy dla niego operacji.']);
		}

		if ($leak['campaign_id'] === null) {
			throw new ValidationFailed(['leak' => 'Wyciek nie jest przypisany do kampanii – nie ma czego zmienić w Google Ads.']);
		}

		if (($existing = $this->sets->openForSource($project->id, 'budget_leak', $leakId)) !== null) {
			return (int) $existing['id'];
		}

		$setId = $this->center->createSet($project, ['title' => mb_substr('Budget Leaks: ' . $leak['title'], 0, 255), 'description' => $leak['action'], 'source_type' => 'budget_leak', 'source_id' => $leakId, 'source_label' => mb_substr((string) $leak['subject'], 0, 255)], $userId);
		$added = 0;

		try {
			switch ($leak['rule_id']) {
				case 'BL101':
				case 'BL102':
					$this->campaignCriterionAdd($project, $userId, (int) $leak['campaign_id'], 'negative_keyword', ['phrases' => (string) $leak['subject'], 'match_type' => 'EXACT'], $setId);
					$added++;
					break;
				case 'BL201':
					foreach ($this->keywordsByText($project, (int) $leak['campaign_id'], [(string) $leak['subject']]) as $keywordId) {
						$this->keywordStatus($project, $userId, $keywordId, 'PAUSED', $setId);
						$added++;
					}
					break;
				case 'BL304':
					$this->campaignUpdate($project, $userId, (int) $leak['campaign_id'], ['target_search_network' => false, 'target_content_network' => false], $setId);
					$added++;
					break;
			}
		} catch (ValidationFailed $e) {
			$this->abandon($project, $setId, $userId, $e);

			throw $e;
		}

		if ($added === 0) {
			$this->abandon($project, $setId, $userId, null);

			throw new ValidationFailed(['leak' => 'Nie znaleziono aktywnego zasobu z identyfikatorem Google Ads dla tego wycieku – zsynchronizuj konto przez API.']);
		}

		return $setId;
	}

	/**
	 * Pusty szkic, którego nie udało się wypełnić, jest anulowany (zostaje w historii z powodem).
	 */
	private function abandon(Project $project, int $setId, int $userId, ?ValidationFailed $e): void
	{
		if ($this->sets->operations($setId) !== []) {
			return; // częściowo wypełniony szkic zostaje – użytkownik zobaczy go w Change Center
		}

		$this->sets->transition($setId, [ChangeStatus::DRAFT], ChangeStatus::CANCELLED);
		$this->sets->addEvent($setId, $project->id, 'cancelled', $userId, ChangeStatus::DRAFT, ChangeStatus::CANCELLED, 'Nie udało się przygotować operacji: ' . ($e !== null ? (string) (array_values($e->errors)[0] ?? $e->getMessage()) : 'brak danych z Google Ads.'));
	}

	/**
	 * Działanie z Action Plan: wszystkie powiązane rekomendacje, które da się wdrożyć – w jednym szkicu.
	 */
	public function fromActionItem(Project $project, int $userId, array $item): int
	{
		if (($existing = $this->sets->openForSource($project->id, 'action_item', (int) $item['id'])) !== null) {
			return (int) $existing['id'];
		}

		$all = array_values(array_filter(array_map(fn ($id) => $this->recommendations->find($project->id, (int) $id), (array) $item['recommendation_ids'])));
		$blockers = [];
		$recs = [];

		foreach ($all as $rec) {
			if (($blocker = $this->recommendationBlocker($project, $rec)) === null) {
				$recs[] = $rec;
			} elseif (self::deployable($rec) === null) {
				$blockers[] = $blocker;
			}
		}

		if ($recs === []) {
			throw new ValidationFailed(['action' => $blockers !== [] ? implode(' ', array_values(array_unique($blockers))) : 'To działanie wymaga pracy poza Google Ads API – Penny Pincher nie tworzy dla niego operacji.']);
		}

		$setId = $this->center->createSet($project, ['title' => 'Action Plan: ' . mb_substr($item['title'], 0, 230), 'description' => $item['description'], 'source_type' => 'action_item', 'source_id' => (int) $item['id'], 'source_label' => $item['title']], $userId);
		$ok = 0;

		$failures = [];

		foreach ($recs as $rec) {
			try {
				$this->fromRecommendation($project, $userId, (int) $rec['id'], $setId, 'action_item', (int) $item['id']);
				$ok++;
			} catch (ValidationFailed $e) {
				// pomijamy rekomendację bez danych API lub nieobsługiwaną przez API; pozostałe zostają w szkicu
				$failures[] = (string) (array_values($e->errors)[0] ?? $e->getMessage());
			}
		}

		if ($ok === 0) {
			$this->abandon($project, $setId, $userId, null);

			throw new ValidationFailed(['action' => $failures !== [] ? implode(' ', array_values(array_unique($failures))) : 'Brak danych z Google Ads do przygotowania operacji – zsynchronizuj konto przez API.']);
		}

		return $setId;
	}

	/**
	 * Wykluczenia wybrane z propozycji Keyword Opportunities (zakładka „Wykluczenia”).
	 *
	 * @param array<int, int> $suggestionIds
	 */
	public function fromNegativeSuggestions(Project $project, int $userId, int $runId, array $suggestionIds): int
	{
		$rows = array_values(array_filter($this->analyses->negativeSuggestions($runId), fn ($row) => in_array((int) $row['id'], array_map('intval', $suggestionIds), true) && (int) $row['project_id'] === $project->id));

		if ($rows === []) {
			throw new ValidationFailed(['negatives' => 'Zaznacz propozycje wykluczeń.']);
		}

		$setId = $this->center->createSet($project, ['title' => 'Wykluczenia z Keyword Opportunities (' . count($rows) . ')', 'source_type' => 'negative_suggestion', 'source_id' => $runId], $userId);
		$added = 0;

		try {
			foreach ($rows as $row) {
				if ($row['level'] === 'campaign' && $row['campaign_id'] !== null && ! (int) $row['already_excluded']) {
					$this->campaignCriterionAdd($project, $userId, (int) $row['campaign_id'], 'negative_keyword', ['phrases' => $row['phrase'], 'match_type' => $row['match_type']], $setId);
					$added++;
				}
			}
		} catch (ValidationFailed $e) {
			$this->abandon($project, $setId, $userId, $e);

			throw $e;
		}

		if ($added === 0) {
			$this->abandon($project, $setId, $userId, null);

			throw new ValidationFailed(['negatives' => 'Wybrane propozycje dotyczą list wykluczeń konta albo są już wykluczone – Penny Pincher obsługuje wykluczenia na poziomie kampanii i grupy reklam.']);
		}

		return $setId;
	}

	// --- Zasoby lokalne → resource names ------------------------------------------------------------

	public function campaign(Project $project, int $campaignId): array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? AND id = ?', [$project->id, $campaignId]) ?? throw new ValidationFailed(['campaign' => 'Nie znaleziono kampanii.']);
		$customer = $this->customer($project);

		if ($row['external_id'] === null || $row['external_id'] === '') {
			throw new ValidationFailed(['campaign' => 'Kampania pochodzi z importu i nie ma identyfikatora Google Ads – połącz konto i zsynchronizuj dane przez API.']);
		}

		$channel = mb_strtolower((string) $row['channel_type']);

		return $row + [
			'customer_id' => $customer,
			'resource_name' => ResourceName::campaign($customer, (string) $row['external_id']),
			'channel' => str_contains($channel, 'search') || str_contains($channel, 'wyszuk') ? 'search' : $channel,
		];
	}

	public function adGroup(Project $project, int $adGroupId): array
	{
		$row = $this->db->first('SELECT g.*, c.name AS campaign_name, c.external_id AS campaign_external_id FROM ' . $this->db->table('ad_groups') . ' g INNER JOIN ' . $this->db->table('campaigns') . ' c ON c.id = g.campaign_id WHERE g.project_id = ? AND g.id = ?', [$project->id, $adGroupId])
			?? throw new ValidationFailed(['ad_group' => 'Nie znaleziono grupy reklam.']);

		if ($row['external_id'] === null || $row['external_id'] === '') {
			throw new ValidationFailed(['ad_group' => 'Grupa reklam pochodzi z importu i nie ma identyfikatora Google Ads – zsynchronizuj konto przez API.']);
		}

		$customer = $this->customer($project);

		return $row + ['customer_id' => $customer, 'resource_name' => ResourceName::adGroup($customer, (string) $row['external_id'])];
	}

	public function keyword(Project $project, int $keywordId): array
	{
		$row = $this->db->first('SELECT k.*, g.name AS ad_group_name, g.external_id AS ad_group_external_id FROM ' . $this->db->table('keywords') . ' k INNER JOIN ' . $this->db->table('ad_groups') . ' g ON g.id = k.ad_group_id WHERE k.project_id = ? AND k.id = ?', [$project->id, $keywordId])
			?? throw new ValidationFailed(['keyword' => 'Nie znaleziono słowa kluczowego.']);

		if (! $row['external_id'] || ! $row['ad_group_external_id']) {
			throw new ValidationFailed(['keyword' => 'Słowo pochodzi z importu i nie ma identyfikatora Google Ads – zsynchronizuj konto przez API.']);
		}

		$customer = $this->customer($project);

		return $row + [
			'customer_id' => $customer,
			'resource_name' => ResourceName::keyword($customer, (string) $row['ad_group_external_id'], (string) $row['external_id']),
			'ad_group_resource_name' => ResourceName::adGroup($customer, (string) $row['ad_group_external_id']),
		];
	}

	public function conversionAction(Project $project, int $actionId): array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('conversion_actions') . ' WHERE project_id = ? AND id = ?', [$project->id, $actionId]) ?? throw new ValidationFailed(['conversion' => 'Nie znaleziono działania konwersji.']);

		if (! $row['external_id']) {
			throw new ValidationFailed(['conversion' => 'Działanie pochodzi z importu i nie ma identyfikatora Google Ads – zsynchronizuj konto przez API.']);
		}

		return $row + ['resource_name' => ResourceName::conversionAction($this->customer($project), (string) $row['external_id'])];
	}

	/**
	 * @return array<int, string>
	 */
	public static function phrases(mixed $input): array
	{
		$lines = is_array($input) ? $input : (preg_split('/[\r\n]+/u', (string) $input) ?: []);
		$out = [];

		foreach ($lines as $line) {
			$line = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $line)));
			$line = trim($line, '[]"+ ');

			if ($line !== '') {
				$out[mb_strtolower($line)] = mb_strtolower($line);
			}
		}

		return array_values(array_slice($out, 0, 200));
	}

	// --- Pomocnicze -----------------------------------------------------------------------------

	/**
	 * Formularz ręczny jako jedna całość: jeśli przygotowanie się nie powiedzie albo nie powstała żadna operacja,
	 * nowo utworzony pusty szkic jest anulowany (z powodem w historii) – w Change Center nie zostają puste zestawy.
	 */
	public function atomically(Project $project, int $userId, \Closure $build): int
	{
		$this->created = [];

		try {
			$id = $build();
		} catch (\Throwable $e) {
			foreach ($this->created as $created) {
				$this->abandon($project, $created, $userId, $e instanceof ValidationFailed ? $e : null);
			}

			throw $e;
		}

		foreach ($this->created as $created) {
			if ($this->sets->operations($created) === []) {
				$this->abandon($project, $created, $userId, null);

				throw new ValidationFailed(['change' => 'Nie przygotowano żadnej operacji – uzupełnij formularz (np. wpisz słowa lub wykluczenia).']);
			}
		}

		return $id;
	}

	/** @var array<int, int> zestawy utworzone w bieżącym wywołaniu atomically() */
	private array $created = [];

	private function target(Project $project, int $userId, ?int $setId, string $title, string $sourceType = 'manual', ?int $sourceId = null, ?string $sourceLabel = null): int
	{
		if ($setId !== null) {
			$set = $this->center->find($project, $setId);

			if (! in_array($set['status'], ChangeStatus::EDITABLE, true)) {
				throw new ValidationFailed(['change_set' => 'Wybrany zestaw nie jest już edytowalny – utwórz nowy.']);
			}

			return $setId;
		}

		$id = $this->center->createSet($project, ['title' => mb_substr($title, 0, 255), 'source_type' => $sourceType, 'source_id' => $sourceId, 'source_label' => $sourceLabel], $userId);
		$this->created[] = $id;

		return $id;
	}

	private function customer(Project $project): string
	{
		$connection = $this->connections->currentForProject($project->id);

		if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE || ! $connection['customer_id']) {
			throw new ValidationFailed(['connection' => 'Połącz projekt z kontem Google Ads, aby przygotowywać zmiany.']);
		}

		return (string) $connection['customer_id'];
	}

	private function activeConnection(Project $project): array
	{
		$this->customer($project);

		return $this->connections->currentForProject($project->id);
	}

	private function campaignSyncState(array $campaign): array
	{
		$settings = json_decode((string) ($campaign['settings'] ?? ''), true) ?: [];

		return array_filter([
			'name' => $campaign['name'],
			'status' => strtoupper((string) $campaign['status']) ?: null,
			'channel_type' => $campaign['channel'] === 'search' ? 'SEARCH' : strtoupper((string) $campaign['channel_type']),
			'target_search_network' => isset($settings['networks']['search_partners']) ? (bool) $settings['networks']['search_partners'] : null,
			'target_content_network' => isset($settings['networks']['display']) ? (bool) $settings['networks']['display'] : null,
		], fn ($v) => $v !== null);
	}

	/**
	 * @param array<int, string> $phrases
	 * @return array<int, array<string, mixed>>
	 */
	private function negativeSuggestions(Project $project, array $phrases): array
	{
		$run = $this->analyses->latestRun($project->id, KeywordOpportunityService::TYPE);

		if ($run === null) {
			return [];
		}

		$wanted = array_flip(array_map('mb_strtolower', $phrases));

		return array_values(array_filter($this->analyses->negativeSuggestions((int) $run['id']), fn ($row) => isset($wanted[mb_strtolower((string) $row['phrase'])]) && ! (int) $row['already_excluded']));
	}

	/**
	 * Przedmiot (hasło/słowo) wycieku dla rekomendacji z pojedynczego wycieku.
	 *
	 * @return array<int, string>
	 */
	private function leakSubjects(Project $project, array $rec): array
	{
		return array_values(array_unique(array_map('strval', $this->db->column(
			'SELECT subject FROM ' . $this->db->table('budget_leaks') . ' WHERE project_id = ? AND rule_id = ? AND title = ? ORDER BY id DESC LIMIT 5',
			[$project->id, strtoupper(substr($rec['type'], 5)), $rec['title']]
		))));
	}

	/**
	 * @param array<int, string> $texts
	 * @return array<int, int>
	 */
	private function keywordsByText(Project $project, int $campaignId, array $texts): array
	{
		if ($texts === []) {
			return [];
		}

		return array_map('intval', $this->db->column(
			'SELECT id FROM ' . $this->db->table('keywords') . ' WHERE project_id = ? AND campaign_id = ? AND external_id IS NOT NULL AND LOWER(text) IN (' . implode(',', array_fill(0, count($texts), '?')) . ') AND status = ?',
			[$project->id, $campaignId, ...array_map('mb_strtolower', $texts), 'enabled']
		));
	}

	/**
	 * Encje problemu audytu (ostatni audyt) dla rekomendacji z reguły audytu.
	 *
	 * @return array<int, int>
	 */
	private function findingEntities(Project $project, array $rec, string $entityType): array
	{
		return array_values(array_unique(array_map('intval', $this->db->column(
			'SELECT entity_id FROM ' . $this->db->table('audit_findings') . ' WHERE project_id = ? AND rule_id = ? AND entity_type = ? AND title = ? AND entity_id IS NOT NULL ORDER BY id DESC LIMIT 10',
			[$project->id, strtoupper(substr($rec['type'], 6)), $entityType, $rec['title']]
		))));
	}
}
