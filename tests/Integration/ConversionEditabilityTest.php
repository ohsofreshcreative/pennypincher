<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Services\Changes\ChangeLinkService;
use App\PennyPincher\Services\Changes\ConversionManagementService;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Edytowalność pól ConversionAction w całym przepływie: Conversion Management, Conversion Health Check, rekomendacje
 * (CT003), Action Plan i Change Center – na atrapie konta z konwersjami kampanii inteligentnych (typy „Read only”
 * w Google Ads API v25). Żadna operacja nie jest wysyłana do Google Ads.
 */
class ConversionEditabilityTest extends ChangeCenterTestCase
{
	private const AD_CALLS = 'Kliknięcia przycisku połączenia w reklamach inteligentnych';
	private const MAP_CALLS = 'Kliknięcia przycisku połączenia w reklamach inteligentnych wyświetlanych w Mapach';

	/**
	 * Konto z dwiema konwersjami Smart (903, 904 – tylko do odczytu) i zwykłą konwersją na stronie 902
	 * (formularz liczony „każda”). Konfiguracja odczytana z Google Ads (Conversion Management).
	 */
	private function smartAccount(string $name): void
	{
		$this->account($name);
		$lead = ['category' => 'PHONE_CALL_LEAD', 'counting_type' => 'MANY_PER_CLICK', 'origin' => 'CALL_FROM_ADS'];
		$this->fake->conversionAction('903', self::AD_CALLS, ['type' => 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL'] + $lead);
		$this->fake->conversionAction('904', self::MAP_CALLS, ['type' => 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL', 'origin' => 'GOOGLE_HOSTED'] + $lead);
		$this->localConversion('903', self::AD_CALLS, 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL');
		$this->localConversion('904', self::MAP_CALLS, 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL');
		$this->make(ConversionManagementService::class)->readFromGoogle($this->project);
	}

	public function testConversionManagementBlocksReadOnlySmartCallFields(): void
	{
		$this->smartAccount('smart-cm');
		$overview = $this->make(ConversionManagementService::class)->overview($this->project);
		$rows = array_column($overview['actions'], null, 'external_id');

		foreach (['903' => 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL', '904' => 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL'] as $id => $type) {
			$this->assertSame([], $rows[$id]['editable'], $type . ': brak pól do edycji w formularzu.');
			$this->assertContains($type, (string) $rows[$id]['locked_reason']);
			$this->assertSame('read_only', $rows[$id]['field_policy']['counting_type']['status']);
		}

		$this->assertTrue(in_array('counting_type', $rows['902']['editable'], true), 'Zwykła konwersja na stronie – zliczanie edytowalne.');
		$this->assertSame('confirm', $rows['902']['field_policy']['primary_for_goal']['status']);

		// Health Check: problem zliczania pokazany, ale jako działanie ręczne – bez operacji API.
		$issues = array_column($overview['issues'], null, 'key');
		$smartIssue = $issues['counting:' . $this->conversionId('903')];
		$this->assertSame([], $smartIssue['operations']);
		$this->assertContains('Działanie ręczne', (string) $smartIssue['not_editable']);
		$this->assertTrue($issues['counting:' . $this->conversionId('904')]['operations'] === []);
		$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->fromHealthIssue($this->project, $this->owner, $smartIssue), 'Działanie ręczne');

		// Ręcznie spreparowany formularz (pole ukryte w interfejsie) – serwer odmawia, nie powstaje pusty szkic.
		$sets = $this->countSets();

		foreach (['903', '904'] as $id) {
			$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->atomically($this->project, $this->owner, fn () => $this->proposals()->conversionUpdate($this->project, $this->owner, $this->conversionId($id), ['counting_type' => 'ONE_PER_CLICK'])), 'tylko do odczytu');
			$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->conversionUpdate($this->project, $this->owner, $this->conversionId($id), ['name' => 'Nowa nazwa']), 'tylko do odczytu');
		}

		$this->assertSame($sets, $this->countSets(), 'Odmowa nie zostawia szkicu.');

		// Zwykła konwersja – zmiana przygotowana normalnie.
		$setId = $this->proposals()->conversionUpdate($this->project, $this->owner, $this->conversionId('902'), ['counting_type' => 'ONE_PER_CLICK']);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$local = $this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId));
		$this->assertTrue($local['ok'], json_encode($local['errors'], JSON_UNESCAPED_UNICODE));
		$this->assertSame([], $local['unsupported']);
		$this->assertSame([], array_filter($this->fake->mutations, fn ($m) => ! $m['validate_only']), 'Nic nie zostało wysłane do Google Ads.');
	}

	public function testRecommendationsAndActionPlanTreatReadOnlyConversionsAsManual(): void
	{
		$this->smartAccount('smart-rec');
		$manual = $this->counting(['903', '904'], 'Leady z połączeń liczone jako „każda”');
		$mixed = $this->counting(['903', '902'], 'Leady (formularz i połączenia) liczone jako „każda”');
		$links = $this->make(ChangeLinkService::class);
		$blockers = $links->recommendationBlockers($this->project, [$this->rec($manual), $this->rec($mixed)]);

		$this->assertContains('Działanie ręczne', (string) $blockers[$manual]);
		$this->assertContains('SMART_CAMPAIGN_AD_CLICKS_TO_CALL', (string) $blockers[$manual]);
		$this->assertContains('SMART_CAMPAIGN_MAP_CLICKS_TO_CALL', (string) $blockers[$manual]);
		$this->assertNull($blockers[$mixed], 'Co najmniej jedno działanie edytowalne – przycisk „Przygotuj zmianę”.');

		// Rekomendacja wyłącznie dla konwersji Smart: brak operacji i brak szkicu.
		$sets = $this->countSets();
		$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->fromRecommendation($this->project, $this->owner, $manual), 'Działanie ręczne');
		$this->assertSame($sets, $this->countSets());

		// Rekomendacja mieszana: operacja tylko dla konwersji na stronie.
		$setId = $this->proposals()->fromRecommendation($this->project, $this->owner, $mixed);
		$ops = $this->ops($setId);
		$this->assertCount(1, $ops);
		$this->assertTrue(str_ends_with((string) $ops[0]['resource_name'], '/conversionActions/902'));
		$this->assertSame(['counting_type' => 'ONE_PER_CLICK'], $ops[0]['payload']);

		// Action Plan: działanie z samą rekomendacją Smart – ręczne; z mieszaną – szkic z jedną operacją.
		$onlyManual = ['id' => 9001, 'title' => 'Popraw zliczanie połączeń', 'description' => 'Pomiar konwersji', 'recommendation_ids' => [$manual]];
		$both = ['id' => 9002, 'title' => 'Popraw pomiar konwersji', 'description' => 'Pomiar konwersji', 'recommendation_ids' => [$manual, $mixed]];
		$itemBlockers = $links->actionItemBlockers($this->project, [$onlyManual, $both]);
		$this->assertContains('Działanie ręczne', (string) $itemBlockers[9001]);
		$this->assertNull($itemBlockers[9002]);
		$this->assertThrows(ValidationFailed::class, fn () => $this->proposals()->fromActionItem($this->project, $this->owner, $onlyManual), 'Działanie ręczne');

		$planSet = $this->proposals()->fromActionItem($this->project, $this->owner, $both);
		$planOps = $this->ops($planSet);
		$this->assertCount(1, $planOps, 'Konwersje Smart pominięte, operacja tylko dla konwersji na stronie.');
		$this->assertSame([], array_filter($planOps, fn ($op) => str_ends_with((string) $op['resource_name'], '/903') || str_ends_with((string) $op['resource_name'], '/904')));
	}

