<?php

namespace App\PennyPincher\Domain\GoogleAds\Changes;

use App\PennyPincher\Domain\Conversions\ConversionFieldPolicy;
use App\PennyPincher\Domain\Planner\AdValidator;

/**
 * Katalog obsługiwanych operacji Google Ads (API v25, `customers/{id}/googleAds:mutate`).
 *
 * Operacja = typ + zasób docelowy (resource name; przy tworzeniu – odwołanie tymczasowe) + ładunek w nazwach pól
 * stanu znormalizowanego (`StateReader`). Katalog nie łączy się z API ani bazą: waliduje lokalnie, ocenia ryzyko,
 * buduje obiekty MutateOperation (REST JSON), określa oczekiwany stan po zmianie i operację odwrotną.
 *
 * Zakres jest świadomie ograniczony do pól zweryfikowanych w definicjach v25 (googleapis, google/ads/googleads/v25):
 * pola IMMUTABLE (np. tekst i dopasowanie słowa kluczowego, typ kampanii, typ działania konwersji) nie są edytowane –
 * zmiana dopasowania to zastąpienie (nowe kryterium + usunięcie starego w jednym atomowym żądaniu).
 */
final class OperationCatalog
{
	public const TYPES = [
		'campaign_budget.update' => ['resource' => 'campaign_budget', 'action' => 'update', 'label' => 'Zmiana budżetu dziennego'],
		'campaign_budget.create' => ['resource' => 'campaign_budget', 'action' => 'create', 'label' => 'Nowy budżet kampanii'],
		'campaign.create' => ['resource' => 'campaign', 'action' => 'create', 'label' => 'Nowa kampania w sieci wyszukiwania (wstrzymana)'],
		'campaign.update' => ['resource' => 'campaign', 'action' => 'update', 'label' => 'Zmiana ustawień kampanii'],
		'campaign.bidding' => ['resource' => 'campaign', 'action' => 'update', 'label' => 'Zmiana strategii ustalania stawek'],
		'campaign_criterion.create' => ['resource' => 'campaign_criterion', 'action' => 'create', 'label' => 'Dodanie kierowania lub wykluczenia w kampanii'],
		'campaign_criterion.remove' => ['resource' => 'campaign_criterion', 'action' => 'remove', 'label' => 'Usunięcie kierowania lub wykluczenia z kampanii'],
		'ad_group.create' => ['resource' => 'ad_group', 'action' => 'create', 'label' => 'Nowa grupa reklam'],
		'ad_group.update' => ['resource' => 'ad_group', 'action' => 'update', 'label' => 'Zmiana grupy reklam'],
		'ad_group_criterion.create' => ['resource' => 'ad_group_criterion', 'action' => 'create', 'label' => 'Nowe słowo kluczowe lub wykluczenie w grupie'],
		'ad_group_criterion.update' => ['resource' => 'ad_group_criterion', 'action' => 'update', 'label' => 'Zmiana słowa kluczowego'],
		'ad_group_criterion.remove' => ['resource' => 'ad_group_criterion', 'action' => 'remove', 'label' => 'Usunięcie słowa kluczowego lub wykluczenia'],
		'ad_group_ad.create' => ['resource' => 'ad_group_ad', 'action' => 'create', 'label' => 'Nowa elastyczna reklama w wyszukiwarce (RSA)'],
		'ad_group_ad.update' => ['resource' => 'ad_group_ad', 'action' => 'update', 'label' => 'Wstrzymanie lub wznowienie reklamy'],
		'ad_group_ad.remove' => ['resource' => 'ad_group_ad', 'action' => 'remove', 'label' => 'Usunięcie reklamy'],
		'conversion_action.create' => ['resource' => 'conversion_action', 'action' => 'create', 'label' => 'Nowe działanie konwersji (strona internetowa)'],
		'conversion_action.update' => ['resource' => 'conversion_action', 'action' => 'update', 'label' => 'Zmiana ustawień działania konwersji'],
		'customer_conversion_goal.update' => ['resource' => 'customer_conversion_goal', 'action' => 'update', 'label' => 'Zmiana celu konwersji konta (optymalizacja stawek)'],
		'campaign_conversion_goal.update' => ['resource' => 'campaign_conversion_goal', 'action' => 'update', 'label' => 'Zmiana celu konwersji kampanii (optymalizacja stawek)'],
	];

	/** Kolekcje zasobów w resource name (customers/{id}/{kolekcja}/{id}). */
	public const COLLECTIONS = [
		'campaign_budget' => 'campaignBudgets', 'campaign' => 'campaigns', 'campaign_criterion' => 'campaignCriteria', 'ad_group' => 'adGroups',
		'ad_group_criterion' => 'adGroupCriteria', 'ad_group_ad' => 'adGroupAds', 'conversion_action' => 'conversionActions',
		'customer_conversion_goal' => 'customerConversionGoals', 'campaign_conversion_goal' => 'campaignConversionGoals',
	];

	/** Pole operacji w MutateOperation (REST JSON). */
	public const OPERATION_FIELDS = [
		'campaign_budget' => 'campaignBudgetOperation', 'campaign' => 'campaignOperation', 'campaign_criterion' => 'campaignCriterionOperation',
		'ad_group' => 'adGroupOperation', 'ad_group_criterion' => 'adGroupCriterionOperation', 'ad_group_ad' => 'adGroupAdOperation',
		'conversion_action' => 'conversionActionOperation', 'customer_conversion_goal' => 'customerConversionGoalOperation',
		'campaign_conversion_goal' => 'campaignConversionGoalOperation',
	];

	/** Pole wyniku w MutateOperationResponse. */
	public const RESULT_FIELDS = [
		'campaign_budget' => 'campaignBudgetResult', 'campaign' => 'campaignResult', 'campaign_criterion' => 'campaignCriterionResult',
		'ad_group' => 'adGroupResult', 'ad_group_criterion' => 'adGroupCriterionResult', 'ad_group_ad' => 'adGroupAdResult',
		'conversion_action' => 'conversionActionResult', 'customer_conversion_goal' => 'customerConversionGoalResult',
		'campaign_conversion_goal' => 'campaignConversionGoalResult',
	];

	/** Pola edytowalne (nazwa w stanie znormalizowanym → ścieżka pola w zasobie REST). */
	public const UPDATE_PATHS = [
		'campaign_budget' => ['amount_micros' => 'amountMicros'],
		'campaign' => ['name' => 'name', 'status' => 'status', 'target_search_network' => 'networkSettings.targetSearchNetwork', 'target_content_network' => 'networkSettings.targetContentNetwork'],
		'ad_group' => ['name' => 'name', 'status' => 'status', 'cpc_bid_micros' => 'cpcBidMicros'],
		'ad_group_criterion' => ['status' => 'status', 'cpc_bid_micros' => 'cpcBidMicros'],
		'ad_group_ad' => ['status' => 'status'],
		'conversion_action' => [
			'name' => 'name', 'category' => 'category', 'counting_type' => 'countingType', 'primary_for_goal' => 'primaryForGoal',
			'click_through_lookback_window_days' => 'clickThroughLookbackWindowDays', 'view_through_lookback_window_days' => 'viewThroughLookbackWindowDays',
			'default_value' => 'valueSettings.defaultValue', 'always_use_default_value' => 'valueSettings.alwaysUseDefaultValue',
		],
		'customer_conversion_goal' => ['biddable' => 'biddable'],
		'campaign_conversion_goal' => ['biddable' => 'biddable'],
	];

