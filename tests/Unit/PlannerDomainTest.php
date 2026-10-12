<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Planner\AdTemplates;
use App\PennyPincher\Domain\Planner\AdValidator;
use App\PennyPincher\Domain\Planner\BudgetAllocator;
use App\PennyPincher\Domain\Planner\MatchType;
use App\PennyPincher\Domain\Planner\NegativeConflictChecker;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Services\Planner\PlanExporter;
use Tests\TestCase;

/**
 * Campaign Planner i Budget Planner – logika bez bazy: dopasowania, konflikty wykluczeń (reguły Google Ads),
 * walidacja reklam RSA, szablony, eksport CSV, podział budżetu i prognozy.
 */
class PlannerDomainTest extends TestCase
{
	private const M = 1_000_000;

	// --- Dopasowania i wykluczenia ------------------------------------------------------------

	public function testMatchTypeIsNeverBroadByDefault(): void
	{
		$this->assertSame(MatchType::PHRASE, MatchType::suggest('strona internetowa'));
		$this->assertSame(MatchType::EXACT, MatchType::suggest('strona internetowa', 3.0), 'Fraza z konwersjami – ścisłe.');
		$this->assertSame(MatchType::EXACT, MatchType::suggest('tania strona internetowa dla małej firmy'));

		foreach (['a', 'strona', 'strona internetowa cena warszawa', 'x y z q w e r t'] as $phrase) {
			$this->assertTrue(MatchType::suggest($phrase) !== MatchType::BROAD);
		}

		$this->assertSame('[strona www]', MatchType::notation('strona www', 'exact'));
		$this->assertSame('"strona www"', MatchType::notation('strona www', 'phrase'));
		$this->assertFalse(MatchType::valid('modified_broad'));
	}

	public function testNegativeMatchingFollowsGoogleRules(): void
	{
		// Wykluczenie przybliżone: wszystkie słowa w dowolnej kolejności, bez wariantów (odmian).
		$this->assertTrue(NegativeConflictChecker::blocks('darmowa strona', 'broad', 'strona internetowa darmowa'));
		$this->assertFalse(NegativeConflictChecker::blocks('darmowa strona', 'broad', 'darmowe strony'), 'Wykluczenia nie obejmują odmian.');
		// Do wyrażenia: słowa w tej kolejności, mogą być inne słowa przed i po.
		$this->assertTrue(NegativeConflictChecker::blocks('strona www', 'phrase', 'tania strona www dla firmy'));
		$this->assertFalse(NegativeConflictChecker::blocks('strona www', 'phrase', 'www strona'));
		// Ścisłe: dokładnie to zapytanie.
		$this->assertTrue(NegativeConflictChecker::blocks('strona www', 'exact', 'strona www'));
		$this->assertFalse(NegativeConflictChecker::blocks('strona www', 'exact', 'strona www cena'));
	}

	public function testConflictsRespectLevelsAndFlagGenericWords(): void
	{
		$keywords = [
			['id' => 1, 'phrase' => 'blat dębowy', 'plan_campaign_id' => 1, 'plan_ad_group_id' => 10],
			['id' => 2, 'phrase' => 'stół dębowy', 'plan_campaign_id' => 1, 'plan_ad_group_id' => 11],
			['id' => 3, 'phrase' => 'sofa dębowa', 'plan_campaign_id' => 2, 'plan_ad_group_id' => 20],
		];

		$plan = NegativeConflictChecker::conflicts([['phrase' => 'dębowy', 'match_type' => 'broad', 'level' => 'plan', 'plan_campaign_id' => null, 'plan_ad_group_id' => null]], $keywords);
		$this->assertCount(1, $plan);
		$this->assertCount(2, $plan[0]['keywords']);
		$this->assertSame('high', $plan[0]['severity'], 'Pojedyncze słowo blokujące wiele fraz – wysoka waga.');
		$this->assertContains('pojedyncze słowo', $plan[0]['message']);

		$group = NegativeConflictChecker::conflicts([['phrase' => 'dębowy', 'match_type' => 'broad', 'level' => 'ad_group', 'plan_campaign_id' => 1, 'plan_ad_group_id' => 11]], $keywords);
		$this->assertCount(1, $group[0]['keywords'], 'Wykluczenie na poziomie grupy dotyczy tylko tej grupy.');

		$campaign = NegativeConflictChecker::conflicts([['phrase' => 'dębowy', 'match_type' => 'broad', 'level' => 'campaign', 'plan_campaign_id' => 2, 'plan_ad_group_id' => null]], $keywords);
		$this->assertSame([], $campaign, '„dębowa” to inna forma – Google jej nie wykluczy.');
	}

