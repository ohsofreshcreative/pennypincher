<?php

namespace App\PennyPincher\Domain\Planner;

/**
 * Walidacja elastycznej reklamy w wyszukiwarce (RSA) według limitów Google Ads:
 * 3–15 nagłówków po maks. 30 znaków, 2–4 opisy po maks. 90 znaków, ścieżki URL po maks. 15 znaków,
 * końcowy URL http(s). Znaki o podwójnej szerokości (np. CJK) liczą się podwójnie – jak w Google Ads.
 *
 * To kontrola formalna, nie weryfikacja zgodności z zasadami reklamowymi Google (te sprawdza Google przy publikacji).
 */
final class AdValidator
{
	public const HEADLINE_MAX = 30;

	public const DESCRIPTION_MAX = 90;

	public const PATH_MAX = 15;

	public const HEADLINES = [3, 15];

	public const DESCRIPTIONS = [2, 4];

	/**
	 * @return array{errors: array<string, string>, warnings: array<int, string>}
	 */
	public static function validate(array $headlines, array $descriptions, ?string $path1, ?string $path2, ?string $finalUrl): array
	{
		$errors = [];
		$warnings = [];
		$headlines = array_values(array_filter(array_map('trim', $headlines), fn ($h) => $h !== ''));
		$descriptions = array_values(array_filter(array_map('trim', $descriptions), fn ($d) => $d !== ''));

		foreach ($headlines as $i => $headline) {
			if (self::length($headline) > self::HEADLINE_MAX) {
				$errors["headline_{$i}"] = sprintf('Nagłówek %d ma %d znaków (maks. %d).', $i + 1, self::length($headline), self::HEADLINE_MAX);
			}

			if (str_contains($headline, '!')) {
				$warnings[] = sprintf('Nagłówek %d zawiera „!” – Google Ads nie dopuszcza wykrzyknika w nagłówkach.', $i + 1);
			}
		}

		foreach ($descriptions as $i => $description) {
			if (self::length($description) > self::DESCRIPTION_MAX) {
				$errors["description_{$i}"] = sprintf('Opis %d ma %d znaków (maks. %d).', $i + 1, self::length($description), self::DESCRIPTION_MAX);
			}
		}

		if (count($headlines) > self::HEADLINES[1]) {
			$errors['headlines'] = 'Maksymalnie 15 nagłówków.';
		}

		if (count($descriptions) > self::DESCRIPTIONS[1]) {
			$errors['descriptions'] = 'Maksymalnie 4 opisy.';
		}

		if (count($headlines) < self::HEADLINES[0]) {
			$warnings[] = sprintf('Reklama wymaga min. %d nagłówków (jest %d) – szkic można zapisać, ale nie jest kompletny.', self::HEADLINES[0], count($headlines));
		}

		if (count($descriptions) < self::DESCRIPTIONS[0]) {
			$warnings[] = sprintf('Reklama wymaga min. %d opisów (jest %d).', self::DESCRIPTIONS[0], count($descriptions));
		}

		$lower = array_map('mb_strtolower', $headlines);

		if (count($lower) !== count(array_unique($lower))) {
			$warnings[] = 'Powtarzające się nagłówki – Google pokazuje tylko unikalne.';
		}

		foreach (['path1' => $path1, 'path2' => $path2] as $field => $path) {
			if ($path !== null && $path !== '' && self::length($path) > self::PATH_MAX) {
				$errors[$field] = sprintf('Ścieżka ma %d znaków (maks. %d).', self::length($path), self::PATH_MAX);
			}

			if ($path !== null && preg_match('/[\s\/]/u', $path)) {
				$errors[$field] = 'Ścieżka nie może zawierać spacji ani ukośników.';
			}
		}

		if (($path2 ?? '') !== '' && ($path1 ?? '') === '') {
			$errors['path2'] = 'Ścieżka 2 wymaga uzupełnienia ścieżki 1.';
		}

		if ($finalUrl !== null && $finalUrl !== '' && (! filter_var($finalUrl, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($finalUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
			$errors['final_url'] = 'Podaj poprawny adres strony docelowej (https://…).';
		}

		foreach ([...$headlines, ...$descriptions] as $text) {
			if (preg_match('/\b\p{Lu}{4,}\b/u', $text)) {
				$warnings[] = 'Słowa pisane w całości wielkimi literami mogą zostać odrzucone przez Google (nadmierne użycie wielkich liter).';
				break;
			}
		}

		return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
	}

	/**
	 * Długość w rozumieniu Google Ads: znaki o podwójnej szerokości liczą się jako 2.
	 */
	public static function length(string $text): int
	{
		$length = 0;

		foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
			$length += preg_match('/[\x{1100}-\x{115F}\x{2E80}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}\x{FE30}-\x{FE4F}\x{FF00}-\x{FF60}\x{FFE0}-\x{FFE6}]/u', $char) ? 2 : 1;
		}

		return $length;
	}
}
