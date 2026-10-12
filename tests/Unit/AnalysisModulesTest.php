<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Analysis\Statistics;
use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Conversions\ConversionClass;
use App\PennyPincher\Domain\Keywords\KeywordAnalyzer;
use App\PennyPincher\Domain\Keywords\OpportunityScore;
use App\PennyPincher\Domain\Keywords\SeedSelector;
use App\PennyPincher\Domain\Leaks\LeakContext;
use App\PennyPincher\Domain\Leaks\LeakDetector;
use App\PennyPincher\Domain\Leaks\SavingsEstimate;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoClient;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoException;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoHttp;
use App\PennyPincher\Support\Format;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/**
 * Czyste funkcje modułów analizy: okres, statystyka, Budget Leaks, Opportunity Score, słowa kluczowe,
 * rekomendacje i parsowanie odpowiedzi DataForSEO.
 */
class AnalysisModulesTest extends TestCase
{
	private const M = 1_000_000;

	// --- Okres analizy --------------------------------------------------------------------------

	public function testPresetsEndYesterdayAndPreviousPeriodHasSameLength(): void
	{
		$tz = new DateTimeZone('Europe/Warsaw');
		$today = new DateTimeImmutable('2026-10-12', $tz);

		foreach (['last_30' => 30, 'last_90' => 90] as $preset => $days) {
			$period = AnalysisPeriod::fromPreset($preset, $tz, $today);
			$this->assertSame('2026-10-11', $period->range->toString());
			$this->assertSame($days, $period->days());
			$previous = $period->previous();
			$this->assertSame($days, (int) (new DateTimeImmutable($previous->fromString()))->diff(new DateTimeImmutable($previous->toString()))->days + 1);
			$this->assertSame($period->range->fromString(), (new DateTimeImmutable($previous->toString()))->modify('+1 day')->format('Y-m-d'));
		}

		$this->assertSame('last_30', AnalysisPeriod::fromPreset('nonsense', $tz, $today)->preset, 'Nieznany preset → domyślny.');
		$this->assertTrue(AnalysisPeriod::fromPreset('last_365', $tz, $today)->days() >= 365);
	}

	public function testCustomRangeIsValidated(): void
	{
		$tz = new DateTimeZone('Europe/Warsaw');
		$today = new DateTimeImmutable('2026-10-12', $tz);

		$this->assertSame(10, AnalysisPeriod::custom('2026-03-01', '2026-03-10', $tz, $today)->days());
		$this->assertSame('2026-03-01', AnalysisPeriod::custom('2026-03-10', '2026-03-01', $tz, $today)->range->fromString(), 'Odwrócone daty są porządkowane.');
		$this->assertSame('2026-10-12', AnalysisPeriod::custom('2026-10-01', '2026-12-31', $tz, $today)->range->toString(), 'Okres nie sięga w przyszłość.');
		$long = AnalysisPeriod::custom('2020-01-01', '2026-03-01', $tz, $today);
		$this->assertSame(AnalysisPeriod::MAX_DAYS, $long->days(), 'Za długi zakres jest skracany od początku.');
		$this->assertSame('2026-03-01', $long->range->toString());
		$this->assertNull(AnalysisPeriod::custom('abc', '2026-03-01', $tz, $today));
		$this->assertNull(AnalysisPeriod::custom("2026-03-01' OR 1=1", '2026-03-02', $tz, $today));
	}

	// --- Statystyka ---------------------------------------------------------------------------

	public function testZeroConversionConfidenceDependsOnSampleSize(): void
	{
		$this->assertSame(Statistics::LOW, Statistics::confidenceForZero(3, 0.05), 'Kilka kliknięć to za mało na wniosek.');
		$this->assertSame(Statistics::HIGH, Statistics::confidenceForZero(80, 0.08));
		$this->assertSame(Statistics::LOW, Statistics::confidenceForZero(80, null), 'Bez punktu odniesienia – niska pewność.');
		$this->assertEqualsWithDelta(0.95 ** 20, Statistics::zeroConversionProbability(20, 0.05), 0.0001);
	}

	// --- Budget Leaks -------------------------------------------------------------------------

	public function testFewClicksWithoutConversionsAreNotALeak(): void
	{
		$leaks = (new LeakDetector())->detect($this->leakContext([
			$this->term(1, 'sofa narożna szara', clicks: 4, cost: 30 * self::M, conversions: 0.0),
		]));

		$this->assertSame([], array_values(array_filter(array_map(fn ($l) => $l->ruleId, $leaks), fn ($r) => str_starts_with($r, 'BL1'))));
	}