	// --- Reklamy --------------------------------------------------------------------------------

	public function testAdValidationUsesGoogleLimits(): void
	{
		$ok = AdValidator::validate(['Strony www dla firm', 'Sklepy internetowe', 'Poproś o wycenę'], ['Projektujemy strony i sklepy dla firm.', 'Bezpłatna wycena projektu.'], 'strony', 'firmy', 'https://example.com/strony');
		$this->assertSame([], $ok['errors']);
		$this->assertSame([], $ok['warnings']);

		$bad = AdValidator::validate([str_repeat('a', 31), 'Super oferta!', 'Super oferta!'], [str_repeat('b', 91)], 'za długa ścieżka xyz', '', 'javascript:alert(1)');
		$this->assertTrue(isset($bad['errors']['headline_0']));
		$this->assertTrue(isset($bad['errors']['description_0']));
		$this->assertTrue(isset($bad['errors']['path1']));
		$this->assertTrue(isset($bad['errors']['final_url']));
		$this->assertTrue(count($bad['warnings']) >= 3, 'Wykrzyknik, duplikaty i za mało opisów to ostrzeżenia: ' . implode(' | ', $bad['warnings']));

		$this->assertSame(30, AdValidator::length(str_repeat('ż', 30)), 'Polskie znaki liczą się pojedynczo.');
		$this->assertSame(4, AdValidator::length('日本'), 'Znaki o podwójnej szerokości liczą się podwójnie.');
		$this->assertTrue(isset(AdValidator::validate(['a', 'b', 'c'], ['d', 'e'], '', 'x', null)['errors']['path2']));
	}

	public function testAdTemplatesUseOnlyContextAndRespectLimits(): void
	{
		$context = new BusinessContext([
			'priority_services' => "strony internetowe dla firm\nsklepy internetowe",
			'advantages' => "Ponad 10 lat doświadczenia\nWłasny zespół programistów",
		], 'Projektujemy szybkie strony internetowe i sklepy dla małych i średnich firm z całej Polski. Wycena w 24 godziny od kontaktu.');

		$ad = AdTemplates::suggest($context, 'Strony www cena', ['strona www cena', 'koszt strony internetowej'], 'Kraków');
		$validation = AdValidator::validate($ad['headlines'], $ad['descriptions'], null, null, null);

		$this->assertSame([], $validation['errors']);
		$this->assertTrue(count($ad['headlines']) >= 3 && count($ad['headlines']) <= 15);
		$all = mb_strtolower(implode(' ', [...$ad['headlines'], ...$ad['descriptions']]));

		foreach (['najlepsz', 'nr 1', 'gwarancj', 'najtańsz', '100%'] as $claim) {
			$this->assertFalse(str_contains($all, $claim), "Szablon nie może dopisywać obietnic, których nie ma w kontekście: {$claim}");
		}

		$this->assertTrue(in_array('Ponad 10 lat doświadczenia', $ad['headlines'], true), 'Przewagi pochodzą z kontekstu.');
		$this->assertSame('Projektujemy szybkie', AdTemplates::truncate('Projektujemy szybkie strony', 22));
	}

	// --- Eksport --------------------------------------------------------------------------------

