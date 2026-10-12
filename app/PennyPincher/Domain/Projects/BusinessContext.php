<?php

namespace App\PennyPincher\Domain\Projects;

/**
 * Kontekst biznesowy projektu (co firma sprzedaje i komu). Wszystkie pola są opcjonalne;
 * im więcej uzupełnionych, tym trafniejsze oceny dopasowania fraz i wykluczeń.
 *
 * Dane należą do konkretnego projektu – aplikacja nie zawiera żadnych założeń o branży.
 */
final class BusinessContext
{
	/** Pola przechowywane w kolumnie JSON `projects.business_context`. */
	public const FIELDS = [
		'products_services' => 'Główne produkty i usługi',
		'priority_services' => 'Usługi priorytetowe',
		'ideal_customer' => 'Klient idealny',
		'excluded_audiences' => 'Niepożądane grupy odbiorców',
		'target_locations' => 'Lokalizacje docelowe',
		'language' => 'Język',
		'advantages' => 'Najważniejsze przewagi firmy',
		'average_value_micros' => 'Średnia wartość klienta / zlecenia',
		'primary_goal' => 'Główny cel reklamowy',
		'secondary_goals' => 'Dodatkowe cele reklamowe',
		'keyword_location_code' => 'Lokalizacja dla danych o słowach kluczowych',
		'own_brand' => 'Własna marka',
		'competitor_brands' => 'Marki konkurencji',
	];

	public const GOALS = [
		'leads' => 'Pozyskiwanie zapytań (leadów)',
		'sales' => 'Sprzedaż online',
		'calls' => 'Połączenia telefoniczne',
		'store_visits' => 'Wizyty w lokalu',
		'awareness' => 'Rozpoznawalność',
		'other' => 'Inny',
	];

	public const LANGUAGES = ['pl' => 'polski', 'en' => 'angielski', 'de' => 'niemiecki', 'cs' => 'czeski', 'uk' => 'ukraiński'];

	/** Kody lokalizacji Google Ads (geo target constants) używane przez DataForSEO dla krajów. */
	public const LOCATIONS = [2616 => 'Polska', 2276 => 'Niemcy', 2826 => 'Wielka Brytania', 2840 => 'Stany Zjednoczone', 2203 => 'Czechy', 2804 => 'Ukraina'];

	/**
	 * @param array<string, mixed> $values pola JSON
	 */
	public function __construct(
		public readonly array $values = [],
		public readonly ?string $businessDescription = null,
		public readonly ?string $targetCustomer = null,
		public readonly ?string $landingPageUrl = null,
		public readonly ?int $minValueMicros = null,
		public readonly ?int $maxValueMicros = null,
	) {
	}

	public static function fromJson(?string $json, ?string $description = null, ?string $target = null, ?string $landing = null, ?int $min = null, ?int $max = null): self
	{
		$values = $json ? json_decode($json, true) : [];

		return new self(is_array($values) ? array_intersect_key($values, self::FIELDS) : [], $description, $target, $landing, $min, $max);
	}

	public function get(string $field): mixed
	{
		return $this->values[$field] ?? null;
	}

	public function language(): string
	{
		$language = (string) ($this->values['language'] ?? 'pl');

		return array_key_exists($language, self::LANGUAGES) ? $language : 'pl';
	}

	public function locationCode(): int
	{
		$code = (int) ($this->values['keyword_location_code'] ?? 2616);

		return array_key_exists($code, self::LOCATIONS) ? $code : 2616;
	}

	/**
	 * Średnia wartość klienta: podana wprost albo środek przedziału wartości projektów.
	 */
	public function averageValueMicros(): ?int
	{
		$average = $this->values['average_value_micros'] ?? null;

		if ($average !== null) {
			return (int) $average;
		}

		if ($this->minValueMicros !== null && $this->maxValueMicros !== null) {
			return (int) round(($this->minValueMicros + $this->maxValueMicros) / 2);
		}

		return $this->minValueMicros ?? $this->maxValueMicros;
	}

	/**
	 * Teksty opisujące ofertę (do oceny dopasowania fraz).
	 *
	 * @return array<int, string>
	 */
	public function offerTexts(): array
	{
		return array_values(array_filter([
			$this->values['products_services'] ?? null,
			$this->values['priority_services'] ?? null,
			$this->businessDescription,
			$this->landingPageUrl ? str_replace(['-', '/', '_'], ' ', (string) parse_url($this->landingPageUrl, PHP_URL_PATH)) : null,
		]));
	}

	/**
	 * @return array<int, string>
	 */
	public function priorityTexts(): array
	{
		return self::lines($this->values['priority_services'] ?? null);
	}

	/**
	 * Teksty opisujące grupę docelową i klienta idealnego (do oceny zgodności fraz z odbiorcami).
	 *
	 * @return array<int, string>
	 */
	public function audienceTexts(): array
	{
		return array_values(array_filter([$this->targetCustomer, $this->values['ideal_customer'] ?? null]));
	}

	/**
	 * @return array<int, string>
	 */
	public function ownBrands(): array
	{
		return self::lines($this->values['own_brand'] ?? null);
	}

	/**
	 * @return array<int, string>
	 */
	public function competitorBrands(): array
	{
		return self::lines($this->values['competitor_brands'] ?? null);
	}

	/**
	 * Frazy niepożądanych odbiorców (jedna na linię albo rozdzielone przecinkami).
	 *
	 * @return array<int, string>
	 */
	public function excludedAudiences(): array
	{
		return self::lines($this->values['excluded_audiences'] ?? null);
	}

	/**
	 * Stopień uzupełnienia kontekstu – do komunikatu o trafności rekomendacji.
	 *
	 * @return array{filled: int, total: int, missing: array<int, string>}
	 */
	public function completeness(): array
	{
		$checks = [
			'Opis firmy' => $this->businessDescription,
			'Główne produkty i usługi' => $this->values['products_services'] ?? null,
			'Usługi priorytetowe' => $this->values['priority_services'] ?? null,
			'Grupa docelowa' => $this->targetCustomer,
			'Klient idealny' => $this->values['ideal_customer'] ?? null,
			'Niepożądane grupy odbiorców' => $this->values['excluded_audiences'] ?? null,
			'Lokalizacje docelowe' => $this->values['target_locations'] ?? null,
			'Przewagi firmy' => $this->values['advantages'] ?? null,
			'Średnia wartość klienta' => $this->averageValueMicros(),
			'Główny cel reklamowy' => $this->values['primary_goal'] ?? null,
			'Strona docelowa' => $this->landingPageUrl,
		];
		$missing = array_keys(array_filter($checks, fn ($value) => $value === null || $value === ''));

		return ['filled' => count($checks) - count($missing), 'total' => count($checks), 'missing' => $missing];
	}

	/**
	 * @return array<int, string>
	 */
	public static function lines(mixed $text): array
	{
		if (! is_string($text) || trim($text) === '') {
			return [];
		}

		return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/u', $text) ?: [])));
	}
}