	public function testCostlyTermWithoutConversionsIsDetectedWithConfidence(): void
	{
		$leaks = (new LeakDetector())->detect($this->leakContext([
			$this->term(1, 'sofa narożna szara', clicks: 60, cost: 180 * self::M, conversions: 0.0),
			$this->term(2, 'sofa na wymiar', clicks: 5, cost: 15 * self::M, conversions: null),
		]));
		$terms = array_values(array_filter($leaks, fn ($l) => $l->ruleId === 'BL101'));

		$this->assertCount(1, $terms, 'Hasło z brakiem danych o konwersjach (null) nie jest oceniane.');
		$this->assertSame(Statistics::HIGH, $terms[0]->confidence);
		$this->assertSame('sofa narożna szara', $terms[0]->subject);
		$this->assertTrue($terms[0]->savingsMicros > 0);
	}

	public function testLowIntentTermIsFlaggedAndExcludedAudienceFromContextIsUsed(): void
	{
		$context = new BusinessContext(['excluded_audiences' => "hurtownie\n"]);
		$leaks = (new LeakDetector())->detect($this->leakContext([
			$this->term(1, 'praca tapicer warszawa', clicks: 6, cost: 25 * self::M, conversions: 0.0),
			$this->term(2, 'sofy hurtownie', clicks: 2, cost: 8 * self::M, conversions: 0.0),
		], $context));
		$low = array_values(array_filter($leaks, fn ($l) => $l->ruleId === 'BL102'));

		$this->assertCount(2, $low);
		$this->assertSame([Statistics::MEDIUM, Statistics::MEDIUM], array_map(fn ($l) => $l->confidence, $low));
	}

	public function testNoSpendMeansNoLeaks(): void
	{
		$context = $this->leakContext([], null, campaignCost: 0, campaignClicks: 0, conversions: 0.0);
		$this->assertSame([], (new LeakDetector())->detect($context));
	}

	public function testOverlappingSavingsAreNotSummedAndCappedBySpend(): void
	{
		$leaks = [
			['rule_id' => 'BL101', 'campaign_id' => 1, 'savings_micros' => 100, 'status' => 'new', 'evidence' => ['terms' => [10 => [1, 100]]]],
			['rule_id' => 'BL103', 'campaign_id' => null, 'savings_micros' => 250, 'status' => 'new', 'evidence' => ['terms' => [10 => [1, 100], 11 => [1, 150]]]],
			['rule_id' => 'BL201', 'campaign_id' => 1, 'savings_micros' => 200, 'status' => 'new', 'evidence' => ['terms' => []]],
			['rule_id' => 'BL301', 'campaign_id' => 1, 'savings_micros' => 900, 'status' => 'new', 'evidence' => ['terms' => []]],
			['rule_id' => 'BL101', 'campaign_id' => 2, 'savings_micros' => 70, 'status' => 'dismissed', 'evidence' => ['terms' => [20 => [2, 70]]]],
			['rule_id' => 'BL104', 'campaign_id' => 2, 'savings_micros' => null, 'status' => 'new', 'evidence' => ['terms' => [21 => [2, 50]]]],
		];

		$result = SavingsEstimate::total($leaks, [1 => 600, 2 => 1000]);

		$this->assertSame([1 => 600], $result['by_campaign'], 'Maksimum (900) ograniczone wydatkami (600); pominięte i bez szacunku nie liczą się.');
		$this->assertSame(600, $result['total']);
		$this->assertSame(250, SavingsEstimate::total(array_slice($leaks, 0, 2), [])['total'], 'To samo hasło liczone raz.');
	}

	// --- Opportunity Score --------------------------------------------------------------------

	public function testOpportunityScoreGivesNoPointsForMissingData(): void
	{
		$empty = OpportunityScore::calculate(['offer_fit' => null, 'intent' => null, 'cpc' => null, 'volume' => null, 'coverage' => null]);
		$this->assertSame(0, $empty['score']);
		$this->assertSame('low', $empty['confidence']);
		$this->assertCount(5, $empty['missing']);

		$full = OpportunityScore::calculate(['offer_fit' => 1, 'intent' => 1, 'cpc' => 1, 'volume' => 1, 'coverage' => 1]);
		$this->assertSame(100, $full['score']);
		$this->assertSame('high', $full['confidence']);
		$this->assertSame(100, array_sum(OpportunityScore::WEIGHTS));

		$this->assertNull(OpportunityScore::cpc(2 * self::M, 'USD', 2 * self::M, 'PLN'), 'Różne waluty nie są porównywane.');
		$this->assertNull(OpportunityScore::volume(null));
		$this->assertTrue(OpportunityScore::intent('commercial') > OpportunityScore::intent('informational'));
	}

