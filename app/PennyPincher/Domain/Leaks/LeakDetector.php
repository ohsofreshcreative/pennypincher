<?php

namespace App\PennyPincher\Domain\Leaks;

use App\PennyPincher\Domain\Analysis\Statistics;
use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Support\Format;

/**
 * Deterministyczne wykrywanie miejsc, w których budżet może być wydawany nieefektywnie.
 *
 * Zasady:
 * - brak konwersji nie oznacza automatycznie straty: wymagamy minimalnej liczby kliknięć i kosztu,
 *   a pewność wynika z prawdopodobieństwa zera konwersji przy typowym współczynniku konwersji kampanii,
 * - konwersji Google Ads nie przypisujemy proporcjonalnie hasłom ani słowom kluczowym – dla nich znamy tylko
 *   konwersje ogółem; wartościowe konwersje (cele biznesowe) liczymy tylko tam, gdzie API je rozbija (kampanie),
 * - oszczędność to szacunek (średni miesięczny koszt problemu), tylko przy pewności średniej lub wysokiej.
 */
final class LeakDetector
{
	/** Minimalna liczba kliknięć hasła/słowa bez konwersji, od której oceniamy problem. */
	public const MIN_CLICKS = 10;

	/** Minimalny koszt w jednostkach waluty (mikro) – poniżej tego nie zgłaszamy pojedynczych fraz. */
	public const MIN_COST_MICROS = 20_000_000;

	public const THEME_MIN_TERMS = 3;

	/**
	 * @return array<int, Leak>
	 */
	public function detect(LeakContext $context): array
	{
		if ($context->totalCost() <= 0) {
			return $this->measurement($context);
		}

		$leaks = [
			...$this->searchTerms($context),
			...$this->keywords($context),
			...$this->campaigns($context),
			...$this->measurement($context),
		];

		$unique = [];

		foreach ($leaks as $leak) {
			$unique[$leak->fingerprint()] ??= $leak;
		}

		return array_values($unique);
	}

	/**
	 * Minimalny koszt pojedynczej frazy: większy z progu stałego, 0,5% wydatków okresu i typowego kosztu konwersji.
	 */
	public function minCost(LeakContext $context, ?int $campaignId = null): int
	{
		$rate = $context->conversionRate($campaignId);
		$clicks = $campaignId !== null ? (int) ($context->campaigns[$campaignId]['current']['clicks'] ?? 0) : $context->totalClicks();
		$cost = $campaignId !== null ? (int) ($context->campaigns[$campaignId]['current']['cost_micros'] ?? 0) : $context->totalCost();
		$cpa = $rate !== null && $clicks > 0 ? (int) round($cost / max(1, $clicks * $rate)) : 0;

		return (int) max(self::MIN_COST_MICROS, round($context->totalCost() * 0.005), $cpa);
	}

