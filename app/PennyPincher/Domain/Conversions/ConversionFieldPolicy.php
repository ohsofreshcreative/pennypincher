<?php

namespace App\PennyPincher\Domain\Conversions;

/**
 * Centralna polityka edycji pól działania konwersji (ConversionAction, Google Ads API v25).
 *
 * Jedno źródło prawdy dla: Conversion Management (formularze), Conversion Health Check, audytu (CT001, CT003),
 * rekomendacji i Action Plan, Change Center (dodawanie, edycja i walidacja operacji) oraz budowania MutateOperation.
 *
 * Podstawa (definicje v25 z googleapis, google/ads/googleads/v25):
 * - `resources/conversion_action.proto`: `type` – IMMUTABLE; `origin`, `owner_customer`, `tag_snippets`, ustawienia
 *   GA4/Firebase/aplikacji zewnętrznych, `mobile_app_vendor` – OUTPUT_ONLY; `owner_customer` = null oznacza działanie
 *   systemowe (zdefiniowane przez Google).
 * - `enums/conversion_action_type.proto`: typy oznaczone „Read only” (m.in. SMART_CAMPAIGN_AD_CLICKS_TO_CALL,
 *   SMART_CAMPAIGN_MAP_CLICKS_TO_CALL, SMART_CAMPAIGN_MAP_DIRECTIONS, SMART_CAMPAIGN_TRACKED_CALLS, GOOGLE_HOSTED,
 *   LEAD_FORM_SUBMIT, STORE_VISITS, STORE_SALES, FLOODLIGHT_*, SEARCH_ADS_360, WEBPAGE_CODELESS).
 * - `errors/conversion_action_error.proto`: UPDATE_NOT_SUPPORTED („Update of this conversion action isn't supported
 *   by Google Ads API”).
 *
 * Statusy pola:
 * - editable     – Penny Pincher może przygotować zmianę,
 * - confirm      – edytowalne, ale wymaga osobnego potwierdzenia (wpływ na Smart Bidding),
 * - read_only    – Google Ads API nie pozwala zmienić pola dla tego działania (typ tylko do odczytu, działanie systemowe,
 *                  usunięte, pole niedostępne dla typu),
 * - unsupported  – API mogłoby pozwolić, ale Penny Pincher świadomie tego nie obsługuje (np. konwersje aplikacji).
 */
final class ConversionFieldPolicy
{
	public const EDITABLE = 'editable';
	public const CONFIRM = 'confirm';
	public const READ_ONLY = 'read_only';
	public const UNSUPPORTED = 'unsupported';

	/** Pola, które Penny Pincher potrafi zapisać w operacji update (nazwa w stanie znormalizowanym). */
	public const API_FIELDS = [
		'name', 'category', 'counting_type', 'primary_for_goal', 'click_through_lookback_window_days', 'view_through_lookback_window_days',
		'default_value', 'always_use_default_value',
	];

	/** Pola wymagające osobnego potwierdzenia przy zmianie (wpływ na automatyczne strategie stawek). */
	public const CONFIRM_FIELDS = ['primary_for_goal'];

	/** Typy oznaczone w ConversionActionTypeEnum v25 jako „Read only” (oraz wycofane – UA, Salesforce). */
	public const READ_ONLY_TYPES = [
		'STORE_SALES', 'ANDROID_APP_PRE_REGISTRATION', 'ANDROID_INSTALLS_ALL_OTHER_APPS', 'FLOODLIGHT_ACTION', 'FLOODLIGHT_TRANSACTION',
		'GOOGLE_HOSTED', 'LEAD_FORM_SUBMIT', 'SALESFORCE', 'SEARCH_ADS_360', 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL', 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL',
		'SMART_CAMPAIGN_MAP_DIRECTIONS', 'SMART_CAMPAIGN_TRACKED_CALLS', 'STORE_VISITS', 'WEBPAGE_CODELESS', 'UNIVERSAL_ANALYTICS_GOAL',
		'UNIVERSAL_ANALYTICS_TRANSACTION',
	];

	/**
	 * Pola edytowalne przez Penny Pincher wg typu (konserwatywnie: typy i pola, których zmiana przez API jest typowa).
	 * Typów spoza tej listy i spoza READ_ONLY_TYPES Penny Pincher nie edytuje (konwersje aplikacji, Firebase, LSA…).
	 */
	public const BY_TYPE = [
		'WEBPAGE' => ['name', 'category', 'counting_type', 'primary_for_goal', 'click_through_lookback_window_days', 'view_through_lookback_window_days', 'default_value', 'always_use_default_value'],
		'UPLOAD_CLICKS' => ['name', 'category', 'counting_type', 'primary_for_goal', 'click_through_lookback_window_days', 'default_value', 'always_use_default_value'],
		'UPLOAD_CALLS' => ['name', 'counting_type', 'primary_for_goal', 'click_through_lookback_window_days', 'default_value', 'always_use_default_value'],
		'AD_CALL' => ['name', 'counting_type', 'primary_for_goal', 'click_through_lookback_window_days', 'default_value', 'always_use_default_value'],
		'WEBSITE_CALL' => ['name', 'counting_type', 'primary_for_goal', 'click_through_lookback_window_days', 'default_value', 'always_use_default_value'],
		'CLICK_TO_CALL' => ['name', 'counting_type', 'primary_for_goal', 'click_through_lookback_window_days', 'default_value', 'always_use_default_value'],
		// Import z GA4: nazwą, kategorią i wartością zarządza Google Analytics.
		'GOOGLE_ANALYTICS_4_CUSTOM' => ['primary_for_goal', 'counting_type', 'click_through_lookback_window_days', 'view_through_lookback_window_days'],
		'GOOGLE_ANALYTICS_4_PURCHASE' => ['primary_for_goal', 'counting_type', 'click_through_lookback_window_days', 'view_through_lookback_window_days'],
		'GOOGLE_ANALYTICS_4_GENERATE_LEAD' => ['primary_for_goal', 'counting_type', 'click_through_lookback_window_days', 'view_through_lookback_window_days'],
		'GOOGLE_ANALYTICS_4_QUALIFY_LEAD' => ['primary_for_goal', 'counting_type', 'click_through_lookback_window_days', 'view_through_lookback_window_days'],
		'GOOGLE_ANALYTICS_4_CLOSE_CONVERT_LEAD' => ['primary_for_goal', 'counting_type', 'click_through_lookback_window_days', 'view_through_lookback_window_days'],
	];

