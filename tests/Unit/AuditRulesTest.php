<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\AuditScore;
use App\PennyPincher\Domain\Audit\Finding;
use App\PennyPincher\Domain\Audit\Rule;
use App\PennyPincher\Domain\Audit\RuleRegistry;
use App\PennyPincher\Domain\Audit\Rules;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\ResolvedPeriods;
use App\PennyPincher\Services\DashboardService;
use Tests\TestCase;

/**
 * Reguły audytu jako czyste funkcje: każdy test buduje kontekst i sprawdza, kiedy reguła się (nie) uruchamia.
 */
class AuditRulesTest extends TestCase
{
	private const NOW = 1791806400; // 2026-10-12 12:00 UTC

	public function testRegistryHasUniqueIdsAndCompleteMetadata(): void
	{
		$ids = [];

		foreach (RuleRegistry::all() as $rule) {
			$this->assertTrue($rule instanceof Rule);
			$this->assertTrue((bool) preg_match('/^(CT|CS|BP|DQ|PF)\d{3}$/', $rule->id()), $rule->id());
			$this->assertTrue($rule->name() !== '' && $rule->description() !== '' && $rule->category() !== '');
			$ids[] = $rule->id();
		}

		$this->assertSame(count($ids), count(array_unique($ids)));
		$this->assertSame(23, count($ids));
	}

	public function testHealthyAccountHasNoFindingsAndFullScore(): void
	{
		$context = $this->context();
		$findings = $this->evaluateAll($context);

		$this->assertSame([], array_map(fn (Finding $f) => $f->ruleId . ': ' . $f->title, $findings));
		$this->assertSame(100, AuditScore::calculate([], AuditScore::unavailableReason($context))['score']);
	}

	public function testMicroConversionAsPrimaryUsesCategoryNotName(): void
	{
		$micro = $this->context(['conversionActions' => [
			$this->action(['name' => 'Kontakt', 'category_code' => 'PAGE_VIEW', 'conversions' => 40.0]),
			$this->action(['id' => 2, 'name' => 'Formularz', 'category_code' => 'SUBMIT_LEAD_FORM', 'conversions' => 5.0]),
		]]);
		$findings = (new Rules\MicroConversionAsPrimary())->evaluate($micro);
		$this->assertCount(1, $findings);
		$this->assertTrue(str_contains($findings[0]->title, 'Kontakt'), $findings[0]->title);

		// Nazwa sugerująca mikrokonwersję, ale kategoria to lead – brak problemu.
		$named = $this->context(['conversionActions' => [$this->action(['name' => 'page view / scroll', 'category_code' => 'SUBMIT_LEAD_FORM'])]]);
		$this->assertCount(0, (new Rules\MicroConversionAsPrimary())->evaluate($named));

		// Konwersja dodatkowa (nie główna) – brak problemu.
		$secondary = $this->context(['conversionActions' => [$this->action(['category_code' => 'PAGE_VIEW', 'primary_for_goal' => false])]]);
		$this->assertCount(0, (new Rules\MicroConversionAsPrimary())->evaluate($secondary));

		// Bez ustawień z API (tylko import) reguła nie zgaduje.
		$imported = $this->context(['conversionActions' => [$this->action(['name' => 'Page view', 'category_code' => null, 'primary_for_goal' => null])]]);
		$this->assertCount(0, (new Rules\MicroConversionAsPrimary())->evaluate($imported));
	}

	public function testNoValuablePrimaryConversionIsCritical(): void
	{
		$context = $this->context(['conversionActions' => [$this->action(['category_code' => 'PAGE_VIEW'])]]);
		$findings = (new Rules\NoValuablePrimaryConversion())->evaluate($context);

		$this->assertCount(1, $findings);
		$this->assertSame(Severity::Critical, $findings[0]->severity);
	}

