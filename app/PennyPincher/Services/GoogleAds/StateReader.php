<?php

namespace App\PennyPincher\Services\GoogleAds;

use App\PennyPincher\Infrastructure\GoogleAds\Changes\StateQueries;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsClient;

/**
 * Odczyt aktualnego stanu zasobów konta Google Ads (tylko GAQL searchStream – bez zapisu).
 * Używany przed zatwierdzeniem zmiany, tuż przed wdrożeniem, przy uzgadnianiu i przy weryfikacji.
 */
class StateReader
{
	public function __construct(private GoogleAdsClient $client, private ConnectionService $connections)
	{
	}

	/**
	 * Stan pojedynczego zasobu albo null, gdy go nie ma (np. usunięty).
	 */
	public function read(array $connection, string $resource, string $resourceName): ?array
	{
		$rows = $this->search($connection, StateQueries::forResource($resource, $resourceName));

		return $rows === [] ? null : StateQueries::normalize($resource, $rows[0]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list(array $connection, string $resource, string $query): array
	{
		return array_values(array_filter(array_map(fn ($row) => StateQueries::normalize($resource, $row), $this->search($connection, $query))));
	}

	/**
	 * Kampanie korzystające z budżetu (do oceny wpływu zmiany współdzielonego budżetu).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function campaignsUsingBudget(array $connection, string $budgetResourceName): array
	{
		return $this->list($connection, 'campaign', StateQueries::campaignsByBudget($budgetResourceName));
	}

	public function languageByCode(array $connection, string $code): ?string
	{
		$rows = $this->search($connection, StateQueries::languageByCode($code));

		return $rows[0]['languageConstant']['resourceName'] ?? null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function search(array $connection, string $query): array
	{
		return $this->client->search(
			$this->connections->accessToken($connection),
			(string) $connection['customer_id'],
			$query,
			$connection['login_customer_id'] ?? null
		);
	}
}