	/**
	 * @return array<int, Leak>
	 */
	private function searchTerms(LeakContext $context): array
	{
		$leaks = [];
		$excluded = $context->context->excludedAudiences();
		$offer = $this->offerStems($context);
		$keywordStems = $this->keywordStemsByAdGroup($context);
		$themes = [];
		$micro = $context->primaryMostlyMicro();

		foreach ($context->terms as $term) {
			if (($term['conversions'] ?? null) === null || $term['conversions'] > 0 || $term['cost_micros'] <= 0) {
				continue;
			}

			$campaignId = $term['campaign_id'];
			$rate = $context->conversionRate($campaignId);
			$intents = Text::intents($term['term'], $excluded);
			$lowIntent = array_values(array_intersect($intents, ['excluded', 'free', 'jobs', 'education', 'diy']));
			$runRate = Statistics::monthlyRunRate($term['cost_micros'], $context->days());
			$base = [
				'entityType' => 'search_term',
				'entityId' => $term['id'],
				'campaignId' => $campaignId,
				'adGroupId' => $term['ad_group_id'],
				'subject' => $term['term'],
				'costMicros' => $term['cost_micros'],
				'clicks' => $term['clicks'],
				'conversions' => 0.0,
				'termIds' => [$term['id'] => [$campaignId, $runRate]],
			];

			foreach (Text::stems($term['term']) as $stem) {
				$themes[$stem][] = $term;
			}

			// BL102: niska intencja (darmowe, praca, nauka, zrób to sam, niepożądani odbiorcy z kontekstu).
			if ($lowIntent !== [] && $term['clicks'] >= 1) {
				$confidence = in_array('excluded', $lowIntent, true) || $term['clicks'] >= 5 ? Statistics::MEDIUM : Statistics::LOW;
				$labels = implode(', ', array_map(fn ($i) => $i === 'excluded' ? 'niepożądani odbiorcy (kontekst projektu)' : Text::INTENT_LABELS[$i], $lowIntent));
				$leaks[] = new Leak(
					'BL102', 'search_terms',
					sprintf('Hasło o niskiej intencji zakupowej: „%s”', $term['term']),
					sprintf('Zapytanie wskazuje na: %s. Nie przyniosło konwersji przy %s kliknięciach.', $labels, Format::number($term['clicks'])),
					'Rozważ dodanie wykluczenia (propozycje w Keyword Opportunities → Wykluczenia). Sprawdź najpierw, czy podobne zapytania nie przynoszą klientów.',
					$confidence, 12,
					...$base,
					savingsMicros: $confidence !== Statistics::LOW ? $runRate : null,
					evidence: $this->termEvidence($context, $term, $rate, ['Rozpoznana intencja', $labels]),
				);
				continue;
			}

			$minCost = $this->minCost($context, $campaignId);

			// BL101: istotny koszt bez konwersji przy wystarczającej liczbie kliknięć.
			if ($term['clicks'] >= self::MIN_CLICKS && $term['cost_micros'] >= $minCost) {
				$confidence = Statistics::confidenceForZero($term['clicks'], $rate);

				if ($micro && $confidence === Statistics::HIGH) {
					$confidence = Statistics::MEDIUM; // konwersje główne to głównie mikrokonwersje – mniejsza pewność wniosków
				}

				$leaks[] = new Leak(
					'BL101', 'search_terms',
					sprintf('Wydatki bez konwersji: „%s”', $term['term']),
					sprintf(
						'Hasło kosztowało %s przy %s kliknięciach i nie przyniosło żadnej konwersji%s.',
						Format::money($term['cost_micros'], $context->currency),
						Format::number($term['clicks']),
						$rate !== null ? sprintf(' (przy typowym współczynniku kampanii oczekiwano ok. %s)', Format::conversions(Statistics::expected($term['clicks'], $rate))) : ''
					),
					'Sprawdź, czy zapytanie pasuje do oferty. Jeśli nie – dodaj je jako wykluczenie; jeśli tak – sprawdź stronę docelową i reklamę.',
					$confidence, 10,
					...$base,
					savingsMicros: $confidence !== Statistics::LOW ? $runRate : null,
					evidence: $this->termEvidence($context, $term, $rate),
				);
				continue;
			}

			// BL104: wysoki CPC bez wyników.
			$campaign = $context->campaigns[$campaignId] ?? null;
			$campaignCpc = $campaign && ($campaign['current']['clicks'] ?? 0) > 0 ? $campaign['current']['cost_micros'] / $campaign['current']['clicks'] : null;
			$termCpc = $term['clicks'] > 0 ? $term['cost_micros'] / $term['clicks'] : null;

			if ($campaignCpc && $termCpc && $term['clicks'] >= 5 && $termCpc >= 2 * $campaignCpc && $term['cost_micros'] >= self::MIN_COST_MICROS) {
				$leaks[] = new Leak(
					'BL104', 'search_terms',
					sprintf('Wysoki CPC bez wyników: „%s”', $term['term']),
					sprintf('Średni CPC hasła (%s) jest ponad dwukrotnie wyższy niż w kampanii (%s), a hasło nie przyniosło konwersji.', Format::money((int) round($termCpc), $context->currency), Format::money((int) round($campaignCpc), $context->currency)),
					'Sprawdź stawki i dopasowanie słowa kluczowego, które uruchamia to hasło. Rozważ niższą stawkę albo wykluczenie.',
					Statistics::LOW, 8,
					...$base,
					evidence: $this->termEvidence($context, $term, $rate, ['CPC kampanii', Format::money((int) round($campaignCpc), $context->currency)]),
				);
				continue;
			}

			// BL105: hasło niedopasowane do oferty (tylko gdy kontekst biznesowy opisuje ofertę).
			if ($offer !== [] && $term['clicks'] >= 5) {
				$reference = array_merge($offer, $keywordStems[$term['ad_group_id'] ?? 0] ?? []);

				if (Text::overlap($term['term'], $reference) === 0.0) {
					$leaks[] = new Leak(
						'BL105', 'search_terms',
						sprintf('Hasło może nie pasować do oferty: „%s”', $term['term']),
						'Hasło nie zawiera żadnego słowa z opisu oferty ani ze słów kluczowych grupy reklam i nie przyniosło konwersji.',
						'Oceń trafność ręcznie – automatyczne porównanie słów nie rozumie synonimów. Jeśli zapytanie jest nietrafne, dodaj wykluczenie.',
						Statistics::LOW, 6,
						...$base,
						evidence: $this->termEvidence($context, $term, $rate),
					);
				}
			}
		}

		// BL103: powtarzające się nieefektywne tematy (ten sam rdzeń słowa w wielu hasłach bez konwersji).
		$offerSet = array_flip($offer);
		$keywordSet = array_flip(array_merge(...array_values($keywordStems ?: [[]])));

		foreach ($themes as $stem => $terms) {
			if (count($terms) < self::THEME_MIN_TERMS || isset($offerSet[$stem]) || isset($keywordSet[$stem]) || mb_strlen($stem) < 3) {
				continue;
			}

			$cost = array_sum(array_column($terms, 'cost_micros'));
			$clicks = array_sum(array_column($terms, 'clicks'));

			if ($cost < max(self::MIN_COST_MICROS * 2, $this->minCost($context)) || $clicks < self::MIN_CLICKS) {
				continue;
			}

			$confidence = Statistics::confidenceForZero($clicks, $context->conversionRate());
			usort($terms, fn ($a, $b) => $b['cost_micros'] <=> $a['cost_micros']);
			$examples = array_map(fn ($t) => '„' . $t['term'] . '”', array_slice($terms, 0, 5));

			$leaks[] = new Leak(
				'BL103', 'search_terms',
				sprintf('Powtarzający się temat bez konwersji: „%s…”', $stem),
				sprintf('%d haseł zawierających „%s…” kosztowało łącznie %s bez żadnej konwersji.', count($terms), $stem, Format::money($cost, $context->currency)),
				'Przejrzyj hasła z tym tematem. Jeśli temat nie pasuje do oferty, jedno wykluczenie (dopasowanie do wyrażenia) obejmie je wszystkie.',
				$confidence, 12,
				'theme', null, null, null, $stem, $cost, $clicks, 0.0, null,
				savingsMicros: $confidence !== Statistics::LOW ? Statistics::monthlyRunRate($cost, $context->days()) : null,
				evidence: [
					['Liczba haseł', (string) count($terms)],
					['Koszt łącznie', Format::money($cost, $context->currency)],
					['Kliknięcia łącznie', Format::number($clicks)],
					['Przykłady', implode(', ', $examples)],
				],
				termIds: $this->termRates($terms, $context->days()),
			);
		}

		return $leaks;
	}

