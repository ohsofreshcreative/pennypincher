<?php

namespace Tests\Support;

/**
 * Atrapa endpointów Google (OAuth i Google Ads API) przez filtr `pre_http_request`.
 *
 * Żadne żądanie nie wychodzi do sieci: nieobsłużony adres kończy się błędem WP_Error, a wszystkie
 * żądania są zapisywane (do sprawdzania nagłówków, kolejności kroków i braku wycieku sekretów).
 *
 * Testy oparte na tej atrapie NIE potwierdzają działania z prawdziwym Google Ads API.
 */
final class GoogleAdsMock
{
	public const CUSTOMER = '1234567890';

	public const MANAGER = '9999999999';

	/** @var array<int, array{url: string, method: string, headers: array, body: mixed, key: string}> */
	public array $requests = [];

	/** @var array<string, callable|array> */
	private array $handlers = [];

	private ?\Closure $filter = null;

	public function install(): self
	{
		$this->filter = fn ($preempt, $args, $url) => $this->handle((array) $args, (string) $url);
		add_filter('pre_http_request', $this->filter, 1, 3);

		return $this;
	}

	public function uninstall(): void
	{
		if ($this->filter !== null) {
			remove_filter('pre_http_request', $this->filter, 1);
			$this->filter = null;
		}
	}

	/**
	 * @param callable|array $response [status, body] albo callable(array $request): [status, body, headers?]
	 */
	public function on(string $key, callable|array $response): self
	{
		$this->handlers[$key] = $response;

		return $this;
	}

	public function count(string $key): int
	{
		return count(array_filter($this->requests, fn ($r) => $r['key'] === $key));
	}