	public function testCampaignSettingRules(): void
	{
		$context = $this->context(['campaigns' => [$this->campaign(['settings' => [
			'networks' => ['google_search' => true, 'search_partners' => true, 'display' => true],
			'geo_target_type' => 'PRESENCE_OR_INTEREST',
			'locations' => [['name' => 'Poland', 'negative' => false]],
			'locations_synced' => true,
		]])]]);

		$this->assertSame(Severity::Info, (new Rules\SearchPartnersEnabled())->evaluate($context)[0]->severity, 'Partnerzy wyszukiwania to informacja, nie błąd.');
		$this->assertSame(Severity::Warning, (new Rules\DisplayInSearchCampaign())->evaluate($context)[0]->severity);
		$this->assertCount(1, (new Rules\LocationPresenceOrInterest())->evaluate($context));
		$this->assertCount(0, (new Rules\NoLocationTargeting())->evaluate($context));

		$noLocations = $this->context(['campaigns' => [$this->campaign(['settings' => ['locations' => [], 'locations_synced' => true]])]]);
		$this->assertCount(1, (new Rules\NoLocationTargeting())->evaluate($noLocations));

		$unknown = $this->context(['campaigns' => [$this->campaign(['settings' => []])]]);
		$this->assertCount(0, (new Rules\NoLocationTargeting())->evaluate($unknown), 'Bez synchronizacji lokalizacji nie zakładamy braku kierowania.');

		$paused = $this->context(['campaigns' => [$this->campaign(['status' => 'paused', 'settings' => ['networks' => ['display' => true, 'search_partners' => true]]])]]);
		$this->assertCount(0, (new Rules\DisplayInSearchCampaign())->evaluate($paused), 'Wstrzymane kampanie pomijamy.');
	}

	public function testSmartBiddingWithLowConversionVolume(): void
	{
		$low = $this->context(['campaigns' => [$this->campaign(['bidding_strategy' => 'TARGET_CPA', 'current' => $this->metrics(['conversions' => 3.0])])]]);
		$this->assertCount(1, (new Rules\SmartBiddingLowVolume())->evaluate($low));

		$enough = $this->context(['campaigns' => [$this->campaign(['bidding_strategy' => 'TARGET_CPA', 'current' => $this->metrics(['conversions' => 40.0])])]]);
		$this->assertCount(0, (new Rules\SmartBiddingLowVolume())->evaluate($enough));

		$manual = $this->context(['campaigns' => [$this->campaign(['bidding_strategy' => 'MANUAL_CPC', 'current' => $this->metrics(['conversions' => 1.0])])]]);
		$this->assertCount(0, (new Rules\SmartBiddingLowVolume())->evaluate($manual));
	}

	public function testMonthlyPacing(): void
	{
		$rule = new Rules\MonthlyPacingForecast();

		$noBudget = $rule->evaluate($this->context(['monthlyBudgetMicros' => null]));
		$this->assertSame(0.0, $noBudget[0]->penalty, 'Brak budżetu: informacja bez kary.');

		$over = $rule->evaluate($this->context(['monthToDate' => $this->mtd(['spend' => 3_100_000_000])]));
		$this->assertSame(Severity::Critical, $over[0]->severity);

		// 11 dni po 150 zł przy budżecie 3000 zł → prognoza 4650 zł (155%).
		$forecast = $rule->evaluate($this->context(['monthToDate' => $this->mtd(['spend' => 1_650_000_000])]));
		$this->assertSame(Severity::Critical, $forecast[0]->severity);
		$this->assertTrue(str_contains(implode(' ', array_column($forecast[0]->evidence, 0)), 'szacunek'), 'Prognoza oznaczona jako szacunek.');

		$onTrack = $rule->evaluate($this->context(['monthToDate' => $this->mtd(['spend' => 1_060_000_000])]));
		$this->assertCount(0, $onTrack);

		$incomplete = $rule->evaluate($this->context(['monthToDate' => $this->mtd(['spend' => 1_650_000_000, 'covered_days' => 6, 'complete' => false])]));
		$this->assertCount(0, $incomplete, 'Przy niepełnych danych nie prognozujemy.');

		$under = $rule->evaluate($this->context(['monthToDate' => $this->mtd(['spend' => 300_000_000])]));
		$this->assertSame(Severity::Info, $under[0]->severity);

		$pacing = DashboardService::pacing($this->project(), $this->mtd(['spend' => 1_650_000_000]));
		$this->assertSame(4_650_000_000, $pacing['forecast']);
		$this->assertEqualsWithDelta(155.0, $pacing['forecast_share'], 0.01);
	}