	public function testCsvExportIsUtf8BomSemicolonAndFormulaSafe(): void
	{
		$csv = PlanExporter::csv(['name' => 'Plan "Q4"', 'currency' => 'PLN', 'service' => null, 'status' => 'draft'], [
			'campaigns' => [1 => ['name' => '=HYPERLINK("http://x")', 'budget_micros' => 1500 * self::M, 'kind' => 'search', 'final_url' => null, 'groups' => [
				10 => ['name' => 'Grupa; średnik', 'final_url' => null, 'ads' => [], 'keywords' => [
					['phrase' => '+strona -www', 'match_type' => 'phrase', 'intent' => 'commercial', 'score' => 70, 'confidence' => 'high', 'cpc_micros' => 2_500_000, 'cpc_currency' => 'USD', 'search_volume' => 90, 'source' => 'dataforseo'],
					['phrase' => '@sklep', 'match_type' => 'exact', 'intent' => null, 'score' => null, 'confidence' => null, 'cpc_micros' => null, 'cpc_currency' => null, 'search_volume' => null, 'source' => 'manual'],
				]],
			]]],
			'negatives' => [['phrase' => 'darmowe', 'match_type' => 'phrase', 'level' => 'plan', 'plan_campaign_id' => null, 'plan_ad_group_id' => null, 'source' => 'manual']],
		]);

		$this->assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
		$lines = explode("\r\n", trim(substr($csv, 3)));
		$this->assertCount(7, $lines, 'Nagłówek, plan, kampania, grupa, 2 słowa, wykluczenie.');
		$this->assertContains('"Typ wiersza";"Plan";"Kampania"', $lines[0]);
		$this->assertContains('"\'=HYPERLINK(""http://x"")"', $csv);
		$this->assertContains('"\'+strona -www"', $csv);
		$this->assertContains('"\'@sklep"', $csv);
		$this->assertContains('"Grupa; średnik"', $csv);
		$this->assertContains('"Plan ""Q4"""', $csv);
		$this->assertContains('"1500,00"', $csv);
		$this->assertContains('"2,50";"USD"', $csv, 'CPC z oryginalną walutą.');

		foreach ($lines as $line) {
			$this->assertSame(count(PlanExporter::COLUMNS), count(str_getcsv($line, ';', '"', '')), 'Każdy wiersz ma tę samą liczbę kolumn: ' . $line);
		}

		$this->assertSame('"\'-5"', PlanExporter::cell('-5'));
		$this->assertSame('"\'' . "\t" . 'x"', PlanExporter::cell("\tx"));
	}

	// --- Budżet ---------------------------------------------------------------------------------

	public function testAllocationIsNotDrivenByClicksAloneAndNewIsNotAssumedBetter(): void
	{
		$proven = $this->campaign(1, true, ['evidence' => 'proven', 'clicks' => 400, 'conversions' => 20.0, 'potential' => 0.6, 'performance' => 0.7, 'uncertainty' => 0.0]);
		$cheapClicks = $this->campaign(2, false, ['evidence' => 'test', 'clicks' => 0, 'conversions' => 0.0, 'potential' => 0.4, 'performance' => null, 'uncertainty' => 1.0, 'cpc' => 100_000]);
		$budget = 10_000 * self::M;

		$balanced = BudgetAllocator::allocate($budget, [$proven, $cheapClicks], BudgetAllocator::config('balanced'));
		$this->assertSame($budget, array_sum($balanced['allocations']), 'Cały budżet rozdzielony.');
		$this->assertTrue($balanced['allocations'][1] > $balanced['allocations'][2], 'Sprawdzona kampania dostaje więcej niż tania, nowa.');
		$this->assertTrue($balanced['allocations'][1] <= 0.6 * $budget + self::M, 'Limit udziału jednej kampanii.');

		$cautious = BudgetAllocator::allocate($budget, [$proven, $cheapClicks], BudgetAllocator::config('cautious'));
		$experimental = BudgetAllocator::allocate($budget, [$proven, $cheapClicks], BudgetAllocator::config('experimental'));
		$this->assertTrue($cautious['allocations'][2] <= $balanced['allocations'][2] && $balanced['allocations'][2] <= $experimental['allocations'][2], 'Scenariusze różnią się udziałem testów.');

		foreach ($balanced['allocations'] as $amount) {
			$this->assertSame(0, $amount % self::M, 'Kwoty w pełnych jednostkach waluty.');
		}
	}

	public function testScenarioIsConfigurableAndWithoutHistoryEverythingIsATest(): void
	{
		$config = BudgetAllocator::config('balanced', ['proven_share' => '0.9', 'priority' => 2, 'potential' => 'x', 'max_share' => 0.05]);
		$this->assertSame(0.9, $config['proven_share']);
		$this->assertSame(0.2, $config['max_share'], 'Minimalny limit udziału zapobiega niemożliwej konfiguracji.');
		$this->assertEqualsWithDelta(1.0, $config['priority'] + $config['potential'] + $config['performance'], 0.0001);

		$result = BudgetAllocator::allocate(3000 * self::M, [
			$this->campaign(1, true, ['evidence' => 'test', 'potential' => 0.7]),
			$this->campaign(2, false, ['evidence' => 'test', 'potential' => 0.5]),
		], BudgetAllocator::config('balanced'));
		$this->assertSame(0, $result['buckets']['proven']);
		$this->assertContains('cały budżet to test', $result['notes'][0]);
		$this->assertSame(['Ustaw budżet miesięczny planu.'], BudgetAllocator::allocate(0, [$this->campaign(1, true, [])], BudgetAllocator::config('balanced'))['notes']);
	}

