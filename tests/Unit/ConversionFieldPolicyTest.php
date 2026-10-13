<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Rules\LeadCountingEvery;
use App\PennyPincher\Domain\Audit\Rules\MicroConversionAsPrimary;
use App\PennyPincher\Domain\Conversions\ConversionFieldPolicy as Policy;
use App\PennyPincher\Domain\Conversions\ConversionHealthCheck;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\ResolvedPeriods;
use Tests\TestCase;

/**
 * Centralna polityka edycji pól ConversionAction (Google Ads API v25) i jej użycie w katalogu operacji,
 * Conversion Health Check i regułach audytu CT001/CT003.
 */
class ConversionFieldPolicyTest extends TestCase
{
	private const C = '1234567890';

	/** Wszystkie wartości ConversionActionTypeEnum v25 (googleapis, enums/conversion_action_type.proto). */
	private const ALL_TYPES = [
		'AD_CALL', 'CLICK_TO_CALL', 'GOOGLE_PLAY_DOWNLOAD', 'GOOGLE_PLAY_IN_APP_PURCHASE', 'UPLOAD_CALLS', 'UPLOAD_CLICKS', 'WEBPAGE', 'WEBSITE_CALL',
		'STORE_SALES_DIRECT_UPLOAD', 'STORE_SALES', 'FIREBASE_ANDROID_FIRST_OPEN', 'FIREBASE_ANDROID_IN_APP_PURCHASE', 'FIREBASE_ANDROID_CUSTOM',
		'FIREBASE_IOS_FIRST_OPEN', 'FIREBASE_IOS_IN_APP_PURCHASE', 'FIREBASE_IOS_CUSTOM', 'THIRD_PARTY_APP_ANALYTICS_ANDROID_FIRST_OPEN',
		'THIRD_PARTY_APP_ANALYTICS_ANDROID_IN_APP_PURCHASE', 'THIRD_PARTY_APP_ANALYTICS_ANDROID_CUSTOM', 'THIRD_PARTY_APP_ANALYTICS_IOS_FIRST_OPEN',
		'THIRD_PARTY_APP_ANALYTICS_IOS_IN_APP_PURCHASE', 'THIRD_PARTY_APP_ANALYTICS_IOS_CUSTOM', 'ANDROID_APP_PRE_REGISTRATION', 'ANDROID_INSTALLS_ALL_OTHER_APPS',
		'FLOODLIGHT_ACTION', 'FLOODLIGHT_TRANSACTION', 'GOOGLE_HOSTED', 'LEAD_FORM_SUBMIT', 'SALESFORCE', 'SEARCH_ADS_360', 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL',
		'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL', 'SMART_CAMPAIGN_MAP_DIRECTIONS', 'SMART_CAMPAIGN_TRACKED_CALLS', 'STORE_VISITS', 'WEBPAGE_CODELESS',
		'UNIVERSAL_ANALYTICS_GOAL', 'UNIVERSAL_ANALYTICS_TRANSACTION', 'GOOGLE_ANALYTICS_4_CUSTOM', 'GOOGLE_ANALYTICS_4_PURCHASE', 'GOOGLE_ANALYTICS_4_GENERATE_LEAD',
		'GOOGLE_ANALYTICS_4_QUALIFY_LEAD', 'GOOGLE_ANALYTICS_4_CLOSE_CONVERT_LEAD', 'FIREBASE_ANDROID_GENERATE_LEAD', 'FIREBASE_ANDROID_QUALIFY_LEAD',
		'FIREBASE_ANDROID_CLOSE_CONVERT_LEAD', 'FIREBASE_IOS_GENERATE_LEAD', 'FIREBASE_IOS_QUALIFY_LEAD', 'FIREBASE_IOS_CLOSE_CONVERT_LEAD', 'LOCAL_SERVICES_ADS',
		'FIREBASE_ANDROID_APP_AD_IMPRESSION', 'FIREBASE_IOS_APP_AD_IMPRESSION',
	];

	private function owned(string $type, array $extra = []): array
	{
		return array_replace(['type' => $type, 'status' => 'ENABLED', 'owner_customer' => 'customers/' . self::C], $extra);
	}

