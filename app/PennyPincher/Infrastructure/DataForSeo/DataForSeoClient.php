<?php

namespace App\PennyPincher\Infrastructure\DataForSeo;

/**
 * Endpointy DataForSEO używane przez Penny Pincher (sprawdzone w dokumentacji docs.dataforseo.com/v3, 12.10.2026).
 *
 * Koszt nie jest jednolity: zależy od endpointu i trybu (live/standard), a w Labs także od liczby wyników.
 * `ESTIMATES` to górny szacunek do kontroli limitu PRZED zapytaniem; po zapytaniu zapisujemy koszt z odpowiedzi (`cost`).
 */
class DataForSeoClient
{
	public const KEYWORDS_FOR_KEYWORDS = 'keywords_data/google_ads/keywords_for_keywords/live';

	/** Szacowany koszt jednego zadania (USD) – cennik DataForSEO z 10.2026; faktyczny koszt pochodzi z odpowiedzi API. */
	public const ESTIMATES = [
		self::KEYWORDS_FOR_KEYWORDS => 0.09,
	];

	/** Maksymalna liczba fraz źródłowych w jednym zadaniu keywords_for_keywords. */
	public const MAX_SEEDS = 20;

	/** Maksymalna długość frazy źródłowej (znaki). */
	public const MAX_SEED_LENGTH = 80;

	public function __construct(private DataForSeoHttp $http)
	{
	}

	/**
	 * Dane konta (saldo) – bezpłatne; używane jako test połączenia.
	 *
	 * @return array{login: ?string, balance: ?float, currency: string}
	 */
	public function userData(): array
	{
		$body = $this->http->request('GET', 'appendix/user_data');
		$result = $body['tasks'][0]['result'][0] ?? null;

		if (! is_array($result)) {
			throw DataForSeoException::of(DataForSeoException::RESPONSE, null, null, 'brak danych konta');
		}

		return [
			'login' => isset($result['login']) ? (string) $result['login'] : null,
			'balance' => isset($result['money']['balance']) && is_numeric($result['money']['balance']) ? (float) $result['money']['balance'] : null,
			'currency' => 'USD',
		];
	}

	/**
	 * Lokalizacje Google Ads dla kraju (bezpłatne) – do sprawdzenia kodu lokalizacji przed pierwszą analizą.
	 *
	 * @return array<int, array{location_code: int, location_name: string, location_type: ?string}>
	 */
	public function locations(string $countryIso): array
	{
		$country = strtolower(preg_replace('/[^a-z]/i', '', $countryIso));
		$body = $this->http->request('GET', 'keywords_data/google_ads/locations/' . $country);
		$rows = [];

		foreach ((array) ($body['tasks'][0]['result'] ?? []) as $item) {
			if (is_array($item) && isset($item['location_code'])) {
				$rows[] = ['location_code' => (int) $item['location_code'], 'location_name' => (string) ($item['location_name'] ?? ''), 'location_type' => $item['location_type'] ?? null];
			}
		}

		return $rows;
	}

	/**
	 * Zadanie keywords_for_keywords (live) – dokładnie jedno zadanie w tablicy.
	 *
	 * @param array<int, string> $seeds
	 * @return array<int, array<string, mixed>>
	 */
	public static function keywordsForKeywordsPayload(array $seeds, int $locationCode, string $languageCode): array
	{
		$seeds = array_values(array_unique(array_filter(array_map(fn ($s) => mb_substr(trim(mb_strtolower($s)), 0, self::MAX_SEED_LENGTH), $seeds), fn ($s) => $s !== '')));
		sort($seeds);

		return [[
			'keywords' => array_slice($seeds, 0, self::MAX_SEEDS),
			'location_code' => $locationCode,
			'language_code' => $languageCode,
			'sort_by' => 'relevance',
		]];
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, cost: ?float, raw: array<string, mixed>}
	 */
	public function call(string $endpoint, array $payload): array
	{
		if (! isset(self::ESTIMATES[$endpoint])) {
			throw DataForSeoException::of(DataForSeoException::CONFIG, null, null, 'nieobsługiwany endpoint');
		}

		$body = $this->http->request('POST', $endpoint, $payload);

		return ['items' => self::parseKeywordItems($body), 'cost' => isset($body['cost']) && is_numeric($body['cost']) ? (float) $body['cost'] : null, 'raw' => $body];
	}

	/**
	 * Wyniki keywords_for_keywords: płaska lista w tasks[0].result[]. CPC i stawki są w USD.
	 *
	 * @return array<int, array{keyword: string, search_volume: ?int, cpc_micros: ?int, competition: ?string, competition_index: ?int, low_bid_micros: ?int, high_bid_micros: ?int}>
	 */
	public static function parseKeywordItems(array $body): array
	{
		$items = [];
		$result = $body['tasks'][0]['result'] ?? [];

		if (! is_array($result)) {
			throw DataForSeoException::of(DataForSeoException::RESPONSE, null, null, 'nieprawidłowa lista wyników');
		}

		foreach ($result as $item) {
			if (! is_array($item) || ! isset($item['keyword']) || ! is_string($item['keyword']) || trim($item['keyword']) === '') {
				continue;
			}

			$competition = isset($item['competition']) && is_string($item['competition']) ? strtoupper($item['competition']) : null;

			$items[] = [
				'keyword' => mb_substr(trim($item['keyword']), 0, 255),
				'search_volume' => self::int($item['search_volume'] ?? null),
				'cpc_micros' => self::micros($item['cpc'] ?? null),
				'competition' => in_array($competition, ['LOW', 'MEDIUM', 'HIGH'], true) ? $competition : null,
				'competition_index' => ($index = self::int($item['competition_index'] ?? null)) !== null ? min(100, $index) : null,
				'low_bid_micros' => self::micros($item['low_top_of_page_bid'] ?? null),
				'high_bid_micros' => self::micros($item['high_top_of_page_bid'] ?? null),
			];
		}

		return $items;
	}

	private static function int(mixed $value): ?int
	{
		return is_numeric($value) && $value >= 0 ? (int) $value : null;
	}

	private static function micros(mixed $value): ?int
	{
		return is_numeric($value) && $value >= 0 ? (int) round((float) $value * 1_000_000) : null;
	}
}