	// --- Słowa kluczowe -----------------------------------------------------------------------

	public function testOpportunitiesAreDedupedAndSkipExistingKeywordsAndNegatives(): void
	{
		$analyzer = $this->analyzer(
			terms: [
				$this->term(1, 'Sofa Narożna Szara', clicks: 30, cost: 60 * self::M, conversions: 3.0),
				$this->term(2, 'sofa narozna szara', clicks: 5, cost: 10 * self::M, conversions: 1.0),
				$this->term(3, 'sofa rozkładana', clicks: 20, cost: 40 * self::M, conversions: 2.0),
				$this->term(4, 'sofa skórzana', clicks: 20, cost: 40 * self::M, conversions: 2.0),
			],
			keywords: [['id' => 1, 'text' => 'sofa rozkładana', 'status' => 'enabled', 'campaign_id' => 1, 'ad_group_id' => 1, 'match_type' => 'phrase']],
			negatives: [['level' => 'campaign', 'campaign_id' => 1, 'ad_group_id' => null, 'text' => 'skórzana', 'match_type' => 'broad']],
			ideas: [['keyword' => 'sofa narożna szara', 'seed' => null, 'search_volume' => 1200, 'cpc_micros' => 800_000, 'competition' => 'HIGH', 'source' => 'keywords_for_keywords', 'fetched_at' => '2026-10-10 10:00:00']],
		);

		$phrases = array_map(fn ($o) => Text::normalize($o['phrase']), $analyzer->opportunities());

		$this->assertSame(['sofa narozna szara'], $phrases, 'Duplikaty połączone, istniejące słowo i wykluczona fraza pominięte.');
		$opportunity = $analyzer->opportunities()[0];
		$this->assertSame('search_terms,dataforseo', $opportunity['sources']);
		$this->assertSame(1200, $opportunity['search_volume']);
		$this->assertSame('PLN', $opportunity['cpc_currency'], 'CPC z danych konta ma pierwszeństwo przed USD z DataForSEO.');
	}

	public function testNegativeSuggestionsMarkAlreadyExcludedPhrases(): void
	{
		$analyzer = $this->analyzer(
			terms: [
				$this->term(1, 'praca tapicer', clicks: 12, cost: 30 * self::M, conversions: 0.0),
				$this->term(2, 'darmowe meble', clicks: 3, cost: 9 * self::M, conversions: 0.0),
				$this->term(3, 'jak zrobić sofę', clicks: 2, cost: 3 * self::M, conversions: 0.0),
			],
			negatives: [['level' => 'account', 'campaign_id' => null, 'ad_group_id' => null, 'text' => 'praca', 'match_type' => 'phrase']],
		);

		$rows = [];

		foreach ($analyzer->negatives(20 * self::M) as $row) {
			$rows[$row['phrase']] = $row;
		}

		$this->assertSame(1, (int) $rows['praca']['already_excluded']);
		$this->assertSame('jobs', $rows['praca']['reason_code']);
		$this->assertSame(Statistics::HIGH, $rows['praca']['confidence']);
		$this->assertSame(0, (int) ($rows['darmowe']['already_excluded'] ?? 0));
		$this->assertFalse(isset($rows['jak']), 'Tanie zapytanie informacyjne nie jest proponowane do wykluczenia.');
	}

	public function testSeedSelectorUsesPriorityServicesAndSkipsBlockedIntents(): void
	{
		$context = new BusinessContext(['priority_services' => "Sofy na wymiar\nsofy na wymiar\n"]);
		$terms = [
			$this->term(1, 'praca tapicer', clicks: 5, cost: 1, conversions: 2.0),
			$this->term(2, 'sofa narożna', clicks: 5, cost: 1, conversions: 3.0),
		];
		$seeds = SeedSelector::select($context, $terms, [], 20);

		$this->assertSame(['sofy na wymiar', 'sofa narożna'], array_column($seeds, 'phrase'));

		$many = array_map(fn ($i) => $this->term($i, 'fraza numer ' . $i, clicks: 5, cost: 1, conversions: 1.0), range(1, 40));
		$this->assertCount(DataForSeoClient::MAX_SEEDS, SeedSelector::select(new BusinessContext(), $many, []));
	}

	// --- Konwersje ----------------------------------------------------------------------------

	public function testContactClicksAreNotConfirmedLeads(): void
	{
		$this->assertSame(ConversionClass::PotentialLead, ConversionClass::suggestFromCategory('CONTACT'));
		$this->assertSame(ConversionClass::PotentialLead, ConversionClass::suggestFromCategory('PHONE_CALL_LEAD'));
		$this->assertFalse(ConversionClass::PotentialLead->isLead());
		$this->assertSame(ConversionClass::Micro, ConversionClass::suggestFromCategory('PAGE_VIEW'));
		$this->assertSame(ConversionClass::Unclassified, ConversionClass::suggestFromCategory(null));
	}