	/**
	 * Standardowy scenariusz: tokeny, jedno konto (PLN), kampania, konwersje, metryki dzienne.
	 */
	public function withDefaultAccount(): self
	{
		return $this
			->on('token', [200, ['access_token' => 'ya29.mock-access', 'refresh_token' => '1//mock-refresh', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/adwords openid email', 'id_token' => self::idToken('ads@example.test')]])
			->on('revoke', [200, []])
			->on('listAccessibleCustomers', [200, ['resourceNames' => ['customers/' . self::CUSTOMER]]])
			->on('customer', fn () => [200, self::stream([['customer' => ['id' => self::CUSTOMER, 'descriptiveName' => 'Konto testowe', 'currencyCode' => 'PLN', 'timeZone' => 'Europe/Warsaw', 'manager' => false, 'testAccount' => true]]])])
			->on('campaign', [200, self::stream([[
				'campaign' => [
					'id' => '111', 'name' => 'Szukaj | Marka', 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH', 'biddingStrategyType' => 'MAXIMIZE_CONVERSIONS',
					'networkSettings' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => true, 'targetContentNetwork' => true],
					'geoTargetTypeSetting' => ['positiveGeoTargetType' => 'PRESENCE_OR_INTEREST'],
				],
				'campaignBudget' => ['amountMicros' => '50000000', 'period' => 'DAILY', 'explicitlyShared' => false],
			]])])
			->on('campaign_criterion', [200, self::stream([['campaign' => ['id' => '111'], 'campaignCriterion' => ['type' => 'LOCATION', 'negative' => false, 'location' => ['geoTargetConstant' => 'geoTargetConstants/2616']]]])])
			->on('geo_target_constant', [200, self::stream([['geoTargetConstant' => ['resourceName' => 'geoTargetConstants/2616', 'name' => 'Poland', 'canonicalName' => 'Poland']]])])
			->on('ad_group', [200, self::stream([['adGroup' => ['id' => '222', 'name' => 'Marka', 'status' => 'ENABLED'], 'campaign' => ['id' => '111']]])])
			->on('ad_group_criterion', [200, self::stream([['campaign' => ['id' => '111'], 'adGroup' => ['id' => '222'], 'adGroupCriterion' => ['criterionId' => '333', 'status' => 'ENABLED', 'keyword' => ['text' => 'sklep meble', 'matchType' => 'PHRASE'], 'qualityInfo' => ['qualityScore' => 7]]]])])
			->on('conversion_action', [200, self::stream([
				['conversionAction' => ['id' => '901', 'name' => 'Wyświetlenie strony kontakt', 'category' => 'PAGE_VIEW', 'status' => 'ENABLED', 'primaryForGoal' => true, 'includeInConversionsMetric' => true, 'countingType' => 'ONE_PER_CLICK', 'type' => 'WEBPAGE', 'origin' => 'WEBSITE']],
				['conversionAction' => ['id' => '902', 'name' => 'Formularz', 'category' => 'SUBMIT_LEAD_FORM', 'status' => 'ENABLED', 'primaryForGoal' => false, 'includeInConversionsMetric' => false, 'countingType' => 'MANY_PER_CLICK', 'type' => 'WEBPAGE', 'origin' => 'WEBSITE']],
			])])
			->on('campaign_metrics', fn ($request) => [200, self::stream(array_map(fn ($date) => [
				'campaign' => ['id' => '111'],
				'segments' => ['date' => $date],
				'metrics' => ['impressions' => '100', 'clicks' => '10', 'costMicros' => '20000000', 'conversions' => 1.0, 'conversionsValue' => 0, 'allConversions' => 1.0],
			], self::dates($request['gaql'])))])
			->on('keyword_view', fn ($request) => [200, self::stream(array_map(fn ($date) => [
				'campaign' => ['id' => '111'], 'adGroup' => ['id' => '222'], 'adGroupCriterion' => ['criterionId' => '333'],
				'segments' => ['date' => $date],
				'metrics' => ['impressions' => '80', 'clicks' => '8', 'costMicros' => '16000000', 'conversions' => 1.0],
			], self::dates($request['gaql'])))])
			->on('search_term_view', fn ($request) => [200, self::stream(array_merge(...array_map(fn ($date) => [
				['campaign' => ['id' => '111'], 'adGroup' => ['id' => '222'], 'searchTermView' => ['searchTerm' => 'sklep meble warszawa', 'status' => 'NONE'], 'segments' => ['date' => $date, 'searchTermMatchType' => 'PHRASE', 'keyword' => ['info' => ['text' => 'sklep meble']]], 'metrics' => ['impressions' => '50', 'clicks' => '5', 'costMicros' => '10000000', 'conversions' => 1.0]],
				// Ten sam wiersz hasła w dwóch segmentach – musi zostać zsumowany, nie zdublowany.
				['campaign' => ['id' => '111'], 'adGroup' => ['id' => '222'], 'searchTermView' => ['searchTerm' => 'meble za darmo', 'status' => 'NONE'], 'segments' => ['date' => $date, 'searchTermMatchType' => 'BROAD', 'keyword' => ['info' => ['text' => 'sklep meble']]], 'metrics' => ['impressions' => '30', 'clicks' => '2', 'costMicros' => '4000000']],
				['campaign' => ['id' => '111'], 'adGroup' => ['id' => '222'], 'searchTermView' => ['searchTerm' => 'meble za darmo', 'status' => 'NONE'], 'segments' => ['date' => $date, 'searchTermMatchType' => 'BROAD', 'keyword' => ['info' => ['text' => 'sklep meble']]], 'metrics' => ['impressions' => '20', 'clicks' => '1', 'costMicros' => '2000000']],
			], self::dates($request['gaql']))))])
			->on('conversion_metrics', fn ($request) => [200, self::stream(array_map(fn ($date) => [
				'segments' => ['date' => $date, 'conversionAction' => 'customers/' . self::CUSTOMER . '/conversionActions/901'],
				'metrics' => ['conversions' => 1.0, 'allConversions' => 1.0, 'conversionsValue' => 0],
			], self::dates($request['gaql'])))]);
	}

	private function handle(array $args, string $url): array|\WP_Error
	{
		$body = $args['body'] ?? null;
		$gaql = null;

		if (is_string($body) && str_contains($url, 'googleAds:searchStream')) {
			$gaql = (string) (json_decode($body, true)['query'] ?? '');
		}

		$key = self::keyFor($url, $gaql);
		$request = ['url' => $url, 'method' => (string) ($args['method'] ?? 'GET'), 'headers' => (array) ($args['headers'] ?? []), 'body' => $body, 'gaql' => $gaql, 'key' => $key];
		$this->requests[] = $request;

		$handler = $this->handlers[$key] ?? null;

		if ($handler === null) {
			return new \WP_Error('pp_mock_unhandled', "Atrapa: brak odpowiedzi dla {$key} ({$url})");
		}

		$result = is_callable($handler) ? $handler($request) : $handler;
		[$status, $responseBody] = $result;

		return [
			'headers' => $result[2] ?? [],
			'body' => is_string($responseBody) ? $responseBody : wp_json_encode($responseBody),
			'response' => ['code' => $status, 'message' => ''],
			'cookies' => [],
			'filename' => null,
		];
	}

	private static function keyFor(string $url, ?string $gaql): string
	{
		if (str_contains($url, 'oauth2.googleapis.com/token')) {
			return 'token';
		}

		if (str_contains($url, 'oauth2.googleapis.com/revoke')) {
			return 'revoke';
		}

		if (str_contains($url, 'listAccessibleCustomers')) {
			return 'listAccessibleCustomers';
		}

		if ($gaql === null) {
			return 'unknown';
		}

		preg_match('/\bFROM\s+(\w+)/i', $gaql, $m);
		$from = strtolower($m[1] ?? '');

		return match (true) {
			$from === 'customer' && str_contains($gaql, 'segments.conversion_action') => 'conversion_metrics',
			$from === 'campaign' && str_contains($gaql, 'segments.date') => 'campaign_metrics',
			default => $from,
		};
	}

	/**
	 * Wszystkie dni z warunku `segments.date BETWEEN 'a' AND 'b'`.
	 *
	 * @return array<int, string>
	 */
	public static function dates(?string $gaql): array
	{
		if (! preg_match("/BETWEEN '(\d{4}-\d{2}-\d{2})' AND '(\d{4}-\d{2}-\d{2})'/", (string) $gaql, $m)) {
			return [];
		}

		$dates = [];

		for ($day = new \DateTimeImmutable($m[1]); $day <= new \DateTimeImmutable($m[2]); $day = $day->modify('+1 day')) {
			$dates[] = $day->format('Y-m-d');
		}

		return $dates;
	}

	/**
	 * Odpowiedź searchStream: tablica partii z `results`.
	 */
	public static function stream(array $rows): array
	{
		return [['results' => $rows, 'fieldMask' => 'mock', 'requestId' => 'mock-request']];
	}

	public static function idToken(string $email): string
	{
		$encode = fn (array $data) => rtrim(strtr(base64_encode((string) wp_json_encode($data)), '+/', '-_'), '=');

		return $encode(['alg' => 'none']) . '.' . $encode(['email' => $email, 'email_verified' => true]) . '.signature';
	}
}
