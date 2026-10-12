<?php

namespace App\PennyPincher\Domain\Keywords;

use App\PennyPincher\Domain\Analysis\Statistics;

/**
 * Opportunity Score 2.0 – atrakcyjność biznesowa frazy (0–100) oddzielona od CONFIDENCE (wiarygodność danych, 0–100).
 *
 * Różnice względem 1.0 (`OpportunityScore`, wersja 1.0 – zostaje dla historycznych wyników):
 * - więcej składników: zgodność z grupą docelową, historyczne wyniki Google Ads, konkurencja,
 * - wartości ciągłe (dopasowanie liczone na pojęciach z synonimami, wyniki na współczynniku konwersji),
 * - CPC nagradza tylko frazy o dobrej intencji i dopasowaniu – tania, słaba fraza nie dostaje punktów,
 * - kary za sygnały nietrafności (inny język, nazwa firmy, marka konkurencji, brak związku z ofertą),
 * - brak danych = 0 pkt danego składnika i niższa pewność (nigdy wartość zastępcza; brak CPC ≠ CPC 0,
 *   brak wolumenu ≠ brak popytu).
 *
 * Wagi i kary są wyłącznie tutaj (nie w widokach). Zmiana algorytmu = nowa VERSION; wyniki zapisywane są z wersją,
 * więc historyczne oceny pozostają nietknięte w przebiegach analiz.
 */
final class OpportunityScoreV2
{
	public const VERSION = '2.0';

	public const WEIGHTS = [
		'offer_fit' => 25,
		'intent' => 20,
		'performance' => 15,
		'audience' => 10,
		'coverage' => 10,
		'cpc' => 8,
		'volume' => 7,
		'competition' => 5,
	];

	public const LABELS = [
		'offer_fit' => 'Dopasowanie do oferty',
		'intent' => 'Intencja zakupowa',
		'performance' => 'Historyczne wyniki w Google Ads',
		'audience' => 'Zgodność z grupą docelową',
		'coverage' => 'Brak w obecnych kampaniach',
		'cpc' => 'Koszt kliknięcia (przy dobrej intencji)',
		'volume' => 'Popularność wyszukiwań',
		'competition' => 'Konkurencja reklamodawców',
	];

	public const PENALTIES = [
		PhraseQuality::WRONG_LANGUAGE => 25,
		PhraseQuality::COMPANY_NAME => 25,
		PhraseQuality::COMPETITOR_BRAND => 15,
		PhraseQuality::UNRELATED => 15,
		PhraseQuality::INFORMATIONAL => 10,
	];

	/**
	 * @param array<string, ?float> $components wartości 0–1 albo null (brak danych)
	 * @param array<int, string> $flags flagi PhraseQuality
	 * @param array{term_clicks?: ?int, measurement_reliable?: bool, intent_known?: bool, cpc_comparable?: bool} $data
	 * @return array{score: int, version: string, breakdown: array, confidence: string, confidence_score: int, strengths: array, risks: array, missing: array, action: string, penalties: array}
	 */
	public static function calculate(array $components, array $flags = [], array $data = []): array
	{
		$breakdown = [];
		$score = 0.0;
		$missing = [];
		$intent = $components['intent'] ?? null;
		$fit = $components['offer_fit'] ?? null;

		foreach (self::WEIGHTS as $key => $weight) {
			$value = $components[$key] ?? null;
			$value = $value === null ? null : max(0.0, min(1.0, (float) $value));
			$effective = $value;

			if ($key === 'cpc' && $value !== null) {
				// Niski CPC liczy się tylko przy dobrej intencji i dopasowaniu do oferty.
				$effective = $value * min(1.0, ($intent ?? 0.0) / 0.75) * ($fit ?? 0.5);
			}

			$points = $effective === null ? 0.0 : round($effective * $weight, 1);
			$score += $points;
			$breakdown[$key] = ['value' => $value, 'points' => $points, 'max' => $weight];

			if ($value === null) {
				$missing[] = self::LABELS[$key];
			}
		}

		$penalties = [];

		foreach ($flags as $flag) {
			if (isset(self::PENALTIES[$flag])) {
				$penalties[$flag] = self::PENALTIES[$flag];
				$score -= self::PENALTIES[$flag];
			}
		}

		$score = (int) max(0, min(100, round($score)));
		$confidenceScore = self::confidence($components, $data);
		$confidence = $confidenceScore >= 70 ? Statistics::HIGH : ($confidenceScore >= 40 ? Statistics::MEDIUM : Statistics::LOW);

		$strengths = [];
		$risks = [];

		foreach ($breakdown as $key => $part) {
			if ($part['value'] === null) {
				continue;
			}

			if ($part['value'] >= 0.7 && $part['points'] >= 0.6 * $part['max']) {
				$strengths[] = self::LABELS[$key] . ': wysoko (' . self::fmt($part['points']) . '/' . $part['max'] . ' pkt)';
			} elseif ($part['value'] <= 0.3) {
				$risks[] = self::LABELS[$key] . ': nisko (' . self::fmt($part['points']) . '/' . $part['max'] . ' pkt)';
			}
		}

		if (($components['cpc'] ?? null) !== null && $breakdown['cpc']['points'] < 0.5 * $breakdown['cpc']['max'] * (float) $components['cpc'] && (float) $components['cpc'] >= 0.7) {
			$risks[] = 'Niski CPC nie podnosi oceny, bo intencja lub dopasowanie do oferty są słabe';
		}

		foreach ($penalties as $flag => $points) {
			$risks[] = ucfirst(PhraseQuality::LABELS[$flag]) . " (−{$points} pkt)";
		}

		if (in_array(PhraseQuality::OWN_BRAND, $flags, true)) {
			$strengths[] = 'Fraza z własną marką – odpowiednia do kampanii brandowej';
		}

		if (($data['measurement_reliable'] ?? true) === false && ($components['performance'] ?? null) !== null) {
			$risks[] = 'Historyczne konwersje mogą obejmować mikrokonwersje – wyniki traktuj ostrożnie';
		}

		return [
			'score' => $score,
			'version' => self::VERSION,
			'breakdown' => $breakdown,
			'confidence' => $confidence,
			'confidence_score' => $confidenceScore,
			'strengths' => $strengths,
			'risks' => $risks,
			'missing' => $missing,
			'penalties' => $penalties,
			'action' => self::action($score, $confidenceScore, $flags),
		];
	}