	/**
	 * @return array<int, Leak>
	 */
	private function keywords(LeakContext $context): array
	{
		$leaks = [];
		$termsByKeyword = [];

		foreach ($context->terms as $term) {
			if ($term['keyword_text']) {
				$termsByKeyword[($term['ad_group_id'] ?? 0) . '|' . Text::normalize((string) $term['keyword_text'])][] = $term;
			}
		}

		$duplicates = [];

		foreach ($context->keywords as $keyword) {
			$campaignId = $keyword['campaign_id'];
			$rate = $context->conversionRate($campaignId);
			$base = [
				'entityType' => 'keyword',
				'entityId' => $keyword['id'],
				'campaignId' => $campaignId,
				'adGroupId' => $keyword['ad_group_id'],
				'subject' => $keyword['text'],
				'costMicros' => $keyword['cost_micros'],
				'clicks' => $keyword['clicks'],
				'conversions' => $keyword['conversions'],
			];
			$evidence = [
				['Słowo kluczowe', $keyword['text'] . ' (' . ($keyword['match_type'] ?? '—') . ')'],
				['Kampania', $context->campaignName($campaignId)],
				['Grupa reklam', $context->adGroups[$keyword['ad_group_id']]['name'] ?? '—'],
				['Koszt w okresie', Format::money($keyword['cost_micros'], $context->currency)],
				['Kliknięcia', Format::number($keyword['clicks'])],
				['Konwersje (Google Ads)', Format::conversions($keyword['conversions'])],
			];

			if ($keyword['cost_micros'] > 0 && $keyword['status'] !== 'removed') {
				$duplicates[Text::normalize($keyword['text']) . '|' . ($keyword['match_type'] ?? '')][] = $keyword;
			}

			// BL201: słowo kluczowe z istotnym kosztem bez konwersji.
			if ($keyword['conversions'] !== null && $keyword['conversions'] == 0 && $keyword['clicks'] >= self::MIN_CLICKS && $keyword['cost_micros'] >= $this->minCost($context, $campaignId)) {
				$confidence = Statistics::confidenceForZero($keyword['clicks'], $rate);
				$leaks[] = new Leak(
					'BL201', 'keywords',
					sprintf('Słowo kluczowe bez konwersji: „%s”', $keyword['text']),
					sprintf('Słowo kosztowało %s przy %s kliknięciach bez żadnej konwersji%s.', Format::money($keyword['cost_micros'], $context->currency), Format::number($keyword['clicks']), $rate !== null ? sprintf(' (oczekiwano ok. %s)', Format::conversions(Statistics::expected($keyword['clicks'], $rate))) : ''),
					'Sprawdź wyszukiwane hasła tego słowa. Rozważ obniżenie stawki, zawężenie dopasowania albo wstrzymanie.',
					$confidence, 12,
					...$base,
					savingsMicros: $confidence !== Statistics::LOW ? Statistics::monthlyRunRate($keyword['cost_micros'], $context->days()) : null,
					evidence: [...$evidence, ['Oczekiwane konwersje przy typowym współczynniku', $rate !== null ? Format::conversions(Statistics::expected($keyword['clicks'], $rate)) : 'brak danych']],
				);
			}

			// BL202: zbyt szerokie dopasowanie – większość kosztu na hasłach bez wspólnych słów ze słowem kluczowym.
			$terms = $termsByKeyword[($keyword['ad_group_id'] ?? 0) . '|' . Text::normalize($keyword['text'])] ?? [];

			if (in_array($keyword['match_type'], ['broad', 'phrase'], true) && count($terms) >= 5) {
				$keywordStems = Text::stems($keyword['text']);
				$termCost = array_sum(array_column($terms, 'cost_micros'));
				$farCost = array_sum(array_map(fn ($t) => Text::overlap($t['term'], $keywordStems) < 0.5 ? $t['cost_micros'] : 0, $terms));

				if ($termCost >= self::MIN_COST_MICROS * 2 && $farCost / max(1, $termCost) >= 0.5) {
					$far = array_values(array_filter($terms, fn ($t) => Text::overlap($t['term'], $keywordStems) < 0.5));
					usort($far, fn ($a, $b) => $b['cost_micros'] <=> $a['cost_micros']);
					$leaks[] = new Leak(
						'BL202', 'keywords',
						sprintf('Zbyt szerokie dopasowanie: „%s”', $keyword['text']),
						sprintf('%s kosztu haseł tego słowa przypada na zapytania, które mają mało wspólnego z jego treścią.', Format::percent($farCost / $termCost * 100, 0)),
						'Rozważ zmianę dopasowania na do wyrażenia lub ścisłe albo dodanie wykluczeń dla nietrafnych zapytań.',
						Statistics::MEDIUM, 12,
						...array_merge($base, ['costMicros' => $farCost]),
						evidence: [...$evidence, ['Odległe zapytania', implode(', ', array_map(fn ($t) => '„' . $t['term'] . '”', array_slice($far, 0, 5)))]],
						termIds: $this->termRates($far, $context->days()),
					);
				}
			}

			// BL203: niska skuteczność – CPA słowa wyraźnie wyższy niż kampanii.
			$campaign = $context->campaigns[$campaignId] ?? null;
			$campaignCpa = $campaign && ($campaign['current']['conversions'] ?? 0) > 0 ? $campaign['current']['cost_micros'] / $campaign['current']['conversions'] : null;

			if ($campaignCpa && $keyword['conversions'] >= 2 && $keyword['clicks'] >= 30) {
				$cpa = $keyword['cost_micros'] / $keyword['conversions'];

				if ($cpa >= 2.5 * $campaignCpa) {
					$leaks[] = new Leak(
						'BL203', 'keywords',
						sprintf('Wysoki koszt konwersji: „%s”', $keyword['text']),
						sprintf('Koszt konwersji (%s) jest ponad 2,5 raza wyższy niż średnia kampanii (%s).', Format::money((int) round($cpa), $context->currency), Format::money((int) round($campaignCpa), $context->currency)),
						'Obniż stawkę lub przenieś budżet do skuteczniejszych słów. Sprawdź, jakie konwersje przynosi to słowo (konwersji Google Ads nie da się rozbić na cele biznesowe na poziomie słowa).',
						$keyword['conversions'] >= 5 ? Statistics::MEDIUM : Statistics::LOW, 10,
						...$base,
						evidence: [...$evidence, ['Koszt konwersji kampanii', Format::money((int) round($campaignCpa), $context->currency)]],
					);
				}
			}
		}

		// BL204: to samo słowo kluczowe (tekst + dopasowanie) w kilku grupach reklam – konkurują ze sobą.
		foreach ($duplicates as $group) {
			if (count($group) < 2 || count(array_unique(array_column($group, 'ad_group_id'))) < 2) {
				continue;
			}

			$cost = array_sum(array_column($group, 'cost_micros'));
			$places = array_map(fn ($k) => $context->campaignName($k['campaign_id']) . ' / ' . ($context->adGroups[$k['ad_group_id']]['name'] ?? '—'), $group);
			$leaks[] = new Leak(
				'BL204', 'keywords',
				sprintf('Zdublowane słowo kluczowe: „%s”', $group[0]['text']),
				sprintf('To samo słowo z tym samym dopasowaniem jest w %d grupach reklam – grupy konkurują o te same zapytania, a statystyki rozpraszają się.', count($group)),
				'Zostaw słowo w jednej, najlepiej dopasowanej grupie albo dodaj wykluczenia między grupami.',
				Statistics::HIGH, 6,
				'keyword_duplicate', $group[0]['id'], $group[0]['campaign_id'], null, $group[0]['text'], $cost, array_sum(array_column($group, 'clicks')), null, null,
				evidence: [['Miejsca', implode('; ', $places)], ['Koszt łącznie', Format::money($cost, $context->currency)]],
				impactOverride: 5,
			);
		}

		return $leaks;
	}