	public function testForecastShowsLeadsOnlyWithReliableMeasurementAndHistory(): void
	{
		$test = BudgetAllocator::forecast(3000 * self::M, ['evidence' => 'test', 'clicks' => 40, 'conversions' => 2.0, 'cpc' => 2 * self::M, 'cpc_source' => 'historia konta'], true);
		$this->assertSame(BudgetAllocator::NO_LEAD_FORECAST, $test['lead_message']);
		$this->assertSame('Brak wystarczających danych do wiarygodnej prognozy kosztu pozyskania leada.', $test['lead_message']);
		$this->assertNull($test['conversions_low']);
		$this->assertSame(98, intdiv($test['daily'], self::M), 'Dziennie = miesięcznie / 30,4.');
		$this->assertTrue($test['clicks_low'] < $test['clicks_high']);
		$this->assertSame('high', $test['uncertainty']);

		$proven = ['evidence' => 'proven', 'clicks' => 600, 'conversions' => 30.0, 'cpc' => 2 * self::M, 'cpc_source' => 'historia konta'];
		$unreliable = BudgetAllocator::forecast(3000 * self::M, $proven, false);
		$this->assertSame(BudgetAllocator::NO_LEAD_FORECAST, $unreliable['lead_message'], 'Przy niewiarygodnym pomiarze (np. kliknięcia telefonu, krótkie wizyty) brak prognozy leadów.');

		$reliable = BudgetAllocator::forecast(3000 * self::M, $proven, true);
		$this->assertNull($reliable['lead_message']);
		$this->assertTrue($reliable['conversions_low'] < $reliable['conversions_high']);
		$this->assertTrue($reliable['cpl_low'] < $reliable['cpl_high']);
		$this->assertSame('low', $reliable['uncertainty']);

		$noCpc = BudgetAllocator::forecast(3000 * self::M, ['evidence' => 'test', 'clicks' => 0, 'conversions' => 0.0, 'cpc' => null, 'cpc_source' => null], true);
		$this->assertNull($noCpc['clicks_low'], 'Bez CPC nie zgadujemy kliknięć.');

		[$low, $high] = BudgetAllocator::wilson(5, 100);
		$this->assertTrue($low > 0.02 && $low < 0.05 && $high > 0.05 && $high < 0.09);
	}

	public function testProfileUsesHistoryBeforeMarketCpc(): void
	{
		$profile = BudgetAllocator::profile([
			['term_clicks' => 120, 'term_cost_micros' => 240 * self::M, 'term_conversions' => 4, 'score' => 70, 'search_volume' => 500, 'confidence' => 'high', 'cpc_project_micros' => 9 * self::M],
			['term_clicks' => null, 'term_cost_micros' => null, 'term_conversions' => null, 'score' => 60, 'search_volume' => null, 'confidence' => 'low', 'cpc_project_micros' => 5 * self::M],
		]);

		$this->assertSame('proven', $profile['evidence']);
		$this->assertSame(2 * self::M, $profile['cpc']);
		$this->assertSame('historia konta', $profile['cpc_source']);
		$this->assertEqualsWithDelta(0.5, $profile['uncertainty'], 0.001);

		$market = BudgetAllocator::profile([['term_clicks' => null, 'score' => 60, 'confidence' => 'low', 'cpc_project_micros' => 5 * self::M]]);
		$this->assertSame('test', $market['evidence']);
		$this->assertSame('DataForSEO (przeliczone)', $market['cpc_source']);
		$this->assertNull($market['performance']);
	}

	private function campaign(int $id, bool $priority, array $profile): array
	{
		return ['id' => $id, 'name' => 'Kampania ' . $id, 'priority' => $priority, 'profile' => $profile + [
			'evidence' => 'test', 'clicks' => 0, 'conversions' => 0.0, 'cost' => 0, 'potential' => 0.5, 'performance' => null, 'uncertainty' => 0.5, 'cpc' => null, 'cpc_source' => null, 'keywords' => 3,
		]];
	}
}
