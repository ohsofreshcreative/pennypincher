<?php

namespace App\PennyPincher\Domain\Keywords;

use App\PennyPincher\Domain\Analysis\Text;

/**
 * Słownik pojęć (synonimy i odmiany) do porównywania fraz bez AI: „cena”, „koszt”, „ile kosztuje” → `price`,
 * „tworzenie”, „stworzenie”, „wykonanie” → `create`, „strona”, „witryna”, „www” → `website` itd.
 *
 * Wzorce to prefiksy po normalizacji (bez polskich znaków). Słowa spoza słownika zostają jako własne pojęcie
 * (rdzeń 5 znaków), więc nieznana branża nadal działa – tylko bez synonimów.
 * Słownik można rozszerzyć bez zmian w algorytmie (klastry, ocena), a w przyszłości zastąpić modelem semantycznym
 * implementującym `PhraseSimilarity`.
 */
final class Concepts
{
	/** Pojęcia modyfikujące intencję – frazy różniące się nimi trafiają do różnych klastrów (np. „cena” vs bez ceny). */
	public const MODIFIERS = ['price', 'cheap', 'free', 'local'];

	public const DICTIONARY = [
		'price' => ['cena', 'ceny', 'cen', 'cennik', 'koszt', 'kosztu', 'koszty', 'kosztuje', 'kosztow', 'ile kosztuje', 'ile kosztow', 'wycen', 'stawk', 'price', 'pricing', 'cost', 'costs', 'quote', 'rate'],
		'cheap' => ['tani', 'tania', 'tanie', 'tanio', 'taniej', 'tanich', 'niedrog', 'budzetow', 'cheap', 'affordable', 'budget'],
		'free' => ['darmow', 'za darmo', 'bezplatn', 'gratis', 'free'],
		'create' => ['tworzen', 'stworzen', 'stworzyc', 'tworzyc', 'zrobien', 'zrobic', 'robien', 'wykonan', 'wykonac', 'budow', 'zbudow', 'zalozen', 'zalozyc', 'postawien', 'wdrozen', 'create', 'creation', 'build', 'building', 'development', 'develop', 'make'],
		'design' => ['projektow', 'zaprojekt', 'projekt', 'design', 'designer', 'grafik', 'graficzn'],
		'redesign' => ['redesign', 'przebudow', 'odswiezen', 'odswiezyc', 'modernizac', 'przeprojekt', 'rebranding', 'migracj'],
		'website' => ['strona', 'strony', 'stron', 'stronie', 'strone', 'stronka', 'witryn', 'www', 'website', 'websites', 'web', 'internetow', 'serwis', 'portal', 'site'],
		'shop' => ['sklep', 'sklepy', 'sklepu', 'sklepow', 'ecommerce', 'e commerce', 'commerce', 'woocommerce', 'shopify', 'prestashop', 'shop', 'store'],
		'landing' => ['landing', 'landing page', 'strona docelow', 'one page', 'onepage', 'wizytowk'],
		'ux' => ['ux', 'ui', 'uxui', 'user experience', 'interfejs', 'uzytecznosc', 'usability', 'prototyp'],
		'company' => ['firm', 'firmow', 'przedsiebiorstw', 'przedsiebiorc', 'biznes', 'b2b', 'business', 'company', 'companies', 'msp', 'startup'],
		'agency' => ['agencj', 'studio', 'software house', 'agency', 'freelancer', 'wykonawc'],
		'seo' => ['seo', 'pozycjonowan', 'pozycjonowac', 'widocznosc'],
		'wordpress' => ['wordpress', 'wp'],
		'mobile' => ['responsywn', 'mobiln', 'mobile', 'aplikacj', 'app'],
		'service' => ['uslug', 'uslugi', 'service', 'services', 'oferta', 'oferty', 'zlecen', 'zlecic', 'zamow'],
		'local' => ['warszaw', 'krakow', 'wroclaw', 'poznan', 'gdansk', 'gdyni', 'lodz', 'katowic', 'lublin', 'szczecin', 'bydgoszcz', 'bialystok', 'rzeszow', 'torun', 'mazowsz', 'slask', 'malopolsk', 'pomorz', 'blisko mnie', 'w poblizu', 'near me', 'okolic'],
	];