	/**
	 * @return array<int, Leak>
	 */
	private function campaigns(LeakContext $context): array
	{
		$leaks = [];
		$total = $context->totalCost();
		$goalRate = $context->goalRate();
		$accountGoals = $context->accountGoals();
		$budgetForPeriod = $context->monthlyBudgetMicros ? (int) round($context->monthlyBudgetMicros * $context->days() / 30.4) : null;

		foreach ($context->campaigns as $id => $campaign) {
			$cost = (int) ($campaign['current']['cost_micros'] ?? 0);
			$clicks = (int) ($campaign['current']['clicks'] ?? 0);
			$google = $campaign['current']['conversions'] ?? null;

			if ($cost <= 0) {
				continue;
			}

			$goals = $context->campaignGoals($id);
			$share = $cost / max(1, $total);
			$base = ['entityType' => 'campaign', 'entityId' => $id, 'campaignId' => $id, 'subject' => (string) $campaign['name'], 'costMicros' => $cost, 'clicks' => $clicks, 'conversions' => $google === null ? null : (float) $google, 'valuableConversions' => $goals];
			$evidence = [
				['Kampania', (string) $campaign['name']],
				['Wydatki w okresie', Format::money($cost, $context->currency) . ' (' . Format::percent($share * 100, 0) . ' wydatków)'],
				['Kliknięcia', Format::number($clicks)],
				['Konwersje Google Ads', Format::conversions($google)],
				['Konwersje celów biznesowych', $goals === null ? 'Brak danych per kampania' : Format::conversions($goals)],
			];
			$significant = $share >= 0.15 || ($budgetForPeriod && $cost >= $budgetForPeriod * 0.1);

			// BL301: kampania bez wartościowych konwersji.
			if ($significant && $clicks >= self::MIN_CLICKS * 3) {
				if ($goals !== null && $goals == 0) {
					$confidence = $goalRate !== null ? Statistics::confidenceForZero($clicks, $goalRate) : Statistics::LOW;
					$leaks[] = new Leak(
						'BL301', 'campaigns',
						sprintf('Kampania bez wartościowych konwersji: „%s”', $campaign['name']),
						sprintf('Kampania wydała %s (%s budżetu okresu) i nie przyniosła żadnej konwersji oznaczonej jako cel biznesowy%s.', Format::money($cost, $context->currency), Format::percent($share * 100, 0), (float) $google > 0 ? sprintf(' – jej %s konwersji Google Ads to inne działania (np. mikrokonwersje)', Format::conversions($google)) : ''),
						'Sprawdź, czy kampania kieruje na właściwych odbiorców i czy jej konwersje są mierzone poprawnie. Rozważ ograniczenie budżetu do czasu wyjaśnienia.',
						$confidence, 16,
						...$base,
						savingsMicros: $confidence !== Statistics::LOW ? Statistics::monthlyRunRate($cost, $context->days()) : null,
						evidence: $evidence,
					);
				} elseif ($goals === null && $google !== null && (float) $google == 0) {
					$confidence = Statistics::confidenceForZero($clicks, $context->conversionRate());
					$leaks[] = new Leak(
						'BL301', 'campaigns',
						sprintf('Kampania bez konwersji: „%s”', $campaign['name']),
						sprintf('Kampania wydała %s (%s budżetu okresu) bez żadnej konwersji Google Ads. Brak danych o konwersjach per działanie – nie wiemy, które cele by liczyła.', Format::money($cost, $context->currency), Format::percent($share * 100, 0)),
						'Sprawdź kierowanie, wyszukiwane hasła i pomiar konwersji kampanii.',
						$confidence === Statistics::HIGH ? Statistics::MEDIUM : $confidence, 16,
						...$base,
						savingsMicros: $confidence !== Statistics::LOW ? Statistics::monthlyRunRate($cost, $context->days()) : null,
						evidence: $evidence,
					);
				}
			}

			// BL302: nieproporcjonalne wykorzystanie budżetu względem udziału w celach biznesowych.
			if ($goals !== null && $goals > 0 && $accountGoals !== null && $accountGoals >= 5 && $share >= 0.2) {
				$goalShare = $goals / $accountGoals;

				if ($share >= 1.5 * $goalShare) {
					$leaks[] = new Leak(
						'BL302', 'campaigns',
						sprintf('Nieproporcjonalne wydatki: „%s”', $campaign['name']),
						sprintf('Kampania odpowiada za %s wydatków, ale tylko %s konwersji celów biznesowych.', Format::percent($share * 100, 0), Format::percent($goalShare * 100, 0)),
						'Rozważ przesunięcie części budżetu do kampanii o niższym koszcie pozyskania celu.',
						$accountGoals >= 20 ? Statistics::MEDIUM : Statistics::LOW, 14,
						...$base,
						evidence: [...$evidence, ['Udział w celach biznesowych', Format::percent($goalShare * 100, 0)]],
					);
				}
			}

			// BL303: pogarszająca się efektywność względem poprzedniego okresu.
			$prev = $campaign['previous'] ?? [];
			$prevConversions = (float) ($prev['conversions'] ?? 0);
			$currConversions = (float) ($google ?? 0);

			if ($prevConversions >= 5 && $currConversions >= 5 && ($prev['cost_micros'] ?? 0) > 0) {
				$prevCpa = $prev['cost_micros'] / $prevConversions;
				$currCpa = $cost / $currConversions;

				if ($currCpa >= 1.5 * $prevCpa) {
					$leaks[] = new Leak(
						'BL303', 'campaigns',
						sprintf('Pogarszająca się efektywność: „%s”', $campaign['name']),
						sprintf('Koszt konwersji wzrósł z %s do %s (+%s) względem poprzedniego okresu tej samej długości.', Format::money((int) round($prevCpa), $context->currency), Format::money((int) round($currCpa), $context->currency), Format::percent(($currCpa / $prevCpa - 1) * 100, 0)),
						'Sprawdź zmiany w kampanii (stawki, budżet, słowa kluczowe, reklamy) i konkurencję w aukcjach. Uwzględnij opóźnienie konwersji w ostatnich dniach.',
						min($prevConversions, $currConversions) >= 15 ? Statistics::MEDIUM : Statistics::LOW, 12,
						...$base,
						evidence: [...$evidence, ['Koszt konwersji – poprzedni okres', Format::money((int) round($prevCpa), $context->currency)]],
					);
				}
			}

			// BL304: ustawienia, które mogą kierować budżet na ruch słabszej jakości.
			$networks = $campaign['settings']['networks'] ?? [];

			if (($campaign['channel_type'] ?? null) === 'SEARCH' && (! empty($networks['display']) || ! empty($networks['search_partners']))) {
				$what = array_values(array_filter([! empty($networks['display']) ? 'sieć reklamowa' : null, ! empty($networks['search_partners']) ? 'partnerzy wyszukiwania' : null]));
				$leaks[] = new Leak(
					'BL304', 'campaigns',
					sprintf('Ustawienia sieci do sprawdzenia: „%s”', $campaign['name']),
					sprintf('Kampania w sieci wyszukiwania korzysta też z: %s. Bez danych o podziale kosztów na sieci nie oceniamy, ile to kosztuje.', implode(', ', $what)),
					'Porównaj w Google Ads wyniki segmentu „Sieć”. Wyłącz sieć, która ma wyraźnie wyższy koszt konwersji.',
					Statistics::LOW, 8,
					...array_merge($base, ['subject' => implode(', ', $what)]),
					evidence: $evidence,
					impactOverride: 10,
				);
			}
		}

		return $leaks;
	}