	public function testDailyBudgetsVersusMonthlyBudget(): void
	{
		$rule = new Rules\DailyBudgetsExceedMonthly();
		$this->assertCount(0, $rule->evaluate($this->context()), '100 zł × 30,4 = 3040 zł ≈ budżet 3000 zł.');

		$high = $this->context(['campaigns' => [$this->campaign(['budget_micros' => 250_000_000])]]);
		$this->assertSame(Severity::Warning, $rule->evaluate($high)[0]->severity);
	}

	public function testDataQualityRules(): void
	{
		$this->assertSame(Severity::Critical, (new Rules\NoCampaignData())->evaluate($this->context(['campaigns' => [$this->campaign(['current' => []])]]))[0]->severity);
		$this->assertCount(1, (new Rules\NoApiConnection())->evaluate($this->context(['connection' => null])));

		$stale = fn (int $hours) => (new Rules\StaleSync())->evaluate($this->context(['lastSyncAt' => gmdate('Y-m-d H:i:s', self::NOW - $hours * 3600)]));
		$this->assertCount(0, $stale(30));
		$this->assertSame(Severity::Warning, $stale(49)[0]->severity);
		$this->assertSame(Severity::Critical, $stale(200)[0]->severity);

		$reauth = (new Rules\ConnectionProblem())->evaluate($this->context(['connection' => ['status' => 'reauth_required', 'customer_id' => '1']]));
		$this->assertSame(Severity::Critical, $reauth[0]->severity);
		$failed = (new Rules\ConnectionProblem())->evaluate($this->context(['lastRunStatus' => 'failed', 'lastRunError' => 'QUOTA']));
		$this->assertSame(Severity::Warning, $failed[0]->severity);

		$gaps = (new Rules\CoverageGaps())->evaluate($this->context(['coverage' => $this->coverage(20)]));
		$this->assertSame(Severity::Warning, $gaps[0]->severity);

		$conflicts = (new Rules\SourceConflicts())->evaluate($this->context(['coverage' => $this->coverage(30, 4)]));
		$this->assertSame(0.0, $conflicts[0]->penalty, 'Konflikt rozwiązany automatycznie – bez kary.');

		$this->assertCount(1, (new Rules\CurrencyMismatch())->evaluate($this->context(['projectCurrencyMismatch' => 'EUR'])));
	}

	public function testPerformanceTrendsRequireSampleSize(): void
	{
		$campaign = fn (array $current, array $previous) => $this->context(['campaigns' => [$this->campaign(['current' => $this->metrics($current), 'previous' => $this->metrics($previous)])]]);

		// CPC 2 zł → 3 zł (+50%) przy 200 kliknięciach.
		$this->assertCount(1, (new Rules\CpcIncrease())->evaluate($campaign(['clicks' => 200, 'cost_micros' => 600_000_000], ['clicks' => 200, 'cost_micros' => 400_000_000])));
		// Ten sam wzrost przy 50 kliknięciach – za mała próba.
		$this->assertCount(0, (new Rules\CpcIncrease())->evaluate($campaign(['clicks' => 50, 'cost_micros' => 150_000_000], ['clicks' => 50, 'cost_micros' => 100_000_000])));

		$this->assertCount(1, (new Rules\CtrDrop())->evaluate($campaign(['impressions' => 10_000, 'clicks' => 200], ['impressions' => 10_000, 'clicks' => 400])));
		$this->assertCount(0, (new Rules\CtrDrop())->evaluate($campaign(['impressions' => 500, 'clicks' => 5], ['impressions' => 500, 'clicks' => 20])));

		$cpa = $campaign(['conversions' => 20.0, 'cost_micros' => 1_000_000_000], ['conversions' => 20.0, 'cost_micros' => 600_000_000]);
		$this->assertCount(1, (new Rules\CpaIncrease())->evaluate($cpa));

		$microCpa = $this->context([
			'campaigns' => $cpa->campaigns,
			'conversionActions' => [$this->action(['category_code' => 'PAGE_VIEW'])],
		]);
		$this->assertCount(0, (new Rules\CpaIncrease())->evaluate($microCpa), 'CPA na mikrokonwersjach nie jest oceniany.');
	}

