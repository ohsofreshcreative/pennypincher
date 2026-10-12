<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Keywords\Concepts;
use App\PennyPincher\Domain\Keywords\KeywordClusterer;
use App\PennyPincher\Domain\Keywords\LexicalSimilarity;
use App\PennyPincher\Domain\Keywords\OpportunityScoreV2;
use App\PennyPincher\Domain\Keywords\PhraseQuality;
use App\PennyPincher\Domain\Keywords\PhraseSimilarity;
use App\PennyPincher\Domain\Keywords\SeedSelector;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoClient;
use Tests\TestCase;

/**
 * Keyword Intelligence 2.0: automatyczne frazy źródłowe, ocena jakości fraz, klastry i Opportunity Score 2.0.
 * Kontekst odpowiada agencji tworzącej strony internetowe (jak OhSoFresh) – przypadki z realnych danych.
 */
class KeywordIntelligenceTest extends TestCase
{
	// --- Frazy źródłowe ---------------------------------------------------------------------------

	public function testAutomaticSeedsRejectForeignCompanyCompetitorAndOffTopicPhrases(): void
	{
		$result = SeedSelector::analyze($this->context(), $this->terms([
			'online seller', 'stevedesign', 'hk bundle e commerce limited', 'marketing plan for a new product',
			'strona internetowa cena', 'tworzenie stron www dla firm', 'darmowy kreator stron', 'jak zrobić stronę internetową', 'ohsofresh', 'wix strona',
		]), []);

		$seeds = array_column($result['seeds'], 'phrase');
		$rejected = array_column($result['rejected'], 'reason', 'phrase');

		foreach (['online seller', 'stevedesign', 'hk bundle e commerce limited', 'marketing plan for a new product', 'darmowy kreator stron', 'jak zrobić stronę internetową', 'wix strona'] as $bad) {
			$this->assertFalse(in_array($bad, $seeds, true), "„{$bad}” nie może być automatyczną frazą źródłową.");
			$this->assertTrue(isset($rejected[$bad]), "„{$bad}” powinna mieć powód odrzucenia.");
		}

		$this->assertContains('innym języku', $rejected['online seller']);
		$this->assertContains('nazwę firmy', $rejected['hk bundle e commerce limited']);
		$this->assertContains('konkurencji', $rejected['stevedesign']);
		$this->assertContains('niska intencja', $rejected['darmowy kreator stron']);

		// Własna marka i trafne frazy zostają; usługi priorytetowe są pierwsze.
		$this->assertTrue(in_array('ohsofresh', $seeds, true), 'Własna marka nie jest blokowana.');
		$this->assertTrue(in_array('strona internetowa cena', $seeds, true));
		$this->assertTrue(in_array('tworzenie stron www dla firm', $seeds, true));
		$this->assertSame('strony internetowe dla firm', $seeds[0]);
		$this->assertSame('Usługa priorytetowa', $result['seeds'][0]['source']);
	}

	public function testSeedLimitIsTwentyAndDuplicatesAreRemoved(): void
	{
		$phrases = [];

		for ($i = 1; $i <= 40; $i++) {
			$phrases[] = "strona internetowa {$i} podstrony";
		}

		$result = SeedSelector::analyze($this->context(), $this->terms([...$phrases, 'Strona internetowa 1 podstrony', '"strona internetowa 2 podstrony"']), []);

		$this->assertSame(20, DataForSeoClient::MAX_SEEDS);
		$this->assertCount(20, $result['seeds']);
		$normalized = array_map(fn ($s) => Text::normalize($s['phrase']), $result['seeds']);
		$this->assertSame(count($normalized), count(array_unique($normalized)));
		$this->assertSame('strona internetowa 2 podstrony', SeedSelector::clean('"strona internetowa 2 podstrony"'));
	}

	public function testOwnBrandIsAllowedButCompetitorBrandIsFlagged(): void
	{
		$context = $this->context();

		$this->assertTrue(in_array(PhraseQuality::OWN_BRAND, PhraseQuality::flags('ohsofresh strony', $context), true));
		$this->assertFalse(in_array(PhraseQuality::COMPANY_NAME, PhraseQuality::flags('ohsofresh strony', $context), true));
		$this->assertTrue(in_array(PhraseQuality::COMPETITOR_BRAND, PhraseQuality::flags('stevedesign strony', $context), true));
		// Marki spoza listy konkurencji nie są blokowane hurtowo – fraza „strona na wordpress” jest dozwolona.
		$this->assertSame([], array_intersect(PhraseQuality::flags('strona internetowa wordpress', $context), [PhraseQuality::COMPETITOR_BRAND, PhraseQuality::COMPANY_NAME]));
		$this->assertTrue(in_array(PhraseQuality::COMPANY_NAME, PhraseQuality::flags('firma-x.com', $context), true));
	}

	public function testLanguageDetection(): void
	{
		$this->assertSame('en', PhraseQuality::language('marketing plan for a new product'));
		$this->assertSame('pl', PhraseQuality::language('tworzenie stron internetowych'));
		$this->assertSame('pl', PhraseQuality::language('strona dla firmy'));
		$this->assertNull(PhraseQuality::language('wordpress'));
	}