	public const LABELS = [
		'price' => 'cena', 'cheap' => 'tanio', 'free' => 'za darmo', 'create' => 'tworzenie', 'design' => 'projektowanie',
		'redesign' => 'redesign', 'website' => 'strona WWW', 'shop' => 'sklep internetowy', 'landing' => 'landing page',
		'ux' => 'UX/UI', 'company' => 'dla firm', 'agency' => 'agencja/wykonawca', 'seo' => 'SEO', 'wordpress' => 'WordPress',
		'mobile' => 'mobile', 'service' => 'usługa', 'local' => 'lokalnie',
	];

	/**
	 * Pojęcia frazy (posortowane, unikalne). Wielowyrazowe wzorce są sprawdzane przed pojedynczymi słowami.
	 *
	 * @return array<int, string>
	 */
	public static function of(string $phrase): array
	{
		$normalized = ' ' . Text::normalize($phrase) . ' ';
		$concepts = [];
		$covered = [];

		// 1. Wzorce wielowyrazowe (np. „ile kosztuje”, „software house”).
		foreach (self::DICTIONARY as $concept => $patterns) {
			foreach ($patterns as $pattern) {
				if (str_contains($pattern, ' ') && str_contains($normalized, ' ' . $pattern)) {
					$concepts[] = $concept;
					array_push($covered, ...explode(' ', $pattern));
				}
			}
		}

		// 2. Pojedyncze słowa: pojęcie ze słownika albo rdzeń słowa.
		foreach (Text::tokens($phrase) as $token) {
			if (in_array($token, $covered, true) || preg_match('/^\d+$/', $token) || in_array($token, ['cm', 'mm', 'm', 'kg', 'x', 'szt', 'zl', 'pln'], true)) {
				continue; // liczby i jednostki nie zmieniają znaczenia frazy
			}

			$concept = self::conceptForToken($token);

			if ($concept !== null) {
				$concepts[] = $concept;
			} elseif (! in_array($token, ['ile', 'jaka', 'jaki', 'jakie', 'najlepsza', 'najlepszy', 'najlepsze'], true)) {
				$concepts[] = '~' . self::stem($token);
			}
		}

		$concepts = array_values(array_unique($concepts));
		sort($concepts);

		return $concepts;
	}

	/**
	 * Krótszy rdzeń niż Text::stem (odmiana: „meble” / „meblowy” → „mebl”, „stołu” / „stół” → „stol”).
	 */
	public static function stem(string $token): string
	{
		$length = mb_strlen($token);

		return match (true) {
			$length > 4 => mb_substr($token, 0, 4),
			$length === 4 && preg_match('/[aeiouy]$/', $token) === 1 => mb_substr($token, 0, 3), // „sofa” / „sofy”
			default => $token,
		};
	}

	public static function conceptForToken(string $token): ?string
	{
		foreach (self::DICTIONARY as $concept => $patterns) {
			foreach ($patterns as $pattern) {
				if (str_contains($pattern, ' ')) {
					continue;
				}

				$match = mb_strlen($pattern) <= 3 ? $token === $pattern : str_starts_with($token, $pattern);

				if ($match) {
					return $concept;
				}
			}
		}

		return null;
	}

	/**
	 * Czytelna etykieta zestawu pojęć (do opisu tematu klastra).
	 *
	 * @param array<int, string> $concepts
	 */
	public static function label(array $concepts): string
	{
		$labels = array_map(fn ($c) => self::LABELS[$c] ?? null, $concepts);

		return implode(' · ', array_filter($labels)) ?: '—';
	}
}