	public function testSmartCampaignCallConversionsAreReadOnlyInEveryField(): void
	{
		foreach (['SMART_CAMPAIGN_AD_CLICKS_TO_CALL', 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL'] as $type) {
			$state = $this->owned($type, ['category' => 'PHONE_CALL_LEAD', 'counting_type' => 'MANY_PER_CLICK']);
			$this->assertSame([], Policy::editableFields($state), $type);

			foreach (Policy::API_FIELDS as $field) {
				$this->assertSame(Policy::READ_ONLY, Policy::field($state, $field)['status'], $type . ' / ' . $field);
			}

			$counting = Policy::field($state, 'counting_type');
			$this->assertContains($type, (string) $counting['reason']);
			$this->assertContains('tylko do odczytu', (string) $counting['reason']);
			$this->assertSame(['counting_type'], array_keys(Policy::violations($state, ['counting_type' => 'ONE_PER_CLICK', 'confirm_goal_change' => true])), 'Klucze pomocnicze (potwierdzenia) nie są polami API.');
		}
	}

	public function testWebsiteConversionDistinguishesEditableConfirmAndReadOnlyFields(): void
	{
		$web = $this->owned('WEBPAGE');
		$this->assertSame(Policy::EDITABLE, Policy::field($web, 'counting_type')['status']);
		$this->assertSame(Policy::EDITABLE, Policy::field($web, 'view_through_lookback_window_days')['status']);
		$this->assertSame(Policy::CONFIRM, Policy::field($web, 'primary_for_goal')['status'], 'Konwersja główna wymaga potwierdzenia (Smart Bidding).');
		$this->assertSame(Policy::API_FIELDS, Policy::editableFields($web));
		$this->assertSame(Policy::UNSUPPORTED, Policy::field($web, 'attribution_model')['status'], 'Pola spoza zakresu Penny Pincher.');
		$this->assertSame(Policy::UNSUPPORTED, Policy::field($web, 'type')['status'], 'Typ jest IMMUTABLE.');

		// Pole niedostępne dla typu (połączenia nie mają okna po wyświetleniu, GA4 – nazwy i kategorii).
		$this->assertSame(Policy::READ_ONLY, Policy::field($this->owned('AD_CALL'), 'view_through_lookback_window_days')['status']);
		$this->assertSame(Policy::READ_ONLY, Policy::field($this->owned('GOOGLE_ANALYTICS_4_CUSTOM'), 'name')['status']);
		$this->assertSame(Policy::EDITABLE, Policy::field($this->owned('GOOGLE_ANALYTICS_4_CUSTOM'), 'counting_type')['status']);
		$this->assertSame(Policy::EDITABLE, Policy::field($this->owned('UPLOAD_CLICKS'), 'category')['status']);
	}

	public function testEveryConversionTypeOfApiV25IsClassified(): void
	{
		foreach (self::ALL_TYPES as $type) {
			$state = $this->owned($type);
			$editable = Policy::editableFields($state);
			$block = Policy::actionBlock($state);

			if (in_array($type, Policy::READ_ONLY_TYPES, true)) {
				$this->assertSame(Policy::READ_ONLY, $block['status'] ?? null, $type . ' – typ „Read only” w API v25.');
				$this->assertSame([], $editable, $type);
			} elseif (array_key_exists($type, Policy::BY_TYPE)) {
				$this->assertNull($block, $type);
				$this->assertTrue($editable !== [], $type);
				$this->assertTrue(in_array('primary_for_goal', $editable, true) && in_array('counting_type', $editable, true), $type);
			} else {
				$this->assertSame(Policy::UNSUPPORTED, $block['status'] ?? null, $type . ' – świadomie nieobsługiwany przez Penny Pincher.');
				$this->assertSame([], $editable, $type);
			}

			// Żaden typ nie ma pól spoza zakresu operacji update.
			$this->assertSame([], array_diff($editable, array_keys(OperationCatalog::UPDATE_PATHS['conversion_action'])), $type);
		}

		$this->assertSame([], array_diff(Policy::READ_ONLY_TYPES, self::ALL_TYPES), 'Lista typów tylko do odczytu zawiera wyłącznie typy z API v25.');
		$this->assertSame([], array_diff(array_keys(Policy::BY_TYPE), self::ALL_TYPES));
		$this->assertTrue(in_array('WEBPAGE_CODELESS', Policy::READ_ONLY_TYPES, true), 'WEBPAGE_CODELESS jest w API v25 oznaczony jako „Read only”.');
	}

	public function testSystemRemovedAndUnknownActionsAreNotEditable(): void
	{
		$this->assertSame(Policy::READ_ONLY, Policy::field(['type' => 'WEBPAGE', 'owner_customer' => null], 'name')['status'], 'Działanie systemowe.');
		$this->assertSame(Policy::READ_ONLY, Policy::field($this->owned('WEBPAGE', ['status' => 'REMOVED']), 'name')['status']);
		$this->assertSame(Policy::READ_ONLY, Policy::field($this->owned('WEBPAGE', ['status' => 'removed']), 'name')['status'], 'Status z synchronizacji (małe litery).');
		$this->assertSame(Policy::READ_ONLY, Policy::field($this->owned('WEBPAGE', ['status' => 'HIDDEN']), 'name')['status']);
		$this->assertSame(Policy::UNSUPPORTED, Policy::field(['type' => null], 'counting_type')['status'], 'Nieznany typ – najpierw odczyt z Google Ads.');
		$this->assertSame(Policy::UNSUPPORTED, Policy::field(['type' => 'UNKNOWN'], 'counting_type')['status']);
		$this->assertSame(Policy::EDITABLE, Policy::field(['type' => 'WEBPAGE'], 'name')['status'], 'Stan z synchronizacji (bez właściciela) – właściciel nieznany, nie systemowy.');
	}

	public function testCatalogRejectsUnsupportedFieldsAndBuildRefusesThem(): void
	{
		$op = [
			'type' => 'conversion_action.update', 'resource_name' => 'customers/' . self::C . '/conversionActions/903', 'payload' => ['counting_type' => 'ONE_PER_CLICK'],
			'before_state' => $this->owned('SMART_CAMPAIGN_AD_CLICKS_TO_CALL', ['counting_type' => 'MANY_PER_CLICK']),
		];
		$result = OperationCatalog::validate($op);
		$this->assertTrue($result['errors'] !== []);
		$this->assertContains('SMART_CAMPAIGN_AD_CLICKS_TO_CALL', implode(' ', $result['unsupported']));
		$this->assertTrue(in_array(OperationCatalog::UNSUPPORTED_HINT, $result['unsupported'], true), 'Wskazówka: usuń operację z zestawu.');
		$this->assertSame([], $result['confirmations'], 'Operacja nieobsługiwana to nie jest „brak potwierdzenia”.');
		$this->assertThrows(\InvalidArgumentException::class, fn () => OperationCatalog::build($op, self::C, []), 'tylko do odczytu');

		// Konwersja na stronie: edycja zliczania poprawna, zmiana konwersji głównej = potwierdzenie, nie błąd API.
		$web = ['before_state' => $this->owned('WEBPAGE', ['counting_type' => 'MANY_PER_CLICK', 'primary_for_goal' => true])] + $op;
		$this->assertSame([], OperationCatalog::validate($web)['errors']);
		$this->assertSame('countingType', OperationCatalog::build($web, self::C, [])['conversionActionOperation']['updateMask']);
		$primary = OperationCatalog::validate(['payload' => ['primary_for_goal' => false]] + $web);
		$this->assertSame([], $primary['unsupported']);
		$this->assertTrue($primary['confirmations'] !== []);
		$this->assertSame($primary['confirmations'], $primary['errors']);

		// Pole spoza typu (okno po wyświetleniu dla połączeń) – też nieobsługiwane przez API.
		$call = ['payload' => ['view_through_lookback_window_days' => 7], 'before_state' => $this->owned('AD_CALL')] + $op;
		$this->assertContains('tylko do odczytu', implode(' ', OperationCatalog::validate($call)['unsupported']));
		$this->assertSame(Policy::BY_TYPE, OperationCatalog::CONVERSION_EDITABLE);
		$this->assertSame(Policy::editableFields($this->owned('UPLOAD_CALLS')), OperationCatalog::conversionEditable($this->owned('UPLOAD_CALLS')));
	}

	public function testHealthCheckTurnsReadOnlyFieldsIntoManualActions(): void
	{
		$base = ['status' => 'ENABLED', 'primary_for_goal' => true, 'category' => 'PHONE_CALL_LEAD', 'counting_type' => 'MANY_PER_CLICK', 'campaigns' => [], 'external_id' => '1'];
		$smart = $this->owned('SMART_CAMPAIGN_MAP_CLICKS_TO_CALL');
		$actions = [
			$base + ['id' => 1, 'name' => 'Kliknięcia przycisku połączenia w Mapach', 'type' => 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL', 'api' => $smart, 'editable' => Policy::editableFields($smart),
				'field_policy' => array_combine(Policy::API_FIELDS, array_map(fn ($f) => Policy::field($smart, $f), Policy::API_FIELDS))],
			$base + ['id' => 2, 'name' => 'Połączenia z witryny', 'type' => 'WEBSITE_CALL', 'editable' => Policy::editableFields($this->owned('WEBSITE_CALL'))],
		];
		$issues = array_column(ConversionHealthCheck::check($actions, ['customer' => null, 'campaigns' => null], ['reliable' => true, 'reason' => '']), null, 'key');

		$this->assertSame([], $issues['counting:1']['operations'], 'Brak operacji API dla typu tylko do odczytu.');
		$this->assertContains('Działanie ręczne', (string) $issues['counting:1']['not_editable']);
		$this->assertContains('SMART_CAMPAIGN_MAP_CLICKS_TO_CALL', (string) $issues['counting:1']['not_editable']);
		$this->assertSame(['counting_type' => 'ONE_PER_CLICK'], $issues['counting:2']['operations'][0]['payload']);
		$this->assertNull($issues['counting:2']['not_editable']);
	}

	public function testAuditRulesRecommendManualChangeForReadOnlyTypes(): void
	{
		$smart = ['id' => 7, 'name' => 'Kliknięcia przycisku połączenia w reklamach inteligentnych', 'status' => 'enabled', 'type' => 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL',
			'category_code' => 'PHONE_CALL_LEAD', 'primary_for_goal' => true, 'include_in_conversions' => true, 'counting_type' => 'MANY_PER_CLICK', 'conversions' => 5.0];
		$web = ['id' => 8, 'name' => 'Formularz', 'type' => 'WEBPAGE', 'category_code' => 'SUBMIT_LEAD_FORM'] + $smart;
		$findings = (new LeadCountingEvery())->evaluate($this->context([$smart, $web]));
		$this->assertCount(2, $findings);
		$byId = [];

		foreach ($findings as $finding) {
			$byId[$finding->entityId] = $finding;
		}

		$this->assertContains('Działanie ręczne', $byId[7]->recommendation);
		$this->assertContains('SMART_CAMPAIGN_AD_CLICKS_TO_CALL', $byId[7]->recommendation);
		$this->assertContains('Zmień zliczanie na „Jedna”', $byId[8]->recommendation);
		$this->assertSame($byId[7]->fingerprint(), (new \App\PennyPincher\Domain\Audit\Finding('CT003', $byId[7]->category, $byId[7]->severity, '', '', [], '', $byId[7]->dimension, 0, 'conversion_action', 7))->fingerprint(), 'Odcisk zależy od działania, nie od tekstu – istniejące rekomendacje nie są dublowane.');

		$micro = ['id' => 9, 'name' => 'Wskazówki dojazdu w Mapach', 'type' => 'SMART_CAMPAIGN_MAP_DIRECTIONS', 'category_code' => 'GET_DIRECTIONS'] + $smart;
		$microFindings = (new MicroConversionAsPrimary())->evaluate($this->context([$micro, ['id' => 10, 'name' => 'Odsłona', 'type' => 'WEBPAGE', 'category_code' => 'PAGE_VIEW'] + $smart]));

		$this->assertCount(2, $microFindings);

		foreach ($microFindings as $finding) {
			$finding->entityId === 9
				? $this->assertContains('Działanie ręczne', $finding->recommendation)
				: $this->assertContains('Ustaw to działanie jako dodatkowe', $finding->recommendation);
		}
	}

	private function context(array $actions): AuditContext
	{
		return new AuditContext(
			currency: 'PLN', monthlyBudgetMicros: 3_000_000_000, range: new DateRange('2026-09-12', '2026-10-11'), previousRange: new DateRange('2026-08-13', '2026-09-11'),
			campaigns: [], conversionActions: $actions, coverage: new ResolvedPeriods([], [], [], [], 0, 30, 30, [['2026-09-12', '2026-10-11']], 0), connection: ['status' => 'active', 'customer_id' => self::C, 'customer_name' => 'Konto'],
			lastSyncAt: null, lastRunStatus: 'completed', lastRunError: null, monthToDate: [], searchTermsWithoutConversions: [], searchTermsTotalCostMicros: 0,
			now: time(), projectCurrencyMismatch: null,
		);
	}
}