	/** Pola zapisywane jako int64 (w JSON jako tekst). */
	private const INT64 = ['amount_micros', 'cpc_bid_micros', 'click_through_lookback_window_days', 'view_through_lookback_window_days'];

	public const FIELD_LABELS = [
		'amount_micros' => 'Budżet dzienny', 'name' => 'Nazwa', 'status' => 'Status', 'target_search_network' => 'Partnerzy wyszukiwania Google',
		'target_content_network' => 'Sieć reklamowa (Display)', 'cpc_bid_micros' => 'Maks. CPC', 'category' => 'Kategoria', 'counting_type' => 'Sposób zliczania',
		'primary_for_goal' => 'Konwersja główna (używana w optymalizacji)', 'click_through_lookback_window_days' => 'Okno konwersji po kliknięciu (dni)',
		'view_through_lookback_window_days' => 'Okno konwersji po wyświetleniu (dni)', 'default_value' => 'Wartość domyślna',
		'always_use_default_value' => 'Zawsze używaj wartości domyślnej', 'biddable' => 'Uwzględniana w optymalizacji stawek',
		'bidding_strategy_type' => 'Strategia ustalania stawek', 'explicitly_shared' => 'Budżet współdzielony',
		'text' => 'Tekst', 'match_type' => 'Dopasowanie', 'negative' => 'Wykluczenie', 'final_url' => 'Strona docelowa', 'path1' => 'Ścieżka 1', 'path2' => 'Ścieżka 2',
		'kind' => 'Rodzaj', 'geo_target_constant' => 'Lokalizacja', 'language_constant' => 'Język', 'strategy' => 'Strategia',
		'target_cpa_micros' => 'Docelowy CPA', 'cpc_bid_ceiling_micros' => 'Limit stawki CPC', 'type' => 'Typ', 'eu_political' => 'Reklamy polityczne UE',
		'geo_presence_only' => 'Tylko osoby w lokalizacji', 'shared_ack' => 'Potwierdzenie budżetu współdzielonego', 'confirm_goal_change' => 'Potwierdzenie zmiany celu',
	];

	public const STATUSES = ['ENABLED' => 'Aktywny', 'PAUSED' => 'Wstrzymany', 'REMOVED' => 'Usunięty'];

	public const MATCH_TYPES = ['EXACT' => 'Ścisłe', 'PHRASE' => 'Do wyrażenia', 'BROAD' => 'Przybliżone'];

	/** Strategie, które Penny Pincher potrafi ustawić (standardowe, nie portfelowe). */
	public const BIDDING = [
		'MANUAL_CPC' => 'Ręczny CPC',
		'TARGET_SPEND' => 'Maksymalizacja liczby kliknięć',
		'MAXIMIZE_CONVERSIONS' => 'Maksymalizacja liczby konwersji (opcjonalnie docelowy CPA)',
	];

	/** Kategorie konwersji dostępne przy tworzeniu (podzbiór ConversionActionCategory v25 sensowny dla stron www). */
	public const CONVERSION_CATEGORIES = [
		'SUBMIT_LEAD_FORM' => 'Przesłanie formularza kontaktowego', 'REQUEST_QUOTE' => 'Prośba o wycenę', 'BOOK_APPOINTMENT' => 'Umówienie spotkania',
		'CONTACT' => 'Kontakt', 'PHONE_CALL_LEAD' => 'Połączenie telefoniczne', 'SIGNUP' => 'Rejestracja', 'PURCHASE' => 'Zakup',
		'DOWNLOAD' => 'Pobranie', 'PAGE_VIEW' => 'Wyświetlenie strony', 'ENGAGEMENT' => 'Zaangażowanie', 'OUTBOUND_CLICK' => 'Kliknięcie linku wychodzącego',
		'DEFAULT' => 'Inna',
	];

	/** Wskazówka dla operacji, której Google Ads API nie wykona (istniejące zestawy nie są zmieniane automatycznie). */
	public const UNSUPPORTED_HINT = 'Google Ads API nie wykona tej operacji – usuń ją z zestawu (ikona ×), a ustawienie zmień ręcznie w Google Ads albo przygotuj zmianę ponownie.';

	public const COUNTING = ['ONE_PER_CLICK' => 'Jedna na kliknięcie (zalecane dla leadów)', 'MANY_PER_CLICK' => 'Każda (zalecane dla sprzedaży)'];

	/**
	 * Pola działania konwersji edytowalne przez Penny Pincher wg typu – zob. ConversionFieldPolicy (jedno źródło prawdy).
	 */
	public const CONVERSION_EDITABLE = ConversionFieldPolicy::BY_TYPE;

	/** Znaki niedozwolone w słowach kluczowych Google Ads. */
	private const KEYWORD_INVALID = ['!', '@', '%', '^', '*', '(', ')', '=', '{', '}', ';', '~', '`', '<', '>', '?', '\\', '|', ','];

	// --- Metadane ---------------------------------------------------------------------------------

	public static function exists(string $type): bool
	{
		return isset(self::TYPES[$type]);
	}

	public static function resource(string $type): string
	{
		return self::TYPES[$type]['resource'] ?? throw new \InvalidArgumentException('Nieobsługiwany typ operacji: ' . $type);
	}

	public static function action(string $type): string
	{
		return self::TYPES[$type]['action'];
	}

	/**
	 * Pola stanu porównywane przed wdrożeniem (zmiana poza Penny Pincher = konflikt).
	 *
	 * @return array<int, string>
	 */
	public static function watched(array $op): array
	{
		$resource = self::resource($op['type']);
		$payload = (array) $op['payload'];

		return match ($op['type']) {
			'campaign_budget.update' => ['amount_micros', 'explicitly_shared', 'reference_count'],
			'campaign.bidding' => ['bidding_strategy_type', 'status'],
			'campaign_criterion.remove', 'ad_group_criterion.remove', 'ad_group_ad.remove' => ['status'],
			default => self::action($op['type']) === 'update' ? array_values(array_unique([...array_keys(array_intersect_key($payload, self::UPDATE_PATHS[$resource] ?? [])), ...($resource === 'conversion_action' ? [] : ['status'])])) : [],
		};
	}

	// --- Walidacja lokalna ------------------------------------------------------------------------

