<?php

namespace App\PennyPincher\Domain\GoogleAds\Changes;

/**
 * Blokada emisji reklam (tryb bezpieczeństwa, domyślnie włączona – PP_GOOGLE_ADS_ALLOW_SERVING ≠ 1).
 *
 * Reklama może się wyświetlać tylko wtedy, gdy aktywne są jednocześnie kampania, grupa reklam i reklama (oraz słowo
 * kluczowe w kampanii w sieci wyszukiwania). Blokada pilnuje, żeby żadna operacja Penny Pincher nie doprowadziła do
 * takiego stanu i nie zmieniała budżetu kampanii, która może wydawać pieniądze:
 *
 * - kampanie, grupy reklam i reklamy: nowe powstają tylko jako wstrzymane (odrzucane już przy przygotowaniu);
 *   włączenie istniejących można przygotować i zatwierdzić, ale wdrożenie jest blokowane,
 * - słowa kluczowe: aktywne słowo wolno dodać lub włączyć tylko w grupie albo kampanii, która jest wstrzymana
 *   (sprawdzane odczytem z Google Ads tuż przed wysłaniem),
 * - budżety: zmiana tylko wtedy, gdy żadna kampania korzystająca z budżetu nie jest aktywna (odczyt przed wysłaniem),
 * - zmiany mogące zwiększyć wydatki (włączenie sieci reklamowej lub partnerów, zmiana strategii stawek, podniesienie
 *   stawki CPC, usunięcie wykluczenia, usunięcie kierowania na lokalizację/język) – tylko w zasobach, które nie mogą
 *   emitować (wstrzymana kampania albo grupa); stan rodziców odczytywany przed wysłaniem, nieznany = blokada.
 *
 * Brak skonfigurowanych płatności na koncie NIE jest traktowany jako zabezpieczenie. Wstrzymywanie, zmiany nazw,
 * obniżanie stawek, dodawanie wykluczeń i ustawienia wstrzymanych zasobów nie są blokowane.
 */
final class ServingGuard
{
	public const PREFIX = 'Blokada emisji reklam: ';

	/** Zasoby, których status ENABLED pozwala na emisję (obiekt operacji MutateOperation → zasób). */
	public const STATUS_OPERATIONS = ['campaignOperation' => 'kampanię', 'adGroupOperation' => 'grupę reklam', 'adGroupAdOperation' => 'reklamę'];

	/**
	 * Naruszenie wynikające z samej operacji (bez odczytu Google Ads).
	 */
	public static function operationViolation(array $op): ?string
	{
		$payload = (array) ($op['payload'] ?? []);
		$type = (string) ($op['type'] ?? '');

		$enabled = match ($type) {
			'campaign.update', 'ad_group.update', 'ad_group_ad.update' => ($payload['status'] ?? null) === 'ENABLED',
			// Tworzenie: domyślnie wstrzymane (kampania zawsze), ale jawne ENABLED w ładunku też jest odrzucane.
			'campaign.create', 'ad_group.create', 'ad_group_ad.create' => ($payload['status'] ?? 'PAUSED') === 'ENABLED',
			default => false,
		};

		if (! $enabled) {
			return null;
		}

		$create = OperationCatalog::action($type) === 'create';

		return self::PREFIX . match (OperationCatalog::resource($type)) {
			'campaign' => $create ? 'nowa kampania może powstać tylko jako wstrzymana' : 'włączenie kampanii może uruchomić emisję reklam',
			'ad_group' => $create ? 'nowa grupa reklam może powstać tylko jako wstrzymana' : 'włączenie grupy reklam może uruchomić emisję reklam',
			default => $create ? 'nowa reklama może powstać tylko jako wstrzymana' : 'włączenie reklamy może uruchomić emisję reklam',
		} . ' (PP_GOOGLE_ADS_ALLOW_SERVING wyłączone).';
	}

	/**
	 * Zmiana budżetu: dozwolona tylko, gdy znamy kampanie korzystające z budżetu i żadna nie jest aktywna.
	 *
	 * @param array<int, array{status?: ?string, name?: ?string}>|null $campaigns null – nieznane
	 */
	public static function budgetViolation(array $op, ?array $campaigns): ?string
	{
		if (($op['type'] ?? '') !== 'campaign_budget.update') {
			return null;
		}

		if ($campaigns === null) {
			return self::PREFIX . 'nie wiadomo, które kampanie korzystają z budżetu – odczytaj stan z Google Ads.';
		}

		$active = array_values(array_filter($campaigns, fn ($c) => ($c['status'] ?? null) === 'ENABLED'));

		return $active === [] ? null : self::PREFIX . 'budżet jest używany przez aktywną kampanię (' . implode(', ', array_map(fn ($c) => '„' . ($c['name'] ?? '—') . '”', $active)) . ') – zmiana budżetu aktywnej kampanii jest zablokowana.';
	}

	/**
	 * Słowo kluczowe aktywne (dodanie lub włączenie) w istniejącej grupie: dozwolone tylko, gdy grupa albo kampania
	 * jest wstrzymana. Grupa tworzona w tym samym zestawie jest zawsze wstrzymana (operationViolation).
	 *
	 * @param array{status?: ?string}|null $adGroup stan grupy z Google Ads (null – nieznany)
	 * @param array{status?: ?string}|null $campaign stan kampanii z Google Ads (null – nieznany)
	 */
	public static function keywordViolation(array $op, ?array $adGroup, ?array $campaign): ?string
	{
		if (! self::activatesKeyword($op)) {
			return null;
		}

		if ($adGroup === null || $campaign === null) {
			return self::PREFIX . 'nie udało się potwierdzić, że grupa reklam lub kampania słowa kluczowego jest wstrzymana.';
		}

		return ($adGroup['status'] ?? null) === 'ENABLED' && ($campaign['status'] ?? null) === 'ENABLED'
			? self::PREFIX . 'aktywne słowo kluczowe w aktywnej grupie i kampanii mogłoby uruchomić emisję – dodaj je jako wstrzymane albo najpierw wstrzymaj grupę lub kampanię.'
			: null;
	}