	public const LABELS = [
		'name' => 'Nazwa', 'category' => 'Kategoria', 'counting_type' => 'Sposób zliczania', 'primary_for_goal' => 'Konwersja główna (używana w optymalizacji)',
		'click_through_lookback_window_days' => 'Okno konwersji po kliknięciu (dni)', 'view_through_lookback_window_days' => 'Okno konwersji po wyświetleniu (dni)',
		'default_value' => 'Wartość domyślna', 'always_use_default_value' => 'Zawsze używaj wartości domyślnej',
	];

	/**
	 * Powód blokady całego działania (null – działanie można edytować przynajmniej częściowo).
	 *
	 * @param array<string, mixed> $state stan działania: type, status, owner_customer (jeśli odczytany z API), system
	 * @return array{status: string, reason: string}|null
	 */
	public static function actionBlock(array $state): ?array
	{
		$type = strtoupper((string) ($state['type'] ?? ''));
		$status = strtoupper((string) ($state['status'] ?? ''));

		if (in_array($status, ['REMOVED', 'HIDDEN'], true)) {
			return ['status' => self::READ_ONLY, 'reason' => 'Działanie konwersji jest usunięte lub ukryte w Google Ads – nie można go edytować.'];
		}

		if ((array_key_exists('owner_customer', $state) && $state['owner_customer'] === null) || ! empty($state['system'])) {
			return ['status' => self::READ_ONLY, 'reason' => 'To działanie systemowe (zdefiniowane przez Google) – Google Ads API nie pozwala go edytować.'];
		}

		if ($type === '' || in_array($type, ['UNKNOWN', 'UNSPECIFIED'], true)) {
			return ['status' => self::UNSUPPORTED, 'reason' => 'Nieznany typ działania konwersji – odczytaj stan z Google Ads, aby sprawdzić, co można zmienić.'];
		}

		if (in_array($type, self::READ_ONLY_TYPES, true)) {
			return ['status' => self::READ_ONLY, 'reason' => sprintf('Google Ads API (v25) nie pozwala edytować działań konwersji typu %s (typ tylko do odczytu) – ustawienie można zmienić wyłącznie w interfejsie Google Ads, o ile Google na to pozwala.', $type)];
		}

		if (! array_key_exists($type, self::BY_TYPE)) {
			return ['status' => self::UNSUPPORTED, 'reason' => sprintf('Penny Pincher nie obsługuje edycji działań konwersji typu %s – zmień je w interfejsie Google Ads.', $type)];
		}

		return null;
	}

	/**
	 * Status jednego pola dla działania.
	 *
	 * @return array{status: string, reason: ?string}
	 */
	public static function field(array $state, string $field): array
	{
		if (! in_array($field, self::API_FIELDS, true)) {
			return ['status' => self::UNSUPPORTED, 'reason' => sprintf('Penny Pincher nie zmienia pola „%s” działania konwersji.', $field)];
		}

		if (($block = self::actionBlock($state)) !== null) {
			return $block;
		}

		$type = strtoupper((string) $state['type']);

		if (! in_array($field, self::BY_TYPE[$type], true)) {
			return ['status' => self::READ_ONLY, 'reason' => sprintf('Pole „%s” jest tylko do odczytu dla działania typu %s.', self::LABELS[$field], $type)];
		}

		return ['status' => in_array($field, self::CONFIRM_FIELDS, true) ? self::CONFIRM : self::EDITABLE, 'reason' => null];
	}

	/**
	 * Pola, które Penny Pincher może zmienić (editable + confirm).
	 *
	 * @return array<int, string>
	 */
	public static function editableFields(array $state): array
	{
		return array_values(array_filter(self::API_FIELDS, fn ($field) => self::canEdit($state, $field)));
	}

	public static function canEdit(array $state, string $field): bool
	{
		return in_array(self::field($state, $field)['status'], [self::EDITABLE, self::CONFIRM], true);
	}

	/**
	 * Naruszenia dla ładunku operacji update: pole => powód. Klucze spoza API_FIELDS (np. confirm_goal_change) pomijane.
	 *
	 * @return array<string, string>
	 */
	public static function violations(array $state, array $payload): array
	{
		$out = [];

		foreach (array_keys($payload) as $field) {
			if (! in_array($field, self::API_FIELDS, true)) {
				continue;
			}

			$result = self::field($state, (string) $field);

			if (! in_array($result['status'], [self::EDITABLE, self::CONFIRM], true)) {
				$out[$field] = (string) $result['reason'];
			}
		}

		return $out;
	}

	/**
	 * Wspólny komunikat dla operacji, której nie da się wykonać przez API (do walidacji i blokad w generatorach).
	 */
	public static function message(array $violations): string
	{
		return implode(' ', array_values(array_unique($violations)));
	}
}