	/**
	 * Walidacja bez kontaktu z Google Ads. To NIE jest potwierdzenie Google Ads – API może nadal odrzucić operację.
	 *
	 * @param array{customer_id?: ?string, currency?: string, existing_names?: array<int, string>, existing_keywords?: array<int, string>, parent_channel?: ?string} $context
	 * @return array{errors: array<int, string>, warnings: array<int, string>}
	 */
	public static function validate(array $op, array $context = []): array
	{
		$errors = [];
		$warnings = [];
		// Błędy, które usuwa świadome potwierdzenie użytkownika (pole w „Edytuj operację”) – są też w $errors (blokują).
		$confirmations = [];
		$unsupported = [];
		$type = (string) ($op['type'] ?? '');

		if (! self::exists($type)) {
			return ['errors' => ['Nieobsługiwany typ operacji.'], 'warnings' => [], 'confirmations' => [], 'unsupported' => ['Nieobsługiwany typ operacji.']];
		}

		$payload = (array) ($op['payload'] ?? []);
		$before = is_array($op['before_state'] ?? null) ? $op['before_state'] : null;
		$customer = $context['customer_id'] ?? null;
		$resource = self::resource($type);
		$action = self::action($type);
		$currency = (string) ($context['currency'] ?? 'PLN');

		// Zasób docelowy musi należeć do zatwierdzonego konta.
		if ($action !== 'create') {
			$name = (string) ($op['resource_name'] ?? '');

			if (! preg_match('#^customers/(\d+)/' . preg_quote(self::COLLECTIONS[$resource], '#') . '/[^/]+$#', $name, $m)) {
				$errors[] = 'Nieprawidłowy identyfikator zasobu Google Ads.';
			} elseif ($customer !== null && $m[1] !== (string) $customer) {
				$errors[] = 'Zasób należy do innego konta Google Ads niż konto zestawu zmian.';
			}

			if ($before === null) {
				$errors[] = 'Brak odczytanego stanu zasobu – odczytaj aktualny stan z Google Ads przed zatwierdzeniem.';
			} elseif (($before['status'] ?? null) === 'REMOVED' && $resource !== 'conversion_action') {
				$errors[] = 'Zasób jest usunięty w Google Ads – operacja nie jest możliwa.';
			}
		}

		foreach (self::parentRefs($op) as $ref) {
			if (! str_starts_with($ref, 'ref:') && ! preg_match('#^customers/' . preg_quote((string) $customer, '#') . '/[A-Za-z]+/[^/]+$#', $ref)) {
				$errors[] = 'Nieprawidłowy zasób nadrzędny (musi należeć do konta zestawu zmian).';
			}
		}

		if ($action === 'update') {
			$allowed = self::UPDATE_PATHS[$resource] ?? [];
			$fields = array_keys(array_intersect_key($payload, $allowed));

			if ($type !== 'campaign.bidding' && $fields === []) {
				$errors[] = 'Operacja nie zmienia żadnego obsługiwanego pola.';
			}

			foreach (array_keys(array_diff_key($payload, $allowed, array_flip(['shared_ack', 'strategy', 'target_cpa_micros', 'cpc_bid_ceiling_micros', 'confirm_goal_change']))) as $field) {
				$errors[] = sprintf('Pole „%s” nie może być zmieniane przez Penny Pincher.', $field);
			}

			if (isset($payload['status']) && ! in_array($payload['status'], ['ENABLED', 'PAUSED'], true)) {
				$errors[] = 'Dozwolone statusy: aktywny albo wstrzymany (usuwanie to osobna operacja).';
			}

			if ($before !== null && $fields !== [] && array_filter($fields, fn ($f) => ($before[$f] ?? null) !== $payload[$f]) === []) {
				$warnings[] = 'Proponowane wartości są takie same jak obecne – operacja niczego nie zmieni.';
			}
		}

		$micros = function (string $field, string $label, bool $required = false) use ($payload, &$errors) {
			if (! array_key_exists($field, $payload) || $payload[$field] === null) {
				if ($required) {
					$errors[] = "{$label}: podaj kwotę.";
				}

				return;
			}

			if (! is_int($payload[$field]) || $payload[$field] <= 0) {
				$errors[] = "{$label}: kwota musi być większa od zera.";
			} elseif ($payload[$field] % 10_000 !== 0) {
				$errors[] = "{$label}: kwota musi być wielokrotnością 0,01 jednostki waluty.";
			}
		};

		switch ($type) {
			case 'campaign_budget.update':
				$micros('amount_micros', 'Budżet dzienny', true);

				if ($before !== null) {
					$shared = (bool) ($before['explicitly_shared'] ?? false) || (int) ($before['reference_count'] ?? 1) > 1;

					if ($shared && empty($payload['shared_ack'])) {
						$errors[] = $confirmations[] = sprintf('Budżet jest współdzielony przez %d kampanie – zmiana dotyczy ich wszystkich. Potwierdź, że to rozumiesz.', max(2, (int) ($before['reference_count'] ?? 2)));
					}

					if (($before['period'] ?? 'DAILY') !== 'DAILY') {
						$errors[] = 'Obsługiwane są tylko budżety dzienne (budżet na cały okres kampanii zmień w Google Ads).';
					}

					$old = (int) ($before['amount_micros'] ?? 0);
					$new = (int) ($payload['amount_micros'] ?? 0);

					if ($old > 0 && $new > $old) {
						$warnings[] = sprintf('Zwiększenie budżetu o %s%% – wydatki mogą wzrosnąć (w miesiącu do ok. 30,4 × budżet dzienny, w pojedynczym dniu do 2 ×).', number_format(($new - $old) / $old * 100, 0, ',', ' '));
					}

					if ($old > 0 && $new >= 2 * $old) {
						$warnings[] = 'Budżet rośnie co najmniej dwukrotnie – rozważ stopniowe zwiększanie (np. o 20–30% co kilka dni).';
					}
				}

				break;

			case 'campaign_budget.create':
				$micros('amount_micros', 'Budżet dzienny', true);
				self::requireName($payload, $errors, 'Nazwa budżetu');

				break;

			case 'campaign.create':
				self::requireName($payload, $errors, 'Nazwa kampanii');

				if (in_array(mb_strtolower((string) ($payload['name'] ?? '')), array_map('mb_strtolower', $context['existing_names'] ?? []), true)) {
					$errors[] = 'Kampania o tej nazwie już istnieje na koncie.';
				}

				if (empty($payload['budget'])) {
					$errors[] = 'Kampania wymaga budżetu.';
				}

				if (! array_key_exists($payload['bidding'] ?? '', self::BIDDING)) {
					$errors[] = 'Wybierz obsługiwaną strategię ustalania stawek.';
				}

				if (! in_array($payload['eu_political'] ?? null, ['no', 'yes'], true)) {
					$errors[] = 'Wymagana deklaracja, czy kampania zawiera reklamy polityczne kierowane do UE.';
				}

				if (($payload['bidding'] ?? '') === 'MAXIMIZE_CONVERSIONS' && ! ($context['measurement_reliable'] ?? false)) {
					$warnings[] = 'Maksymalizacja konwersji przy niewiarygodnym pomiarze może optymalizować pod mikrokonwersje (np. czas na stronie). Najpierw popraw cele konwersji.';
				}

				break;

			case 'campaign.update':
				if ($before !== null && ($before['channel_type'] ?? 'SEARCH') !== 'SEARCH' && array_diff(array_keys(array_intersect_key($payload, self::UPDATE_PATHS['campaign'])), ['status', 'name']) !== []) {
					$errors[] = sprintf('Dla kampanii typu %s Penny Pincher obsługuje tylko nazwę i status.', $before['channel_type']);
				}

				if (array_key_exists('name', $payload)) {
					self::requireName($payload, $errors, 'Nazwa kampanii');
				}

				if (($payload['status'] ?? null) === 'ENABLED') {
					$warnings[] = 'Włączenie kampanii rozpocznie wydawanie budżetu.';
				}

				if (($payload['target_content_network'] ?? false) === true) {
					$warnings[] = 'Sieć reklamowa w kampanii w wyszukiwarce zwykle daje tańszy, ale mniej wartościowy ruch.';
				}

				break;

			case 'campaign.bidding':
				if ($before !== null && ($before['channel_type'] ?? '') !== 'SEARCH') {
					$errors[] = 'Zmiana strategii jest obsługiwana tylko w kampaniach w sieci wyszukiwania.';
				}

				if ($before !== null && ! empty($before['portfolio'])) {
					$errors[] = 'Kampania używa strategii portfelowej – zmień ją w Google Ads.';
				}

				if (! array_key_exists($payload['strategy'] ?? '', self::BIDDING)) {
					$errors[] = 'Wybierz obsługiwaną strategię ustalania stawek.';
				}

				foreach (['target_cpa_micros' => 'Docelowy CPA', 'cpc_bid_ceiling_micros' => 'Limit stawki CPC'] as $field => $label) {
					$micros($field, $label);
				}

				$warnings[] = 'Zmiana strategii stawek uruchamia ponowną naukę algorytmu – wyniki mogą się wahać przez 1–2 tygodnie.';

				if (($payload['strategy'] ?? '') === 'MAXIMIZE_CONVERSIONS' && ! ($context['measurement_reliable'] ?? false)) {
					$warnings[] = 'Pomiar konwersji nie jest wiarygodny – automatyczna strategia może optymalizować pod mikrokonwersje.';
				}

				break;

			case 'campaign_criterion.create':
				$kind = $payload['kind'] ?? '';

				if ($kind === 'location' && ! preg_match('#^geoTargetConstants/\d+$#', (string) ($payload['geo_target_constant'] ?? ''))) {
					$errors[] = 'Nieprawidłowa lokalizacja (geoTargetConstants/ID).';
				} elseif ($kind === 'language' && ! preg_match('#^languageConstants/\d+$#', (string) ($payload['language_constant'] ?? ''))) {
					$errors[] = 'Nieprawidłowy język (languageConstants/ID).';
				} elseif ($kind === 'negative_keyword') {
					self::keyword($payload, $errors, $warnings);

					if (in_array(self::keywordKey($payload), $context['existing_negatives'] ?? [], true)) {
						$errors[] = 'Takie wykluczenie już istnieje w kampanii.';
					}

					if (($context['blocked_keywords'] ?? []) !== []) {
						$warnings[] = 'Wykluczenie zablokuje aktywne słowa kluczowe: ' . implode(', ', array_map(fn ($k) => '„' . $k . '”', array_slice($context['blocked_keywords'], 0, 5))) . '.';
					}
				} elseif (! in_array($kind, ['location', 'language', 'negative_keyword'], true)) {
					$errors[] = 'Nieobsługiwany rodzaj kryterium kampanii.';
				}

				break;

			case 'campaign_criterion.remove':
				if ($before !== null && in_array($before['type'] ?? '', ['LOCATION', 'LANGUAGE'], true) && (int) ($context['remaining_same_type'] ?? 1) === 0) {
					$warnings[] = ($before['type'] === 'LOCATION' ? 'To ostatnia lokalizacja – kampania zacznie wyświetlać reklamy we wszystkich krajach.' : 'To ostatni język – kampania będzie kierowana na wszystkie języki.');
				}

				break;

			case 'ad_group.create':
				self::requireName($payload, $errors, 'Nazwa grupy reklam');
				$micros('cpc_bid_micros', 'Maks. CPC');

				if (in_array(mb_strtolower((string) ($payload['name'] ?? '')), array_map('mb_strtolower', $context['existing_names'] ?? []), true)) {
					$errors[] = 'Grupa reklam o tej nazwie już istnieje w kampanii.';
				}

				break;

			case 'ad_group.update':
				if (array_key_exists('name', $payload)) {
					self::requireName($payload, $errors, 'Nazwa grupy reklam');
				}

				$micros('cpc_bid_micros', 'Maks. CPC');

				break;

			case 'ad_group_criterion.create':
				self::keyword($payload, $errors, $warnings);
				$micros('cpc_bid_micros', 'Maks. CPC');

				if (in_array(self::keywordKey($payload), $context['existing_keywords'] ?? [], true)) {
					$errors[] = 'Takie słowo kluczowe (tekst i dopasowanie) już istnieje w tej grupie reklam.';
				}

				if (empty($payload['negative']) && ($payload['match_type'] ?? '') === 'BROAD' && ! ($context['measurement_reliable'] ?? false)) {
					$warnings[] = 'Dopasowanie przybliżone przy niewiarygodnym pomiarze konwersji może wydawać budżet na nietrafne zapytania.';
				}

				if (($context['blocking_negatives'] ?? []) !== []) {
					$warnings[] = 'Słowo zostanie zablokowane przez istniejące wykluczenie: ' . implode(', ', array_map(fn ($n) => '„' . $n . '”', array_slice($context['blocking_negatives'], 0, 5))) . ' – reklamy nie wyświetlą się na to zapytanie, dopóki wykluczenie istnieje.';
				}

				if (empty($payload['negative']) && ($payload['status'] ?? 'ENABLED') === 'ENABLED') {
					$warnings[] = 'Aktywne słowo kluczowe w aktywnej grupie reklam zacznie generować wydatki.';
				}

				if (! empty($payload['negative']) && ($context['blocked_keywords'] ?? []) !== []) {
					$warnings[] = 'Wykluczenie zablokuje aktywne słowa kluczowe: ' . implode(', ', array_map(fn ($k) => '„' . $k . '”', array_slice($context['blocked_keywords'], 0, 5))) . '.';
				}

				break;

			case 'ad_group_criterion.update':
				$micros('cpc_bid_micros', 'Maks. CPC');

				if ($before !== null && ! empty($before['negative'])) {
					$errors[] = 'Wykluczeń nie wstrzymuje się – można je tylko usunąć.';
				}

				break;

			case 'ad_group_ad.create':
				$headlines = array_values(array_filter(array_map('trim', (array) ($payload['headlines'] ?? [])), fn ($h) => $h !== ''));
				$descriptions = array_values(array_filter(array_map('trim', (array) ($payload['descriptions'] ?? [])), fn ($d) => $d !== ''));
				$result = AdValidator::validate($headlines, $descriptions, $payload['path1'] ?? null, $payload['path2'] ?? null, $payload['final_url'] ?? null);
				array_push($errors, ...array_values($result['errors']));

				if (count($headlines) < AdValidator::HEADLINES[0] || count($descriptions) < AdValidator::DESCRIPTIONS[0]) {
					$errors[] = sprintf('Elastyczna reklama w wyszukiwarce wymaga min. %d nagłówków i %d opisów.', AdValidator::HEADLINES[0], AdValidator::DESCRIPTIONS[0]);
				}

				if (trim((string) ($payload['final_url'] ?? '')) === '') {
					$errors[] = 'Podaj adres strony docelowej (final URL).';
				}

				array_push($warnings, ...array_values(array_filter($result['warnings'], fn ($w) => ! str_starts_with($w, 'Reklama wymaga'))));
				$warnings[] = 'Google sprawdza reklamę pod kątem zasad – Penny Pincher nie gwarantuje jej zatwierdzenia.';

				break;

			case 'conversion_action.create':
				self::requireName($payload, $errors, 'Nazwa działania konwersji');

				if (in_array(mb_strtolower((string) ($payload['name'] ?? '')), array_map('mb_strtolower', $context['existing_names'] ?? []), true)) {
					$errors[] = 'Działanie konwersji o tej nazwie już istnieje na koncie.';
				}

				if (! array_key_exists($payload['category'] ?? '', self::CONVERSION_CATEGORIES)) {
					$errors[] = 'Wybierz kategorię konwersji.';
				}

				self::conversionFields($payload, $errors);
				$warnings[] = 'Samo utworzenie działania konwersji nie mierzy konwersji – trzeba wdrożyć tag Google (gtag.js) lub Google Tag Manager i zdarzenie konwersji na stronie.';

				if (($payload['primary_for_goal'] ?? false) === true) {
					$warnings[] = 'Konwersja główna od razu wpłynie na automatyczne strategie stawek – ustaw ją jako główną dopiero po sprawdzeniu, że rejestruje prawdziwe leady.';
				}

				break;

			case 'conversion_action.update':
				// Pola, których Google Ads API nie pozwala zmienić dla tego działania – operacji nie da się wykonać.
				if ($before !== null && ($violations = ConversionFieldPolicy::violations($before, $payload)) !== []) {
					foreach ($violations as $reason) {
						$errors[] = $unsupported[] = $reason;
					}

					$errors[] = $unsupported[] = self::UNSUPPORTED_HINT;
				}

				if (array_key_exists('category', $payload) && ! array_key_exists($payload['category'], self::CONVERSION_CATEGORIES)) {
					$errors[] = 'Nieobsługiwana kategoria konwersji.';
				}

				self::conversionFields($payload, $errors);

				if (array_key_exists('primary_for_goal', $payload) && empty($payload['confirm_goal_change'])) {
					$errors[] = $confirmations[] = 'Zmiana konwersji głównej wpływa na automatyczne strategie stawek – potwierdź, że rozumiesz skutki.';
				}

				if (array_key_exists('primary_for_goal', $payload)) {
					$warnings[] = 'Zmiana celu optymalizacji może na kilka dni lub tygodni zmienić wyniki kampanii z automatycznymi strategiami (Smart Bidding).';
				}

				break;

			case 'customer_conversion_goal.update':
			case 'campaign_conversion_goal.update':
				if (! is_bool($payload['biddable'] ?? null)) {
					$errors[] = 'Wybierz, czy cel ma być używany w optymalizacji.';
				}

				if (empty($payload['confirm_goal_change'])) {
					$errors[] = $confirmations[] = 'Zmiana celów konwersji wpływa na automatyczne strategie stawek – potwierdź, że rozumiesz skutki.';
				}

				$warnings[] = 'Zmiana celu optymalizacji może na kilka dni lub tygodni zmienić wyniki kampanii z automatycznymi strategiami (Smart Bidding).';

				break;
		}

		// Odczyt z Google Ads nie znalazł zasobu, którego dotyczy operacja (usunięty, inne konto, brak dostępu).
		if ($action !== 'create' && ! empty($context['missing'])) {
			$errors[] = 'Google Ads nie zwrócił tego zasobu (usunięty albo niedostępny dla połączonego konta) – operacji nie da się wykonać. Usuń ją z zestawu.';
		}

		return [
			'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)),
			'confirmations' => array_values(array_unique($confirmations)), 'unsupported' => array_values(array_unique($unsupported)),
		];
	}