	public function testSpendWithoutConversions(): void
	{
		$rule = new Rules\SpendWithoutConversions();
		$spend = fn (int $cost, ?float $conversions) => $this->context(['campaigns' => [$this->campaign(['current' => $this->metrics(['cost_micros' => $cost, 'conversions' => $conversions])])]]);

		$this->assertSame(Severity::Warning, $rule->evaluate($spend(400_000_000, 0.0))[0]->severity);
		$this->assertSame(Severity::Critical, $rule->evaluate($spend(900_000_000, 0.0))[0]->severity, '≥ 25% budżetu miesięcznego.');
		$this->assertCount(0, $rule->evaluate($spend(200_000_000, 0.0)), 'Poniżej progu 10% budżetu (300 zł).');
		$this->assertCount(0, $rule->evaluate($spend(900_000_000, null)), 'Brak danych o konwersjach ≠ zero konwersji.');
	}

	public function testSearchTermsWithoutConversions(): void
	{
		$terms = [
			['term' => 'darmowe meble', 'campaign' => 'A', 'clicks' => 30, 'cost_micros' => 90_000_000],
			['term' => 'meble praca', 'campaign' => 'A', 'clicks' => 12, 'cost_micros' => 30_000_000],
		];

		$findings = (new Rules\SearchTermsWithoutConversions())->evaluate($this->context(['searchTermsWithoutConversions' => $terms, 'searchTermsTotalCostMicros' => 400_000_000]));
		$this->assertCount(1, $findings);
		$this->assertTrue(str_contains($findings[0]->evidence[2][0], 'darmowe meble'), 'Najdroższe hasło jako pierwszy dowód.');

		$this->assertCount(0, (new Rules\SearchTermsWithoutConversions())->evaluate($this->context(['searchTermsWithoutConversions' => $terms, 'searchTermsTotalCostMicros' => 5_000_000_000])));
	}

	public function testScoreIsTransparentCappedAndIgnoresDismissed(): void
	{
		$finding = fn (string $severity, float $penalty, string $dimension, string $status = 'new') => ['severity' => $severity, 'penalty' => $penalty, 'score_dimension' => $dimension, 'status' => $status];

		$score = AuditScore::calculate([
			$finding('critical', 10, 'measurement'),
			$finding('critical', 10, 'measurement'),
			$finding('critical', 10, 'measurement'),
			$finding('critical', 10, 'measurement'), // 40 > pula 30 – obcięte do 30
			$finding('warning', 4, 'configuration'),
			$finding('warning', 4, 'risk', 'dismissed'),
		]);

		$this->assertSame(66, $score['score']);
		$this->assertSame(0.0, $score['dimensions']['measurement']['points']);
		$this->assertSame(21.0, $score['dimensions']['configuration']['points']);
		$this->assertSame(30.0, $score['dimensions']['risk']['points'], 'Pominięte nie obniżają wyniku.');
		$this->assertSame(100, array_sum(array_column($score['dimensions'], 'max')));

		$noData = $this->context(['campaigns' => [$this->campaign(['current' => []])]]);
		$this->assertNull(AuditScore::calculate([], AuditScore::unavailableReason($noData))['score'], 'Bez danych nie ma pseudo-precyzyjnego wyniku.');
		$this->assertNull(AuditScore::calculate([], AuditScore::unavailableReason($this->context(['coverage' => $this->coverage(10)])))['score']);
		$this->assertSame('Brak wyniku', AuditScore::label(null));
	}