	/**
	 * Wiarygodność danych 0–100: kompletność składników, wielkość próby historycznej, jakość pomiaru konwersji.
	 */
	public static function confidence(array $components, array $data = []): int
	{
		$points = 0;
		$points += ($components['offer_fit'] ?? null) !== null ? 15 : 0;
		$points += ($data['intent_known'] ?? (($components['intent'] ?? null) !== null && (float) $components['intent'] !== 0.5)) ? 10 : 0;
		$clicks = $data['term_clicks'] ?? null;

		if (($components['performance'] ?? null) !== null) {
			$points += $clicks === null ? 10 : ($clicks >= 50 ? 25 : ($clicks >= 10 ? 15 : 5));
		}

		$points += ($components['audience'] ?? null) !== null ? 10 : 0;
		$points += ($components['cpc'] ?? null) !== null ? 10 : 0;
		$points += ($components['volume'] ?? null) !== null ? 15 : 0;
		$points += ($components['competition'] ?? null) !== null ? 5 : 0;
		$points += ($data['measurement_reliable'] ?? false) ? 10 : 0;

		return min(100, $points);
	}

	/**
	 * Wynik historyczny frazy w Google Ads (0–1) na tle współczynnika konwersji konta/kampanii; null bez historii.
	 */
	public static function performance(?int $clicks, ?float $conversions, ?float $referenceRate): ?float
	{
		if ($clicks === null || $clicks <= 0 || $conversions === null) {
			return null;
		}

		if ($clicks < 10) {
			return $conversions > 0 ? 0.65 : 0.45; // mała próba – blisko neutralnego
		}

		if ($conversions > 0) {
			if ($referenceRate === null || $referenceRate <= 0) {
				return 0.8;
			}

			return max(0.4, min(1.0, 0.35 + 0.4 * min(2.0, ($conversions / $clicks) / $referenceRate)));
		}

		if ($referenceRate === null || $referenceRate <= 0) {
			return 0.3;
		}

		// Zero konwersji: im mniej prawdopodobne przy typowym współczynniku, tym niżej.
		return round(0.35 * ((1 - $referenceRate) ** $clicks), 3);
	}

	public static function intent(string $primaryIntent, string $phrase = ''): float
	{
		$base = match ($primaryIntent) {
			'commercial' => 0.85,
			'unknown' => 0.5,
			'informational' => 0.15,
			default => 0.0,
		};

		// Silne sygnały transakcyjne (cena, wycena, zamówienie, firma/agencja wykonująca) podnoszą intencję.
		$concepts = Concepts::of($phrase);

		if ($primaryIntent !== 'informational' && array_intersect($concepts, ['price', 'agency', 'service']) !== []) {
			$base = min(1.0, $base + 0.15);
		}

		return $base;
	}

	public static function competition(?string $level): ?float
	{
		return match ($level) {
			'LOW' => 1.0,
			'MEDIUM' => 0.6,
			'HIGH' => 0.3,
			default => null,
		};
	}

	private static function action(int $score, int $confidence, array $flags): string
	{
		if (array_intersect($flags, [PhraseQuality::WRONG_LANGUAGE, PhraseQuality::COMPANY_NAME]) !== []) {
			return 'Prawdopodobnie nietrafna fraza – odrzuć, chyba że świadomie jej potrzebujesz.';
		}

		if (in_array(PhraseQuality::COMPETITOR_BRAND, $flags, true)) {
			return 'Fraza z marką konkurencji – używaj tylko świadomie, w osobnej kampanii.';
		}

		return match (true) {
			$score >= 65 && $confidence >= 40 => 'Dodaj do planu kampanii (dopasowanie do wyrażenia lub ścisłe) i obserwuj wyszukiwane hasła przez 2–4 tygodnie.',
			$score >= 65 => 'Wygląda obiecująco, ale danych jest mało – przetestuj z niewielkim budżetem.',
			$score >= 45 => 'Sprawdź ręcznie: jeśli pasuje do oferty, rozważ test w osobnej grupie reklam.',
			default => 'Niski priorytet – obserwuj, nie dodawaj teraz.',
		};
	}

	private static function fmt(float $value): string
	{
		return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
	}
}