	/**
	 * @return array<int, string>
	 */
	public static function conversionEditable(array $state): array
	{
		return ConversionFieldPolicy::editableFields($state);
	}

	// --- Ryzyko i wpływ na budżet --------------------------------------------------------------------

	public static function risk(array $op): string
	{
		$payload = (array) $op['payload'];
		$before = (array) ($op['before_state'] ?? []);

		return match ($op['type']) {
			'campaign_budget.update' => ((int) ($payload['amount_micros'] ?? 0)) > ((int) ($before['amount_micros'] ?? 0)) ? 'high' : 'medium',
			'campaign.bidding', 'customer_conversion_goal.update', 'campaign_conversion_goal.update' => 'high',
			'campaign.update' => ($payload['status'] ?? null) === 'ENABLED' ? 'high' : ((isset($payload['status']) || ($payload['target_content_network'] ?? false) || ($payload['target_search_network'] ?? false)) ? 'medium' : 'low'),
			'campaign_criterion.remove' => in_array($before['type'] ?? '', ['LOCATION', 'LANGUAGE'], true) ? 'high' : 'medium',
			'campaign_criterion.create' => ($payload['kind'] ?? '') === 'negative_keyword' ? 'medium' : 'low',
			'ad_group.update' => isset($payload['status']) || isset($payload['cpc_bid_micros']) ? 'medium' : 'low',
			'ad_group_criterion.create' => ! empty($payload['negative']) || ($payload['status'] ?? 'ENABLED') === 'ENABLED' ? 'medium' : 'low',
			'ad_group_criterion.update' => 'medium',
			'ad_group_criterion.remove' => empty($before['negative']) ? 'high' : 'medium',
			// Usunięcie reklamy jest nieodwracalne (statystyki zostają w raportach) – wstrzymanie jest bezpieczniejsze.
			'ad_group_ad.remove' => 'high',
			'ad_group_ad.create' => ($payload['status'] ?? 'PAUSED') === 'ENABLED' ? 'medium' : 'low',
			'ad_group_ad.update' => ($payload['status'] ?? null) === 'ENABLED' ? 'medium' : 'low',
			'conversion_action.create' => ($payload['primary_for_goal'] ?? false) === true ? 'high' : 'medium',
			'conversion_action.update' => array_key_exists('primary_for_goal', $payload) || array_key_exists('counting_type', $payload) ? 'high' : 'medium',
			default => 'low',
		};
	}