	// --- Rekomendacje -------------------------------------------------------------------------

	public function testRecommendationsDedupeAcrossSourcesAndGroupSimilarIssues(): void
	{
		$generator = new RecommendationGenerator([1 => 'Szukaj | Sofy'], [], 'PLN');
		$finding = ['rule_id' => 'PF004', 'status' => 'new', 'severity' => 'warning', 'entity_type' => 'campaign', 'entity_id' => 1, 'fingerprint' => sha1('f'), 'title' => 'Kampania bez konwersji', 'description' => 'Opis', 'evidence' => [['Wydatki', '500 zł']], 'recommendation' => 'Sprawdź', 'category' => 'performance'];
		$leak = fn (int $id, string $rule, int $score = 50, string $status = 'new') => [
			'id' => $id, 'rule_id' => $rule, 'status' => $status, 'confidence' => Statistics::HIGH, 'priority' => 'medium', 'priority_score' => $score,
			'campaign_id' => 1, 'ad_group_id' => null, 'fingerprint' => sha1($rule . $id), 'title' => "Problem {$id}", 'reason' => 'Powód', 'action' => 'Działanie',
			'subject' => "hasło {$id}", 'cost_micros' => 50 * self::M, 'clicks' => 30, 'savings_micros' => 10 * self::M, 'evidence' => ['pairs' => [], 'terms' => []],
		];

		$candidates = $generator->generate([$finding], [
			$leak(1, 'BL301', 68),
			$leak(2, 'BL101'), $leak(3, 'BL101'), $leak(4, 'BL104'),
			$leak(5, 'BL101', 90, 'dismissed'),
		], [], [
			['phrase' => 'praca', 'reason_code' => 'jobs', 'reason' => 'Praca', 'already_excluded' => 1, 'cost_micros' => 1, 'clicks' => 1, 'confidence' => Statistics::HIGH, 'level' => 'account', 'match_type' => 'phrase'],
		]);

		$this->assertCount(2, $candidates, 'PF004 + BL301 to jedna rekomendacja, 3 hasła w jednej grupie, już wykluczone pominięte.');
		$merged = $candidates[sha1('campaign:1:no_conversions')];
		$this->assertSame(['audit', 'budget_leaks'], $merged['sources']);
		$this->assertSame(73, $merged['priority_score'], 'Wyższy priorytet + premia za potwierdzenie w drugim źródle.');
		$group = $candidates[sha1('group:terms:1')];
		$this->assertTrue(str_contains($group['title'], '3 zapytania'), $group['title']);
		$this->assertSame(30 * self::M, $group['impact_micros'], 'Pominięty problem nie wchodzi do grupy.');
	}

	// --- DataForSEO ---------------------------------------------------------------------------

	public function testDataForSeoErrorsAreClassified(): void
	{
		$cases = [
			[200, ['status_code' => 40100], DataForSeoException::AUTH, false],
			[200, ['status_code' => 40210], DataForSeoException::FUNDS, false],
			[402, null, DataForSeoException::FUNDS, false],
			[200, ['status_code' => 40203], DataForSeoException::COST_LIMIT, false],
			[200, ['status_code' => 40202], DataForSeoException::RATE, true],
			[200, ['status_code' => 20000, 'tasks' => [['status_code' => 40501, 'status_message' => 'Invalid Field']]], DataForSeoException::INVALID, false],
			[200, ['status_code' => 50000], DataForSeoException::TRANSIENT, true],
			[500, null, DataForSeoException::TRANSIENT, true],
			[200, ['status_code' => 20000], DataForSeoException::RESPONSE, false],
			[200, null, DataForSeoException::RESPONSE, false],
		];

		foreach ($cases as [$http, $body, $kind, $retry]) {
			$e = $this->assertThrows(DataForSeoException::class, fn () => DataForSeoHttp::check($http, $body));
			$this->assertSame($kind, $e->kind, json_encode($body));
			$this->assertSame($retry, $e->retryable());
		}

		$ok = DataForSeoHttp::check(200, ['status_code' => 20000, 'cost' => 0.09, 'tasks' => [['status_code' => 20000, 'result' => []]]]);
		$this->assertSame(0.09, $ok['cost']);
	}