	public function testConceptsTreatSynonymsAndInflectionsAlike(): void
	{
		$a = Concepts::of('strona internetowa cena');
		$b = Concepts::of('strony internetowe koszt');

		$this->assertSame($a, $b, 'Odmiana i synonimy (cena/koszt) dają te same pojęcia.');
		$this->assertTrue(in_array('price', $a, true));
		$this->assertTrue(in_array('free', Concepts::of('darmowy kreator stron'), true));
	}

	// --- Klastry --------------------------------------------------------------------------------

	public function testClustersGroupByMeaningAndKeepFreeAndInformationalSeparate(): void
	{
		$result = (new KeywordClusterer())->cluster($this->rows([
			'strona internetowa cena', 'strony internetowe cena', 'ile kosztuje strona internetowa', 'koszt strony internetowej',
			'darmowy kreator stron', 'jak zrobić stronę internetową', 'sklep internetowy', 'sklepy internetowe cena',
		]), $this->context());

		$a = $result['assignments'];
		$price = $a['strona internetowa cena'];

		$this->assertSame($price, $a['strony internetowe cena']);
		$this->assertSame($price, $a['ile kosztuje strona internetowa']);
		$this->assertSame($price, $a['koszt strony internetowej']);
		$this->assertTrue($a['darmowy kreator stron'] !== $price, '„darmowy kreator” to osobny klaster.');
		$this->assertTrue($a['jak zrobic strone internetowa'] !== $price, 'Zapytanie informacyjne/DIY nie trafia do klastra zakupowego.');
		$this->assertTrue($a['sklep internetowy'] !== $price);
		$this->assertTrue($a['sklepy internetowe cena'] !== $a['sklep internetowy'], 'Modyfikator ceny oddziela klaster.');

		$cluster = $result['clusters'][$price];

		foreach (['name', 'service', 'intent', 'phrase_count', 'volume_max', 'volume_sum', 'cpc_project_micros', 'cpc_original', 'score', 'confidence', 'suggested_campaign_id', 'suggested_ad_group_id'] as $field) {
			$this->assertTrue(array_key_exists($field, $cluster), "Klaster nie ma pola {$field}.");
		}

		$this->assertSame(4, $cluster['phrase_count']);
		$this->assertSame('strony internetowe dla firm', $cluster['service']);
		$this->assertTrue($cluster['volume_sum'] >= $cluster['volume_max'], 'Suma wolumenu to górna granica, maksimum – dolna.');
		$this->assertSame(['USD' => 2_000_000], $cluster['cpc_original'], 'CPC w oryginalnej walucie, bez mieszania walut.');
	}

	public function testClusteringIsDeterministicRegardlessOfInputOrder(): void
	{
		$rows = $this->rows(['strona internetowa cena', 'strony internetowe cena', 'sklep internetowy', 'tworzenie stron www', 'darmowy kreator stron']);
		$first = (new KeywordClusterer())->cluster($rows, $this->context());
		$second = (new KeywordClusterer())->cluster(array_reverse($rows), $this->context());

		$this->assertSame($first['assignments'], array_intersect_key($second['assignments'], $first['assignments']));
		$this->assertSame(array_keys($first['clusters']), array_keys($second['clusters']));
	}

	public function testSimilarityIsPluggable(): void
	{
		$this->assertTrue((new LexicalSimilarity()) instanceof PhraseSimilarity);

		// Model, który uznaje wszystko za różne – każda fraza w osobnym klastrze (architektura gotowa na model semantyczny).
		$strict = new class implements PhraseSimilarity {
			public function signature(string $phrase): array
			{
				return ['core' => [$phrase], 'modifiers' => []];
			}

			public function compatible(array $a, array $b): bool
			{
				return true;
			}

			public function similarity(array $a, array $b): float
			{
				return 0.0;
			}
		};

		$result = (new KeywordClusterer($strict))->cluster($this->rows(['strona internetowa cena', 'strony internetowe cena']), $this->context());
		$this->assertCount(2, $result['clusters']);
	}

	// --- Opportunity Score 2.0 ----------------------------------------------------------------

	public function testCheapCpcAloneDoesNotMakeAGoodOpportunity(): void
	{
		$cheapButOffTopic = OpportunityScoreV2::calculate(['offer_fit' => 0.0, 'intent' => 0.15, 'cpc' => 1.0, 'volume' => 1.0, 'competition' => 1.0], [PhraseQuality::UNRELATED]);
		$relevant = OpportunityScoreV2::calculate(['offer_fit' => 1.0, 'intent' => 0.85, 'cpc' => 0.3, 'volume' => 0.5, 'competition' => 0.6]);

		$this->assertSame(0.0, $cheapButOffTopic['breakdown']['cpc']['points'], 'Niski CPC bez intencji i dopasowania nie daje punktów.');
		$this->assertTrue($relevant['score'] > $cheapButOffTopic['score'] + 30);
		$this->assertTrue(in_array('Niski CPC nie podnosi oceny, bo intencja lub dopasowanie do oferty są słabe', $cheapButOffTopic['risks'], true));
		$this->assertSame('2.0', $relevant['version']);
	}