	/**
	 * Zmiana budżetu dziennego (mikro) wynikająca z operacji; null – brak bezpośredniego wpływu.
	 */
	public static function budgetImpact(array $op): ?int
	{
		$payload = (array) $op['payload'];
		$before = (array) ($op['before_state'] ?? []);

		return match ($op['type']) {
			'campaign_budget.update' => isset($payload['amount_micros'], $before['amount_micros']) ? (int) $payload['amount_micros'] - (int) $before['amount_micros'] : null,
			default => null,
		};
	}

	/**
	 * Czy operacja może zmienić optymalizację automatycznych strategii stawek (osobne potwierdzenie).
	 */
	public static function affectsBidding(array $op): bool
	{
		$payload = (array) $op['payload'];

		return in_array($op['type'], ['campaign.bidding', 'customer_conversion_goal.update', 'campaign_conversion_goal.update'], true)
			|| ($op['type'] === 'conversion_action.update' && (array_key_exists('primary_for_goal', $payload) || array_key_exists('counting_type', $payload)))
			|| ($op['type'] === 'conversion_action.create' && ($payload['primary_for_goal'] ?? false) === true);
	}

	// --- Budowanie operacji Google Ads ---------------------------------------------------------------

	/**
	 * Obiekty MutateOperation (REST JSON) dla `googleAds:mutate`.
	 *
	 * @param array<string, string> $resolved odwołania tymczasowe ('ref:…') → resource name (tymczasowy z ujemnym ID w tym samym żądaniu albo rzeczywisty)
	 * @return array<string, mixed>
	 */
	public static function build(array $op, string $customerId, array $resolved): array
	{
		$type = $op['type'];
		$resource = self::resource($type);
		$payload = (array) $op['payload'];
		$field = self::OPERATION_FIELDS[$resource];
		$ref = fn (?string $value) => $value === null ? null : (str_starts_with($value, 'ref:') ? ($resolved[$value] ?? throw new \RuntimeException('Nierozwiązane odwołanie do zasobu nadrzędnego: ' . $value)) : $value);
		$self = $op['temp_ref'] ?? null;
		$selfName = $self !== null ? ($resolved['ref:' . $self] ?? null) : null;

		if (self::action($type) === 'remove') {
			return [$field => ['remove' => (string) $op['resource_name']]];
		}

		if (self::action($type) === 'update') {
			if ($type === 'campaign.bidding') {
				[$object, $mask] = self::biddingUpdate($payload);

				return [$field => ['update' => ['resourceName' => (string) $op['resource_name']] + $object, 'updateMask' => $mask]];
			}

			// Ostatnia linia obrony: pola tylko do odczytu nie trafią do żądania mutate, nawet gdyby ominęły walidację.
			if ($type === 'conversion_action.update' && is_array($op['before_state'] ?? null) && ($violations = ConversionFieldPolicy::violations($op['before_state'], $payload)) !== []) {
				throw new \InvalidArgumentException(ConversionFieldPolicy::message($violations));
			}

			$update = ['resourceName' => (string) $op['resource_name']];
			$paths = [];

			foreach (self::UPDATE_PATHS[$resource] as $name => $path) {
				if (array_key_exists($name, $payload)) {
					self::setPath($update, $path, self::jsonValue($name, $payload[$name]));
					$paths[] = $path;
				}
			}

			return [$field => ['update' => $update, 'updateMask' => implode(',', $paths)]];
		}

		$create = match ($type) {
			'campaign_budget.create' => [
				'name' => (string) $payload['name'],
				'amountMicros' => (string) (int) $payload['amount_micros'],
				'deliveryMethod' => 'STANDARD',
				// Domyślnie Google tworzy budżet współdzielony – budżet nowej kampanii jest tylko jej.
				'explicitlyShared' => false,
			],
			'campaign.create' => array_filter([
				'name' => (string) $payload['name'],
				// Nowe kampanie zawsze wstrzymane – włączenie to osobna, świadoma zmiana.
				'status' => 'PAUSED',
				'advertisingChannelType' => 'SEARCH',
				'campaignBudget' => $ref((string) $payload['budget']),
				'networkSettings' => [
					'targetGoogleSearch' => true,
					'targetSearchNetwork' => (bool) ($payload['target_search_network'] ?? false),
					'targetContentNetwork' => (bool) ($payload['target_content_network'] ?? false),
					'targetPartnerSearchNetwork' => false,
				],
				'geoTargetTypeSetting' => ['positiveGeoTargetType' => ($payload['geo_presence_only'] ?? true) ? 'PRESENCE' : 'PRESENCE_OR_INTEREST'],
				'containsEuPoliticalAdvertising' => ($payload['eu_political'] ?? 'no') === 'yes' ? 'CONTAINS_EU_POLITICAL_ADVERTISING' : 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
			]) + self::biddingCreate($payload),
			'campaign_criterion.create' => ['campaign' => $ref((string) $payload['campaign'])] + match ($payload['kind']) {
				'location' => ['location' => ['geoTargetConstant' => (string) $payload['geo_target_constant']]],
				'language' => ['language' => ['languageConstant' => (string) $payload['language_constant']]],
				default => ['negative' => true, 'keyword' => ['text' => (string) $payload['text'], 'matchType' => (string) $payload['match_type']]],
			},
			'ad_group.create' => array_filter([
				'campaign' => $ref((string) $payload['campaign']),
				'name' => (string) $payload['name'],
				'status' => in_array($payload['status'] ?? 'PAUSED', ['ENABLED', 'PAUSED'], true) ? ($payload['status'] ?? 'PAUSED') : 'PAUSED',
				'type' => 'SEARCH_STANDARD',
				'cpcBidMicros' => isset($payload['cpc_bid_micros']) ? (string) (int) $payload['cpc_bid_micros'] : null,
			], fn ($v) => $v !== null),
			'ad_group_criterion.create' => ! empty($payload['negative'])
				? ['adGroup' => $ref((string) $payload['ad_group']), 'negative' => true, 'keyword' => ['text' => (string) $payload['text'], 'matchType' => (string) $payload['match_type']]]
				: array_filter([
					'adGroup' => $ref((string) $payload['ad_group']),
					'status' => in_array($payload['status'] ?? 'ENABLED', ['ENABLED', 'PAUSED'], true) ? ($payload['status'] ?? 'ENABLED') : 'PAUSED',
					'keyword' => ['text' => (string) $payload['text'], 'matchType' => (string) $payload['match_type']],
					'cpcBidMicros' => isset($payload['cpc_bid_micros']) ? (string) (int) $payload['cpc_bid_micros'] : null,
				], fn ($v) => $v !== null),
			'ad_group_ad.create' => [
				'adGroup' => $ref((string) $payload['ad_group']),
				'status' => in_array($payload['status'] ?? 'PAUSED', ['ENABLED', 'PAUSED'], true) ? ($payload['status'] ?? 'PAUSED') : 'PAUSED',
				'ad' => [
					'finalUrls' => [(string) $payload['final_url']],
					'responsiveSearchAd' => array_filter([
						'headlines' => array_map(fn ($h) => ['text' => (string) $h], array_values(array_filter((array) $payload['headlines'], fn ($h) => trim((string) $h) !== ''))),
						'descriptions' => array_map(fn ($d) => ['text' => (string) $d], array_values(array_filter((array) $payload['descriptions'], fn ($d) => trim((string) $d) !== ''))),
						'path1' => ($payload['path1'] ?? '') !== '' ? (string) $payload['path1'] : null,
						'path2' => ($payload['path2'] ?? '') !== '' ? (string) $payload['path2'] : null,
					], fn ($v) => $v !== null),
				],
			],
			'conversion_action.create' => array_filter([
				'name' => (string) $payload['name'],
				'type' => 'WEBPAGE',
				'category' => (string) $payload['category'],
				'status' => 'ENABLED',
				'countingType' => (string) ($payload['counting_type'] ?? 'ONE_PER_CLICK'),
				'primaryForGoal' => (bool) ($payload['primary_for_goal'] ?? false),
				'clickThroughLookbackWindowDays' => isset($payload['click_through_lookback_window_days']) ? (string) (int) $payload['click_through_lookback_window_days'] : null,
				'viewThroughLookbackWindowDays' => isset($payload['view_through_lookback_window_days']) ? (string) (int) $payload['view_through_lookback_window_days'] : null,
				'valueSettings' => array_filter([
					'defaultValue' => isset($payload['default_value']) ? (float) $payload['default_value'] : null,
					'alwaysUseDefaultValue' => isset($payload['always_use_default_value']) ? (bool) $payload['always_use_default_value'] : null,
				], fn ($v) => $v !== null) ?: null,
			], fn ($v) => $v !== null),
			default => throw new \InvalidArgumentException('Nieobsługiwany typ operacji: ' . $type),
		};

		if ($selfName !== null) {
			$create = ['resourceName' => $selfName] + $create;
		}

		return [$field => ['create' => $create]];
	}