	/**
	 * @return array<int, Leak>
	 */
	private function measurement(LeakContext $context): array
	{
		$leaks = [];
		$account = $context->breakdown['account'] ?? [];
		$google = $account['google'] ?? null;
		$total = $context->totalCost();

		if ($total <= 0) {
			return [];
		}

		// BL401: konwersje Google Ads to głównie mikrokonwersje.
		if ($context->primaryMostlyMicro()) {
			$leaks[] = new Leak(
				'BL401', 'measurement',
				'Konwersje Google Ads to głównie mikrokonwersje',
				sprintf('%s z %s konwersji Google Ads w okresie to działania sklasyfikowane jako mikrokonwersje. Strategie stawek i raporty optymalizują pod zachowania na stronie, a nie pod zapytania.', Format::conversions($account['google_micro'] ?? 0), Format::conversions($google)),
				'Ustaw w Google Ads jako konwersje główne tylko działania będące kontaktem lub sprzedażą (zmianę wprowadzasz samodzielnie). Mikrokonwersje zostaw jako dodatkowe.',
				Statistics::HIGH, 20,
				subject: 'micro_primary',
				evidence: [['Konwersje Google Ads', Format::conversions($google)], ['W tym mikrokonwersje', Format::conversions($account['google_micro'] ?? 0)]],
				impactOverride: 30,
			);
		}

		// BL402: brak zdefiniowanych celów biznesowych mimo konwersji.
		$hasGoals = (bool) array_filter($context->breakdown['actions'] ?? [], fn ($a) => $a['effective_goal'] && ($a['status'] ?? null) !== 'removed');

		if (! $hasGoals && ($context->breakdown['actions'] ?? []) !== []) {
			$leaks[] = new Leak(
				'BL402', 'measurement',
				'Brak działań oznaczonych jako cel biznesowy',
				'Żadne działanie konwersji nie jest oznaczone jako cel biznesowy, więc nie da się odróżnić wartościowych konwersji od pozostałych.',
				'Oznacz w „Klasyfikacji konwersji”, które działania są leadami lub sprzedażą.',
				Statistics::HIGH, 18,
				subject: 'no_goals',
				impactOverride: 25,
			);
		}

		// BL403: cele biznesowe oparte głównie na potencjalnych kontaktach (kliknięcia e-mail/telefon).
		$goalActions = array_filter($context->breakdown['actions'] ?? [], fn ($a) => $a['effective_goal']);
		$potentialGoals = array_filter($goalActions, fn ($a) => $a['effective_class'] === 'potential_lead');

		if ($potentialGoals !== [] && ($account['goals'] ?? 0) > 0 && (float) ($account['potential'] ?? 0) / max(0.0001, (float) $account['goals']) >= 0.5) {
			$leaks[] = new Leak(
				'BL403', 'measurement',
				'Cele biznesowe to głównie potencjalne kontakty',
				'Większość konwersji liczonych jako cel to kliknięcia e-maila, numeru telefonu lub połączenia – nie każde z nich jest zapytaniem od klienta.',
				'Traktuj te konwersje ostrożnie. Jeśli to możliwe, mierz połączenia o minimalnym czasie trwania lub importuj zakwalifikowane leady z CRM.',
				Statistics::MEDIUM, 14,
				subject: 'potential_goals',
				evidence: [['Działania', implode(', ', array_map(fn ($a) => $a['name'], $potentialGoals))]],
				impactOverride: 15,
			);
		}

		// BL404: niewiarygodne dane konwersji (więcej konwersji niż kliknięć).
		foreach ($context->campaigns as $id => $campaign) {
			$clicks = (int) ($campaign['current']['clicks'] ?? 0);
			$conversions = (float) ($campaign['current']['conversions'] ?? 0);

			if ($clicks >= 20 && $conversions > $clicks * 0.5) {
				$leaks[] = new Leak(
					'BL404', 'measurement',
					sprintf('Podejrzanie wysoki współczynnik konwersji: „%s”', $campaign['name']),
					sprintf('%s konwersji przy %s kliknięciach (%s). Zwykle oznacza to liczenie wielu konwersji na kliknięcie albo mikrokonwersje jako główne.', Format::conversions($conversions), Format::number($clicks), Format::percent($conversions / $clicks * 100, 0)),
					'Sprawdź ustawienie „Liczenie” (jedna/każda) i które działania są konwersjami głównymi.',
					Statistics::MEDIUM, 14,
					'campaign', $id, $id, null, 'unreliable',
					evidence: [['Konwersje', Format::conversions($conversions)], ['Kliknięcia', Format::number($clicks)]],
					impactOverride: 12,
				);
			}
		}

		// BL405: zbyt mało danych do oceny.
		$goals = $account['goals'] ?? null;

		if ($context->totalClicks() < 200 || ($goals !== null && $goals < 5)) {
			$leaks[] = new Leak(
				'BL405', 'measurement',
				'Mało danych do oceny',
				sprintf('W okresie było %s kliknięć i %s konwersji celów biznesowych. Przy tak małej próbie większość wniosków ma niską pewność.', Format::number($context->totalClicks()), $goals === null ? 'brak danych o' : Format::conversions($goals)),
				'Wybierz dłuższy okres analizy (np. 6 lub 12 miesięcy), zanim podejmiesz decyzje o wyłączaniu fraz.',
				Statistics::HIGH, 5,
				subject: 'low_data',
				impactOverride: 0,
			);
		}

		return $leaks;
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function termEvidence(LeakContext $context, array $term, ?float $rate, ?array $extra = null): array
	{
		return array_values(array_filter([
			['Zapytanie', $term['term']],
			['Kampania', $context->campaignName($term['campaign_id'])],
			['Grupa reklam', $context->adGroups[$term['ad_group_id']]['name'] ?? '—'],
			['Słowo kluczowe', $term['keyword_text'] ? $term['keyword_text'] . ($term['match_type'] ? ' (' . $term['match_type'] . ')' : '') : '—'],
			['Okres', $context->period->range->label()],
			['Koszt', Format::money($term['cost_micros'], $context->currency)],
			['Kliknięcia', Format::number($term['clicks'])],
			['Konwersje (Google Ads)', '0'],
			['Wartościowe konwersje', 'Brak danych na poziomie hasła (Google Ads nie rozbija konwersji haseł na działania)'],
			['Typowy współczynnik konwersji kampanii', $rate !== null ? Format::percent($rate * 100) : 'brak danych'],
			['Prawdopodobieństwo braku konwersji przez przypadek', ($p = Statistics::zeroConversionProbability($term['clicks'], $rate)) !== null ? Format::percent($p * 100, 0) : 'nie do oszacowania'],
			$extra,
		]));
	}

	/**
	 * @return array<int, array{0: int, 1: int}> id hasła => [id kampanii, miesięczny koszt]
	 */
	private function termRates(array $terms, int $days): array
	{
		$rates = [];

		foreach ($terms as $term) {
			$rates[$term['id']] = [$term['campaign_id'], Statistics::monthlyRunRate($term['cost_micros'], $days)];
		}

		return $rates;
	}

	/**
	 * @return array<int, string>
	 */
	private function offerStems(LeakContext $context): array
	{
		$stems = [];

		foreach ($context->context->offerTexts() as $text) {
			array_push($stems, ...Text::stems($text));
		}

		return array_values(array_unique($stems));
	}

	/**
	 * @return array<int, array<int, string>> rdzenie słów kluczowych według grupy reklam
	 */
	private function keywordStemsByAdGroup(LeakContext $context): array
	{
		$stems = [];

		foreach ($context->keywords as $keyword) {
			$group = $keyword['ad_group_id'] ?? 0;
			$stems[$group] = array_values(array_unique(array_merge($stems[$group] ?? [], Text::stems($keyword['text']))));
		}

		return $stems;
	}
}