	public function testDataForSeoItemsAreParsedWithoutInventingValues(): void
	{
		$items = DataForSeoClient::parseKeywordItems(['tasks' => [['result' => [
			['keyword' => 'sofa na wymiar', 'search_volume' => 880, 'cpc' => 1.25, 'competition' => 'MEDIUM', 'competition_index' => 54, 'low_top_of_page_bid' => 0.4, 'high_top_of_page_bid' => 2.1],
			['keyword' => 'sofa tapicerowana', 'search_volume' => null, 'cpc' => null, 'competition' => 0.5, 'competition_index' => 'x'],
			['keyword' => '', 'search_volume' => 10],
			'nonsense',
		]]]]);

		$this->assertCount(2, $items);
		$this->assertSame(1_250_000, $items[0]['cpc_micros']);
		$this->assertSame('MEDIUM', $items[0]['competition']);
		$this->assertSame(['sofa tapicerowana', null, null, null, null], [$items[1]['keyword'], $items[1]['search_volume'], $items[1]['cpc_micros'], $items[1]['competition'], $items[1]['competition_index']]);
		$this->assertThrows(DataForSeoException::class, fn () => DataForSeoClient::parseKeywordItems(['tasks' => [['result' => 'x']]]));
	}

	public function testKeywordsForKeywordsPayloadIsOneTaskWithLimits(): void
	{
		$seeds = array_map(fn ($i) => 'fraza ' . $i, range(1, 30));
		$seeds[] = 'FRAZA 1';
		$seeds[] = str_repeat('a', 200);
		$payload = DataForSeoClient::keywordsForKeywordsPayload($seeds, 2616, 'pl');

		$this->assertCount(1, $payload);
		$this->assertCount(20, $payload[0]['keywords']);
		$this->assertSame(2616, $payload[0]['location_code']);
		$this->assertSame('pl', $payload[0]['language_code']);
		$this->assertTrue(max(array_map('mb_strlen', $payload[0]['keywords'])) <= 80);
		$this->assertSame($payload, DataForSeoClient::keywordsForKeywordsPayload(array_reverse($seeds), 2616, 'pl'), 'Kolejność fraz nie zmienia zapytania (cache, idempotencja).');
	}

	public function testPolishPlural(): void
	{
		$this->assertSame(['fraza', 'frazy', 'fraz', 'fraz', 'frazy'], array_map(fn ($n) => Format::plural($n, 'fraza', 'frazy', 'fraz'), [1, 3, 5, 12, 22]));
	}

	// --- Pomocnicze ---------------------------------------------------------------------------

	private function term(int $id, string $text, int $clicks, int $cost, ?float $conversions, int $campaignId = 1): array
	{
		return ['id' => $id, 'term' => $text, 'campaign_id' => $campaignId, 'ad_group_id' => 1, 'keyword_text' => null, 'match_type' => 'phrase', 'added_excluded' => null, 'impressions' => $clicks * 10, 'clicks' => $clicks, 'cost_micros' => $cost, 'conversions' => $conversions];
	}

	private function leakContext(array $terms, ?BusinessContext $context = null, int $campaignCost = 3000 * self::M, int $campaignClicks = 1000, float $conversions = 80.0): LeakContext
	{
		$tz = new DateTimeZone('Europe/Warsaw');
		$campaign = ['id' => 1, 'name' => 'Szukaj | Sofy', 'status' => 'enabled', 'channel_type' => 'search', 'bidding_strategy' => null, 'settings' => [],
			'current' => ['cost_micros' => $campaignCost, 'clicks' => $campaignClicks, 'conversions' => $conversions, 'impressions' => $campaignClicks * 10], 'previous' => []];

		return new LeakContext(
			currency: 'PLN',
			period: AnalysisPeriod::fromPreset('last_30', $tz, new DateTimeImmutable('2026-10-12', $tz)),
			campaigns: [1 => $campaign],
			adGroups: [1 => ['id' => 1, 'name' => 'Sofy', 'campaign_id' => 1, 'status' => 'enabled']],
			terms: $terms,
			keywords: [],
			breakdown: ['actions' => [], 'account' => [], 'campaigns' => [], 'campaign_level_available' => false],
			negatives: [],
			context: $context ?? new BusinessContext(),
		);
	}

	private function analyzer(array $terms = [], array $keywords = [], array $negatives = [], array $ideas = []): KeywordAnalyzer
	{
		return new KeywordAnalyzer(
			$terms, $keywords, $negatives,
			[1 => ['id' => 1, 'name' => 'Sofy', 'campaign_id' => 1, 'status' => 'enabled']],
			[1 => 'Szukaj | Sofy'],
			new BusinessContext(['products_services' => 'sofy narożniki meble tapicerowane']),
			$ideas, 2 * self::M, 'PLN', 30,
		);
	}
}