	/**
	 * Oczekiwany stan po wdrożeniu (pola stanu znormalizowanego) – do weryfikacji i uzgadniania.
	 *
	 * @return array<string, mixed>
	 */
	public static function expected(array $op): array
	{
		$payload = (array) $op['payload'];
		$resource = self::resource($op['type']);

		return match ($op['type']) {
			'campaign_budget.create' => ['name' => $payload['name'], 'amount_micros' => (int) $payload['amount_micros']],
			'campaign.create' => ['name' => $payload['name'], 'status' => 'PAUSED', 'channel_type' => 'SEARCH'],
			'campaign.bidding' => ['bidding_strategy_type' => $payload['strategy']],
			'campaign_criterion.create' => match ($payload['kind'] ?? '') {
				'location' => ['type' => 'LOCATION', 'geo_target_constant' => $payload['geo_target_constant']],
				'language' => ['type' => 'LANGUAGE', 'language_constant' => $payload['language_constant']],
				default => ['type' => 'KEYWORD', 'negative' => true, 'keyword_text' => mb_strtolower((string) $payload['text']), 'match_type' => $payload['match_type']],
			},
			'ad_group.create' => ['name' => $payload['name'], 'status' => $payload['status'] ?? 'PAUSED'],
			'ad_group_criterion.create' => ['keyword_text' => mb_strtolower((string) $payload['text']), 'match_type' => $payload['match_type'], 'negative' => ! empty($payload['negative'])] + (! empty($payload['negative']) ? [] : ['status' => $payload['status'] ?? 'ENABLED']),
			'ad_group_ad.create' => ['status' => $payload['status'] ?? 'PAUSED', 'headlines' => array_values(array_filter(array_map('trim', (array) $payload['headlines']), fn ($h) => $h !== ''))],
			'conversion_action.create' => ['name' => $payload['name'], 'category' => $payload['category'], 'type' => 'WEBPAGE'],
			'campaign_criterion.remove', 'ad_group_criterion.remove', 'ad_group_ad.remove' => ['status' => 'REMOVED'],
			default => array_intersect_key($payload, self::UPDATE_PATHS[$resource] ?? []),
		};
	}