	public function testMissingDataIsNotZeroAndLowersConfidenceNotDemand(): void
	{
		$full = OpportunityScoreV2::calculate(['offer_fit' => 1.0, 'intent' => 0.85, 'audience' => 0.5, 'coverage' => 1.0, 'cpc' => 0.5, 'volume' => 0.5, 'competition' => 0.6], [], ['measurement_reliable' => true]);
		$missing = OpportunityScoreV2::calculate(['offer_fit' => 1.0, 'intent' => 0.85, 'audience' => 0.5, 'coverage' => 1.0, 'cpc' => null, 'volume' => null, 'competition' => null], [], ['measurement_reliable' => true]);

		$this->assertNull($missing['breakdown']['cpc']['value'], 'Brak CPC to null, nie 0.');
		$this->assertNull($missing['breakdown']['volume']['value'], 'Brak wolumenu to brak danych, nie brak popytu.');
		$this->assertTrue(in_array('Popularność wyszukiwań', $missing['missing'], true));
		$this->assertTrue(in_array('Koszt kliknięcia (przy dobrej intencji)', $missing['missing'], true));
		$this->assertTrue($missing['confidence_score'] < $full['confidence_score'], 'Braki danych obniżają pewność.');
		$this->assertFalse(in_array('Popularność wyszukiwań: nisko (0/7 pkt)', $missing['risks'], true), 'Brak wolumenu nie jest raportowany jako niski popyt.');
	}

	public function testScoreAndConfidenceAreSeparateAndExplained(): void
	{
		// Wysoki wynik, ale bez historii i danych rynkowych – niska pewność.
		$result = OpportunityScoreV2::calculate(['offer_fit' => 1.0, 'intent' => 1.0, 'audience' => 1.0, 'coverage' => 1.0]);

		$this->assertTrue($result['score'] >= 60);
		$this->assertSame('low', $result['confidence']);

		foreach (['breakdown', 'strengths', 'risks', 'missing', 'action', 'penalties'] as $key) {
			$this->assertTrue(array_key_exists($key, $result), "Brak wyjaśnienia: {$key}");
		}

		$this->assertTrue($result['strengths'] !== []);
		$this->assertTrue($result['action'] !== '');
	}

	public function testPenaltiesForWrongLanguageAndCompanyNames(): void
	{
		$base = ['offer_fit' => 0.5, 'intent' => 0.5, 'coverage' => 1.0];
		$clean = OpportunityScoreV2::calculate($base);
		$foreign = OpportunityScoreV2::calculate($base, [PhraseQuality::WRONG_LANGUAGE, PhraseQuality::COMPANY_NAME]);

		$this->assertSame(max(0, $clean['score'] - 50), $foreign['score']);
		$this->assertContains('odrzuć', $foreign['action']);
	}

	public function testPerformanceUsesSampleSize(): void
	{
		$this->assertNull(OpportunityScoreV2::performance(null, null, 0.05));
		$this->assertSame(0.45, OpportunityScoreV2::performance(5, 0.0, 0.05), 'Mała próba bez konwersji jest prawie neutralna.');
		$this->assertTrue(OpportunityScoreV2::performance(200, 0.0, 0.05) < 0.01, '200 kliknięć bez konwersji przy 5% CR to silny sygnał.');
		$this->assertTrue(OpportunityScoreV2::performance(100, 10.0, 0.05) > 0.9);
		$this->assertSame(null, OpportunityScoreV2::competition(null));
		$this->assertTrue(OpportunityScoreV2::intent('commercial', 'strona internetowa cena') > OpportunityScoreV2::intent('unknown', 'strona internetowa'));
	}

	// --- Pomocnicze ---------------------------------------------------------------------------

	private function context(): BusinessContext
	{
		return new BusinessContext([
			'products_services' => "strony internetowe dla firm\nsklepy internetowe\nprojektowanie stron www",
			'priority_services' => "strony internetowe dla firm\nsklep internetowy",
			'ideal_customer' => 'małe i średnie firmy',
			'excluded_audiences' => "praca\nkurs",
			'own_brand' => 'ohsofresh',
			'competitor_brands' => "stevedesign\nwix",
			'language' => 'pl',
		], 'Agencja tworząca strony www i sklepy internetowe dla firm', 'firmy');
	}

	private function terms(array $phrases): array
	{
		return array_map(fn ($t, $i) => ['term' => $t, 'conversions' => 3 - $i % 3, 'clicks' => 20 + $i], $phrases, array_keys($phrases));
	}

	private function rows(array $phrases): array
	{
		return array_map(fn ($p, $i) => [
			'phrase' => $p,
			'normalized' => Text::normalize($p),
			'intent' => Text::primaryIntent(Text::intents($p, [])),
			'score' => 70 - $i,
			'confidence' => 'medium',
			'search_volume' => 100 * ($i + 1),
			'cpc_micros' => 2_000_000,
			'cpc_currency' => 'USD',
			'cpc_project_micros' => null,
			'suggested_campaign_id' => null,
			'suggested_ad_group_id' => null,
		], $phrases, array_keys($phrases));
	}
}