	/**
	 * Czy operacja aktywuje słowo kluczowe w ISTNIEJĄCEJ grupie reklam (wymaga sprawdzenia rodzica).
	 */
	public static function activatesKeyword(array $op): bool
	{
		$payload = (array) ($op['payload'] ?? []);

		return match ($op['type'] ?? '') {
			'ad_group_criterion.create' => empty($payload['negative']) && ($payload['status'] ?? 'ENABLED') === 'ENABLED' && ! str_starts_with((string) ($payload['ad_group'] ?? ''), 'ref:'),
			'ad_group_criterion.update' => ($payload['status'] ?? null) === 'ENABLED',
			default => false,
		};
	}

	/**
	 * Czy operacja może zwiększyć wydatki zasobu, który emituje reklamy. `$current` – aktualny stan zasobu z Google Ads
	 * (null = nieznany, wtedy zakładamy najgorszy przypadek).
	 */
	public static function raisesSpend(array $op, ?array $current): bool
	{
		$payload = (array) ($op['payload'] ?? []);

		return match ($op['type'] ?? '') {
			'campaign.update' => (($payload['target_content_network'] ?? null) === true && ! ($current['target_content_network'] ?? false))
				|| (($payload['target_search_network'] ?? null) === true && ! ($current['target_search_network'] ?? false)),
			'campaign.bidding' => true,
			'ad_group.update', 'ad_group_criterion.update' => array_key_exists('cpc_bid_micros', $payload) && $payload['cpc_bid_micros'] !== null
				&& (($current['cpc_bid_micros'] ?? null) === null || (int) $payload['cpc_bid_micros'] > (int) $current['cpc_bid_micros']),
			'campaign_criterion.remove' => $current === null || ! empty($current['negative']) || in_array($current['type'] ?? null, ['LOCATION', 'LANGUAGE'], true),
			'ad_group_criterion.remove' => $current === null || ! empty($current['negative']),
			default => false,
		};
	}

	/**
	 * Zmiana zwiększająca wydatki: dozwolona tylko, gdy kampania (a dla zmian w grupie – kampania albo grupa) jest
	 * wstrzymana. Nieznany stan rodzica = blokada.
	 *
	 * @param array{status?: ?string}|null $campaign
	 * @param array{status?: ?string}|null $adGroup wymagane dla operacji na poziomie grupy (null = nieznany)
	 */
	public static function spendViolation(array $op, ?array $campaign, ?array $adGroup, bool $groupLevel): ?string
	{
		if ($campaign === null || ($groupLevel && $adGroup === null)) {
			return self::PREFIX . 'nie udało się potwierdzić, że kampania' . ($groupLevel ? ' lub grupa reklam' : '') . ' jest wstrzymana – zmiana „' . ($op['label'] ?? $op['type'] ?? '') . '” może zwiększyć wydatki.';
		}

		$serving = ($campaign['status'] ?? null) === 'ENABLED' && (! $groupLevel || ($adGroup['status'] ?? null) === 'ENABLED');

		return $serving
			? self::PREFIX . 'zmiana „' . ($op['label'] ?? $op['type'] ?? '') . '” może zwiększyć wydatki aktywnej kampanii – najpierw ją wstrzymaj albo świadomie zdejmij blokadę emisji.'
			: null;
	}

	/** CampaignStatus / AdGroupStatus / AdGroupAdStatus: PAUSED = 3, REMOVED = 4 (REST przyjmuje nazwę albo liczbę). */
	private static function isPaused(mixed $status): bool
	{
		return in_array(is_string($status) ? strtoupper(trim($status)) : $status, ['PAUSED', 3, '3'], true);
	}

	private static function isRemoved(mixed $status): bool
	{
		return in_array(is_string($status) ? strtoupper(trim($status)) : $status, ['REMOVED', 4, '4'], true);
	}

	/**
	 * Kontrola na poziomie żądania googleAds:mutate (obiekty REST JSON) – niezależna od wyższych warstw:
	 * status ENABLED kampanii, grupy reklam lub reklamy (tworzenie i aktualizacja) jest odrzucany.
	 *
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<int, string> indeks operacji => powód
	 */
	public static function scanMutate(array $operations): array
	{
		$out = [];

		foreach (array_values($operations) as $i => $operation) {
			foreach (self::STATUS_OPERATIONS as $key => $label) {
				$body = $operation[$key] ?? null;

				if (! is_array($body)) {
					continue;
				}

				// Tworzenie: tylko jawnie wstrzymane (brak statusu = domyślnie ENABLED po stronie Google).
				if (is_array($body['create'] ?? null) && ! self::isPaused($body['create']['status'] ?? null)) {
					$out[$i] = self::PREFIX . 'żądanie tworzy ' . $label . ' bez statusu PAUSED – odrzucone przed wysłaniem do Google Ads.';
				}

				// Aktualizacja: status tylko PAUSED/REMOVED (także w postaci liczbowej enum: 3/4); każda inna wartość = blokada.
				if (is_array($body['update'] ?? null) && array_key_exists('status', $body['update']) && ! self::isPaused($body['update']['status']) && ! self::isRemoved($body['update']['status'])) {
					$out[$i] = self::PREFIX . 'żądanie włącza ' . $label . ' – odrzucone przed wysłaniem do Google Ads.';
				}
			}
		}

		return $out;
	}
}