	public function testFingerprintIsStableAcrossRuns(): void
	{
		$context = $this->context(['campaigns' => [$this->campaign(['settings' => ['networks' => ['display' => true]]])]]);
		$a = (new Rules\DisplayInSearchCampaign())->evaluate($context)[0];
		$b = (new Rules\DisplayInSearchCampaign())->evaluate($context)[0];

		$this->assertSame($a->fingerprint(), $b->fingerprint());
		$this->assertFalse($a->fingerprint() === (new Rules\SearchPartnersEnabled())->evaluate($this->context(['campaigns' => [$this->campaign(['settings' => ['networks' => ['search_partners' => true]]])]]))[0]->fingerprint());
	}

	// --- budowanie kontekstu --------------------------------------------------------------------

	private function evaluateAll(AuditContext $context): array
	{
		$findings = [];

		foreach (RuleRegistry::all() as $rule) {
			array_push($findings, ...$rule->evaluate($context));
		}

		return $findings;
	}

	private function context(array $overrides = []): AuditContext
	{
		$values = $overrides + [
			'currency' => 'PLN',
			'monthlyBudgetMicros' => 3_000_000_000,
			'range' => new DateRange('2026-09-12', '2026-10-11'),
			'previousRange' => new DateRange('2026-08-13', '2026-09-11'),
			'campaigns' => [$this->campaign()],
			'conversionActions' => [$this->action()],
			'coverage' => $this->coverage(30),
			'connection' => ['status' => 'active', 'customer_id' => '1234567890', 'customer_name' => 'Konto'],
			'lastSyncAt' => gmdate('Y-m-d H:i:s', self::NOW - 3600),
			'lastRunStatus' => 'completed',
			'lastRunError' => null,
			'monthToDate' => $this->mtd(),
			'searchTermsWithoutConversions' => [],
			'searchTermsTotalCostMicros' => 1_000_000_000,
			'now' => self::NOW,
			'projectCurrencyMismatch' => null,
		];

		return new AuditContext(...$values);
	}

	private function campaign(array $overrides = []): array
	{
		return array_replace([
			'id' => 1,
			'name' => 'Szukaj | Marka',
			'status' => 'enabled',
			'channel_type' => 'SEARCH',
			'budget_micros' => 100_000_000,
			'budget_period' => 'DAILY',
			'bidding_strategy' => 'MAXIMIZE_CONVERSIONS',
			'settings' => [
				'networks' => ['google_search' => true, 'search_partners' => false, 'display' => false],
				'geo_target_type' => 'PRESENCE',
				'locations' => [['name' => 'Warszawa', 'negative' => false]],
				'locations_synced' => true,
			],
			'current' => $this->metrics(),
			'previous' => $this->metrics(),
		], $overrides);
	}

	private function metrics(array $overrides = []): array
	{
		return array_replace(['impressions' => 20_000, 'clicks' => 600, 'cost_micros' => 1_200_000_000, 'conversions' => 40.0, 'conversions_value_micros' => 0], $overrides);
	}

	private function action(array $overrides = []): array
	{
		return array_replace([
			'id' => 1,
			'name' => 'Wysłanie formularza',
			'status' => 'enabled',
			'category_code' => 'SUBMIT_LEAD_FORM',
			'primary_for_goal' => true,
			'include_in_conversions' => true,
			'counting_type' => 'ONE_PER_CLICK',
			'conversions' => 40.0,
		], $overrides);
	}

	private function coverage(int $coveredDays, int $overridden = 0): ResolvedPeriods
	{
		return new ResolvedPeriods([], [], [], [], 0, $coveredDays, 30, [['2026-09-12', '2026-10-11']], $overridden);
	}

	private function mtd(array $overrides = []): array
	{
		return array_replace(['spend' => 1_100_000_000, 'covered_days' => 11, 'elapsed_days' => 11, 'days_in_month' => 31, 'complete' => true, 'month' => '10.2026'], $overrides);
	}

	private function project(): \App\PennyPincher\Domain\Projects\Project
	{
		return new \App\PennyPincher\Domain\Projects\Project(1, 1, 'Org', 'Projekt', 'projekt', 'google_ads', 'PLN', 'Europe/Warsaw', 3_000_000_000, null, null, null, null, null, null, 'active', false, null, '2026-01-01', '2026-01-01');
	}
}