	/**
	 * Porównanie stanu z oczekiwanym; zwraca listę rozbieżności (puste = zgodne).
	 *
	 * @return array<int, array{field: string, expected: mixed, actual: mixed}>
	 */
	public static function compare(array $expected, ?array $state): array
	{
		if ($state === null) {
			return ($expected['status'] ?? null) === 'REMOVED' ? [] : [['field' => 'resource', 'expected' => 'istnieje', 'actual' => 'nie znaleziono']];
		}

		$diff = [];

		foreach ($expected as $field => $value) {
			$actual = $state[$field] ?? null;
			$equal = is_float($value) || is_float($actual) ? abs((float) $value - (float) $actual) < 0.00001 : (is_string($value) && is_string($actual) ? mb_strtolower($value) === mb_strtolower($actual) : $value == $actual);

			if (! $equal) {
				$diff[] = ['field' => $field, 'expected' => $value, 'actual' => $actual];
			}
		}

		return $diff;
	}

	/**
	 * Operacja odwrotna (do „Przygotuj cofnięcie”) albo null, gdy nie da się jej bezpiecznie przygotować.
	 *
	 * @return array{type: string, resource_name: string, payload: array, label: string}|null
	 */
	public static function inverse(array $op, ?string $createdResource = null): ?array
	{
		$payload = (array) $op['payload'];
		$before = is_array($op['before_state'] ?? null) ? $op['before_state'] : null;
		$resource = self::resource($op['type']);
		$name = (string) ($op['resource_name'] ?? '');

		if (self::action($op['type']) === 'update') {
			if ($before === null) {
				return null;
			}

			if ($op['type'] === 'campaign.bidding') {
				$strategy = $before['bidding_strategy_type'] ?? null;

				if (! array_key_exists((string) $strategy, self::BIDDING) || ! empty($before['portfolio'])) {
					return null;
				}

				return ['type' => 'campaign.bidding', 'resource_name' => $name, 'payload' => array_filter(['strategy' => $strategy, 'target_cpa_micros' => $before['target_cpa_micros'] ?? null, 'cpc_bid_ceiling_micros' => $before['cpc_bid_ceiling_micros'] ?? null], fn ($v) => $v !== null && $v !== 0), 'label' => 'Cofnięcie: przywrócenie poprzedniej strategii stawek'];
			}

			$back = [];

			foreach (array_keys(array_intersect_key($payload, self::UPDATE_PATHS[$resource] ?? [])) as $field) {
				if (! array_key_exists($field, $before) || $before[$field] === null) {
					return null;
				}

				$back[$field] = $before[$field];
			}

			if ($back === []) {
				return null;
			}

			if (isset($payload['shared_ack'])) {
				$back['shared_ack'] = true;
			}

			if (isset($payload['confirm_goal_change'])) {
				$back['confirm_goal_change'] = false;
			}

			return ['type' => $op['type'], 'resource_name' => $name, 'payload' => $back, 'label' => 'Cofnięcie: przywrócenie poprzednich wartości'];
		}

		if (self::action($op['type']) === 'create' && $createdResource !== null) {
			return match ($op['type']) {
				'campaign.create' => null, // nowa kampania jest wstrzymana – nic nie wydaje; usunięcie wykonaj świadomie w Google Ads
				'ad_group.create' => ['type' => 'ad_group.update', 'resource_name' => $createdResource, 'payload' => ['status' => 'PAUSED'], 'label' => 'Cofnięcie: wstrzymanie utworzonej grupy reklam'],
				'ad_group_criterion.create' => ! empty($payload['negative'])
					? ['type' => 'ad_group_criterion.remove', 'resource_name' => $createdResource, 'payload' => [], 'label' => 'Cofnięcie: usunięcie dodanego wykluczenia']
					: ['type' => 'ad_group_criterion.update', 'resource_name' => $createdResource, 'payload' => ['status' => 'PAUSED'], 'label' => 'Cofnięcie: wstrzymanie dodanego słowa kluczowego'],
				'campaign_criterion.create' => ['type' => 'campaign_criterion.remove', 'resource_name' => $createdResource, 'payload' => [], 'label' => 'Cofnięcie: usunięcie dodanego kryterium'],
				'ad_group_ad.create' => ['type' => 'ad_group_ad.update', 'resource_name' => $createdResource, 'payload' => ['status' => 'PAUSED'], 'label' => 'Cofnięcie: wstrzymanie utworzonej reklamy'],
				default => null,
			};
		}

		return null;
	}