	public function testChangeCenterRejectsReadOnlyFieldsAndExplainsLegacyOperations(): void
	{
		$this->smartAccount('smart-cc');
		$customer = (string) $this->connection['customer_id'];

		// Zestaw sprzed poprawki: operacje zliczania dla konwersji Smart zapisane bez kontroli (jak na produkcji).
		$setId = $this->center()->createSet($this->project, ['title' => 'Pomiar konwersji', 'source_type' => 'action_item'], $this->owner);
		$legacy = [];

		foreach (['903' => self::AD_CALLS, '904' => self::MAP_CALLS] as $id => $label) {
			$legacy[$id] = $this->sets()->insertOperation([
				'change_set_id' => $setId, 'project_id' => $this->project->id, 'position' => count($legacy) + 1, 'op_uuid' => wp_generate_uuid4(), 'type' => 'conversion_action.update',
				'resource_type' => 'conversion_action', 'resource_name' => 'customers/' . $customer . '/conversionActions/' . $id, 'label' => 'Zmień zliczanie „' . $label . '”',
				'payload' => ['counting_type' => 'ONE_PER_CLICK'], 'before_state' => ['name' => $label, 'type' => 'WEBPAGE'], 'before_source' => 'sync', 'risk' => 'high',
				'status' => ChangeStatus::OP_PENDING, 'depends_on' => [],
			]);
		}

		$web = $this->proposals()->conversionUpdate($this->project, $this->owner, $this->conversionId('902'), ['counting_type' => 'ONE_PER_CLICK'], $setId);
		$this->assertSame($setId, $web);
		$this->center()->refreshState($this->project, $setId, $this->owner);
		$local = $this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId));

		$this->assertFalse($local['ok']);
		$this->assertSame(array_values($legacy), array_keys($local['unsupported']), 'Tylko operacje Smart są oznaczone jako nieobsługiwane przez API.');

		foreach (['903' => 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL', '904' => 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL'] as $id => $type) {
			$messages = implode(' ', $local['unsupported'][$legacy[$id]]);
			$this->assertContains($type, $messages);
			$this->assertContains('usuń ją z zestawu', $messages);
			$this->assertTrue(! isset($local['confirmations'][$legacy[$id]]), 'To nie jest brak potwierdzenia.');
		}

		// Istniejący zestaw nie jest zmieniany automatycznie.
		$this->assertCount(3, $this->ops($setId));
		$this->assertSame(['counting_type' => 'ONE_PER_CLICK'], $this->sets()->findOperation($setId, $legacy['903'])['payload']);
		$this->assertSame(ChangeStatus::DRAFT, $this->set($setId)['status']);

		// Edycja pola tylko do odczytu i dodanie takiej operacji są odrzucane przez Change Center.
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->updateOperation($this->project, $setId, $legacy['903'], ['counting_type' => 'MANY_PER_CLICK'], $this->owner), 'tylko do odczytu');
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->addOperation($this->project, $setId, [
			'type' => 'conversion_action.update', 'resource_name' => 'customers/' . $customer . '/conversionActions/904', 'payload' => ['counting_type' => 'ONE_PER_CLICK'],
			'before_state' => ['type' => 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL', 'owner_customer' => 'customers/' . $customer],
		], $this->owner), 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL');

		// Wysłanie do przeglądu (a więc i zatwierdzenie) zablokowane do czasu usunięcia operacji; po usunięciu zestaw jest poprawny.
		$this->assertThrows(ValidationFailed::class, fn () => $this->center()->submit($this->project, $setId, $this->owner), 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL');
		$this->assertSame(ChangeStatus::DRAFT, $this->set($setId)['status']);

		foreach ($legacy as $opId) {
			$this->center()->removeOperation($this->project, $setId, $opId, $this->owner);
		}

		$after = $this->center()->validateLocally($this->project, $this->set($setId), $this->ops($setId));
		$this->assertTrue($after['ok'], json_encode($after['errors'], JSON_UNESCAPED_UNICODE));
		$this->assertSame([], $after['unsupported']);
		$this->assertSame([], array_filter($this->fake->mutations, fn ($m) => ! $m['validate_only']), 'Nic nie zostało wysłane do Google Ads.');
	}

	public function testGoogleAdsValidationWouldRejectTheUpdateAndCatalogNeverBuildsIt(): void
	{
		// Atrapa odwzorowuje UPDATE_NOT_SUPPORTED z Google Ads API v25: gdyby operacja ominęła Penny Pincher, Google i tak by
		// ją odrzucił. Tylko validateOnly (bez zapisu), tylko atrapa – żadne żądanie nie wychodzi do sieci.
		$this->smartAccount('smart-api');
		putenv('PP_GOOGLE_ADS_API_VALIDATION=1');
		$customer = (string) $this->connection['customer_id'];
		$update = fn (string $id) => ['conversionActionOperation' => ['update' => ['resourceName' => 'customers/' . $customer . '/conversionActions/' . $id, 'countingType' => 'ONE_PER_CLICK'], 'updateMask' => 'countingType']];
		$client = $this->make(\App\PennyPincher\Infrastructure\GoogleAds\Mutate\GoogleAdsMutateClient::class);
		$token = $this->make(\App\PennyPincher\Services\GoogleAds\ConnectionService::class)->accessToken($this->connection);
		$result = $client->mutate($token, $customer, null, [$update('903'), $update('902')], true, true);

		$this->assertFalse($result->ok(0), 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL – UPDATE_NOT_SUPPORTED.');
		$this->assertContains('UPDATE_NOT_SUPPORTED', json_encode($result->errors, JSON_UNESCAPED_UNICODE));
		$this->assertTrue($result->ok(1), 'Konwersja na stronie – aktualizacja możliwa.');
		$this->assertSame(0, $this->fake->executedMutations(), 'Walidacja niczego nie zmienia.');
		$this->assertSame('MANY_PER_CLICK', $this->fake->value('conversion_action', $this->fake->name('conversion_action', '903'), 'counting_type'));

		// Penny Pincher nie zbuduje takiej operacji nawet przy pominięciu walidacji (ostatnia linia obrony).
		$op = ['type' => 'conversion_action.update', 'resource_name' => 'customers/' . $customer . '/conversionActions/903', 'payload' => ['counting_type' => 'ONE_PER_CLICK']];
		$this->assertThrows(\InvalidArgumentException::class, fn () => OperationCatalog::build($op + ['before_state' => ['type' => 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL']], $customer, []), 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL');
	}

	// --- Pomocnicze ----------------------------------------------------------------------------------------

	private function localConversion(string $external, string $name, string $type): int
	{
		$now = $this->db->now();

		return $this->db->insert('conversion_actions', [
			'project_id' => $this->project->id, 'natural_key' => 'id:' . $external, 'external_id' => $external, 'name' => $name, 'category' => 'Połączenie telefoniczne',
			'category_code' => 'PHONE_CALL_LEAD', 'status' => 'enabled', 'status_raw' => 'ENABLED', 'type' => $type, 'primary_for_goal' => 1, 'counting_type' => 'MANY_PER_CLICK',
			'include_in_conversions' => 1, 'created_at' => $now, 'updated_at' => $now,
		]);
	}

	/**
	 * Rekomendacja CT003 (jak z audytu) z ustaleniami dla wskazanych działań konwersji.
	 *
	 * @param array<int, string> $externals
	 */
	private function counting(array $externals, string $title): int
	{
		$now = $this->db->now();
		$run = $this->db->insert('audit_runs', ['project_id' => $this->project->id, 'status' => 'completed', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'rules_version' => 'test', 'trigger_type' => 'manual', 'created_at' => $now]);

		foreach ($externals as $external) {
			$this->db->insert('audit_findings', [
				'audit_run_id' => $run, 'project_id' => $this->project->id, 'rule_id' => 'CT003', 'category' => 'conversion_tracking', 'severity' => 'warning', 'title' => $title,
				'description' => $title, 'evidence' => '[]', 'recommendation' => 'Zmień zliczanie', 'entity_type' => 'conversion_action', 'entity_id' => $this->conversionId($external),
				'fingerprint' => sha1('CT003' . $external . $title), 'penalty' => 2, 'score_dimension' => 'measurement', 'status' => 'open', 'detected_at' => $now,
			]);
		}

		return $this->db->insert('recommendations', [
			'project_id' => $this->project->id, 'fingerprint' => md5($title . microtime()), 'source' => 'audit', 'type' => 'audit_ct003', 'title' => $title,
			'description' => $title, 'rationale' => '', 'evidence' => '[]', 'action' => 'Zmień zliczanie na „Jedna”', 'campaign_id' => null, 'ad_group_id' => null,
			'priority' => 'medium', 'priority_score' => 50, 'confidence' => 'high', 'status' => 'new', 'is_obsolete' => 0,
			'first_seen_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now,
		]);
	}

	private function rec(int $id): array
	{
		return $this->make(\App\PennyPincher\Repositories\RecommendationRepository::class)->find($this->project->id, $id);
	}

	private function countSets(): int
	{
		return (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('change_sets') . ' WHERE project_id = ? AND status <> ?', [$this->project->id, ChangeStatus::CANCELLED]);
	}
}