	/**
	 * Wiersze „przed / po” do podglądu.
	 *
	 * @return array<int, array{field: string, label: string, before: mixed, after: mixed}>
	 */
	public static function diff(array $op): array
	{
		$payload = (array) $op['payload'];
		$before = (array) ($op['before_state'] ?? []);
		$resource = self::resource($op['type']);
		$rows = [];

		if ($op['type'] === 'campaign.bidding') {
			$rows[] = ['field' => 'bidding_strategy_type', 'label' => self::FIELD_LABELS['bidding_strategy_type'], 'before' => $before['bidding_strategy_type'] ?? null, 'after' => $payload['strategy'] ?? null];

			foreach (['target_cpa_micros' => 'Docelowy CPA', 'cpc_bid_ceiling_micros' => 'Limit stawki CPC'] as $field => $label) {
				if (isset($payload[$field]) || isset($before[$field])) {
					$rows[] = ['field' => $field, 'label' => $label, 'before' => $before[$field] ?? null, 'after' => $payload[$field] ?? null];
				}
			}

			return $rows;
		}

		if (self::action($op['type']) === 'update') {
			foreach (array_keys(array_intersect_key($payload, self::UPDATE_PATHS[$resource] ?? [])) as $field) {
				$rows[] = ['field' => $field, 'label' => self::FIELD_LABELS[$field] ?? $field, 'before' => $before[$field] ?? null, 'after' => $payload[$field]];
			}

			return $rows;
		}

		if (self::action($op['type']) === 'remove') {
			return [['field' => 'status', 'label' => 'Stan', 'before' => $before['status'] ?? 'ENABLED', 'after' => 'REMOVED']];
		}

		foreach ($payload as $field => $value) {
			if (in_array($field, ['budget', 'campaign', 'ad_group'], true) || is_array($value) && ! in_array($field, ['headlines', 'descriptions'], true)) {
				continue;
			}

			$rows[] = ['field' => $field, 'label' => self::FIELD_LABELS[$field] ?? $field, 'before' => null, 'after' => $value];
		}

		if (isset($payload['headlines'])) {
			foreach (['headlines', 'descriptions'] as $field) {
				$rows = array_values(array_filter($rows, fn ($r) => $r['field'] !== $field));
				$rows[] = ['field' => $field, 'label' => $field === 'headlines' ? 'Nagłówki' : 'Opisy', 'before' => null, 'after' => implode(' | ', (array) $payload[$field])];
			}
		}

		return $rows;
	}

	/**
	 * Odwołania do zasobów nadrzędnych w ładunku.
	 *
	 * @return array<int, string>
	 */
	public static function parentRefs(array $op): array
	{
		$payload = (array) ($op['payload'] ?? []);

		return array_values(array_filter(array_map(fn ($key) => isset($payload[$key]) ? (string) $payload[$key] : null, ['budget', 'campaign', 'ad_group']), fn ($v) => $v !== null && $v !== ''));
	}

	public static function keywordKey(array $payload): string
	{
		return mb_strtolower(trim((string) ($payload['text'] ?? ''))) . '|' . strtoupper((string) ($payload['match_type'] ?? ''));
	}

	public static function tempResourceName(string $customerId, string $resource, int $tempId): string
	{
		return sprintf('customers/%s/%s/%d', $customerId, self::COLLECTIONS[$resource], $tempId);
	}

	public static function resourceName(string $customerId, string $resource, string $id): string
	{
		return sprintf('customers/%s/%s/%s', $customerId, self::COLLECTIONS[$resource], $id);
	}

	// --- Pomocnicze -----------------------------------------------------------------------------

	private static function requireName(array $payload, array &$errors, string $label): void
	{
		$name = trim((string) ($payload['name'] ?? ''));

		if ($name === '' || mb_strlen($name) > 255) {
			$errors[] = "{$label}: podaj nazwę (maks. 255 znaków).";
		}
	}

	private static function keyword(array $payload, array &$errors, array &$warnings): void
	{
		$text = trim((string) ($payload['text'] ?? ''));

		if ($text === '') {
			$errors[] = 'Podaj tekst słowa kluczowego.';

			return;
		}

		if (mb_strlen($text) > 80 || count(preg_split('/\s+/u', $text) ?: []) > 10) {
			$errors[] = 'Słowo kluczowe może mieć maks. 80 znaków i 10 słów (limit Google Ads).';
		}

		foreach (self::KEYWORD_INVALID as $char) {
			if (str_contains($text, $char)) {
				$errors[] = sprintf('Słowo kluczowe zawiera niedozwolony znak „%s”.', $char);
				break;
			}
		}

		if (preg_match('/^[\["+\-]|[\]"]$/u', $text)) {
			$errors[] = 'Podaj tekst bez znaków dopasowania ([ ], " ", +) – typ dopasowania wybierasz osobno.';
		}

		if (! array_key_exists($payload['match_type'] ?? '', self::MATCH_TYPES)) {
			$errors[] = 'Wybierz typ dopasowania (ścisłe, do wyrażenia, przybliżone).';
		}
	}

	private static function conversionFields(array $payload, array &$errors): void
	{
		if (isset($payload['counting_type']) && ! array_key_exists($payload['counting_type'], self::COUNTING)) {
			$errors[] = 'Nieprawidłowy sposób zliczania.';
		}

		if (isset($payload['click_through_lookback_window_days']) && (! is_int($payload['click_through_lookback_window_days']) || $payload['click_through_lookback_window_days'] < 1 || $payload['click_through_lookback_window_days'] > 90)) {
			$errors[] = 'Okno konwersji po kliknięciu: 1–90 dni.';
		}

		if (isset($payload['view_through_lookback_window_days']) && (! is_int($payload['view_through_lookback_window_days']) || $payload['view_through_lookback_window_days'] < 1 || $payload['view_through_lookback_window_days'] > 30)) {
			$errors[] = 'Okno konwersji po wyświetleniu: 1–30 dni.';
		}

		if (isset($payload['default_value']) && (! is_numeric($payload['default_value']) || (float) $payload['default_value'] < 0)) {
			$errors[] = 'Wartość domyślna konwersji nie może być ujemna.';
		}
	}

	private static function biddingCreate(array $payload): array
	{
		return match ($payload['bidding'] ?? 'MANUAL_CPC') {
			'TARGET_SPEND' => ['targetSpend' => array_filter(['cpcBidCeilingMicros' => isset($payload['cpc_bid_ceiling_micros']) ? (string) (int) $payload['cpc_bid_ceiling_micros'] : null]) ?: new \stdClass()],
			'MAXIMIZE_CONVERSIONS' => ['maximizeConversions' => array_filter(['targetCpaMicros' => isset($payload['target_cpa_micros']) ? (string) (int) $payload['target_cpa_micros'] : null]) ?: new \stdClass()],
			default => ['manualCpc' => new \stdClass()],
		};
	}

	/**
	 * @return array{0: array, 1: string}
	 */
	private static function biddingUpdate(array $payload): array
	{
		return match ($payload['strategy']) {
			'TARGET_SPEND' => isset($payload['cpc_bid_ceiling_micros'])
				? [['targetSpend' => ['cpcBidCeilingMicros' => (string) (int) $payload['cpc_bid_ceiling_micros']]], 'targetSpend.cpcBidCeilingMicros']
				: [['targetSpend' => new \stdClass()], 'targetSpend'],
			'MAXIMIZE_CONVERSIONS' => isset($payload['target_cpa_micros'])
				? [['maximizeConversions' => ['targetCpaMicros' => (string) (int) $payload['target_cpa_micros']]], 'maximizeConversions.targetCpaMicros']
				: [['maximizeConversions' => new \stdClass()], 'maximizeConversions'],
			default => [['manualCpc' => new \stdClass()], 'manualCpc'],
		};
	}

	private static function jsonValue(string $field, mixed $value): mixed
	{
		return in_array($field, self::INT64, true) && $value !== null ? (string) (int) $value : $value;
	}

	private static function setPath(array &$target, string $path, mixed $value): void
	{
		$keys = explode('.', $path);
		$ref = &$target;

		foreach ($keys as $i => $key) {
			if ($i === count($keys) - 1) {
				$ref[$key] = $value;
			} else {
				$ref[$key] ??= [];
				$ref = &$ref[$key];
			}
		}
	}
}
