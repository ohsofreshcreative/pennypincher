<?php

/**
 * Przygotowanie JEDNORAZOWEJ bazy testowej do testów HTTP formularzy Promptu 5.
 *
 *   PP_DB=<baza_testowa> PP_HTTP_PROJECT=31 PP_FAKE_GOOGLE_ADS_STATE=/tmp/pp-fake.json \
 *   PP_GOOGLE_ADS_CLIENT_ID=… PP_GOOGLE_ADS_CLIENT_SECRET=… PP_ENCRYPTION_KEY=… php tests/Http/setup.php
 *
 * Łączy projekt z atrapą konta Google Ads (OAuth na atrapie), synchronizuje dane z atrapy, zapisuje stan atrapy
 * konta (budżet, kampania, grupa, słowo, konwersje), tworzy zatwierdzony plan kampanii, wdrożony zestaw zmian
 * (do testu „Przygotuj cofnięcie”) oraz konwersje Smart (typy tylko do odczytu) z zestawem sprzed poprawki
 * i rekomendacją CT003. NIGDY nie uruchamiaj na bazie produkcyjnej – skrypt odmawia pracy, jeśli
 * WP_ENVIRONMENT_TYPE = production albo nazwa bazy nie zawiera „test”.
 */

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';

require dirname(__DIR__, 5) . '/wp-load.php';
require_once dirname(__DIR__) . '/Support/GoogleAdsMock.php';
require_once dirname(__DIR__) . '/Support/FakeGoogleAdsAccount.php';

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\Planner\PlanStatus;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use App\PennyPincher\Services\GoogleAds\SyncService;
use App\PennyPincher\Services\Planner\BudgetPlannerService;
use App\PennyPincher\Services\Planner\CampaignPlanService;
use Roots\Acorn\Application;
use Tests\Support\FakeGoogleAdsAccount;
use Tests\Support\GoogleAdsMock;

if (wp_get_environment_type() === 'production' || ! str_contains((string) DB_NAME, 'test')) {
	fwrite(STDERR, "Odmowa: skrypt działa tylko na jednorazowej bazie testowej (nazwa bazy musi zawierać „test”).\n");
	exit(1);
}

$app = Application::getInstance();
$make = fn (string $class) => $app->make($class);
$owner = 1;
$projectId = (int) (getenv('PP_HTTP_PROJECT') ?: 31);
$project = $make(ProjectRepository::class)->findForUser($owner, $projectId) ?? (fwrite(STDERR, "Brak projektu {$projectId} dla użytkownika {$owner} (ustaw PP_HTTP_PROJECT).\n") && exit(1));

// 1. OAuth i synchronizacja – wyłącznie na atrapie (pre_http_request).
$mock = (new GoogleAdsMock())->withDefaultAccount()->install();
$connections = $make(ConnectionService::class);
parse_str((string) parse_url($connections->begin($project, $owner), PHP_URL_QUERY), $query);
$state = $connections->consumeState($query['state'], $owner);
$connections->complete($project, $owner, 'mock-code', $state['verifier']);
$connection = $make(GoogleAdsConnectionRepository::class)->currentForProject($project->id);
$connections->selectAccount($project, $connection, GoogleAdsMock::CUSTOMER);
$connection = $make(GoogleAdsConnectionRepository::class)->currentForProject($project->id);
$sync = $make(SyncService::class);
$run = $sync->process($sync->enqueue($connection, 'backfill'), time() + 300);
$mock->uninstall();

if ($run['status'] !== 'completed') {
	exit('Synchronizacja z atrapy nie powiodła się: ' . $run['last_error'] . "\n");
}

// 2. Stan atrapy konta (odczyty GAQL i mutate w kolejnych żądaniach HTTP).
$fake = new FakeGoogleAdsAccount();
$budget = $fake->budget('444', 50_000_000);
$fake->campaign('111', 'Szukaj | Marka', $budget);
$fake->adGroup('222', '111', 'Marka');
$fake->keyword('222', '333', 'sklep meble', 'PHRASE');
$fake->campaignNegative('111', '501', 'darmowe', 'PHRASE');
$fake->conversionAction('901', 'Wyświetlenie strony kontakt', ['category' => 'PAGE_VIEW']);
$fake->conversionAction('902', 'Formularz', ['primary_for_goal' => false, 'counting_type' => 'MANY_PER_CLICK']);
$fake->customerGoal('PAGE_VIEW', 'WEBSITE', true);
// Konwersje kampanii inteligentnych – typy „Read only” w Google Ads API v25 (zgłoszenie z produkcji).
$smartLead = ['category' => 'PHONE_CALL_LEAD', 'counting_type' => 'MANY_PER_CLICK', 'origin' => 'CALL_FROM_ADS'];
$fake->conversionAction('903', 'Kliknięcia przycisku połączenia w reklamach inteligentnych', ['type' => 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL'] + $smartLead);
$fake->conversionAction('904', 'Kliknięcia przycisku połączenia w reklamach inteligentnych wyświetlanych w Mapach', ['type' => 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL', 'origin' => 'GOOGLE_HOSTED'] + $smartLead);
file_put_contents((string) getenv('PP_FAKE_GOOGLE_ADS_STATE'), json_encode($fake->export()));

// 3. Zatwierdzony plan kampanii (przycisk „Przygotuj wdrożenie do Google Ads”).
$planner = $make(CampaignPlanService::class);
$planId = $planner->create($project, ['name' => 'Plan HTTP', 'language' => 'pl', 'monthly_budget' => '1500'], $owner);
$planCampaign = $planner->addCampaign($project, $planId, 'Plan HTTP | Sofy');
$make(BudgetPlannerService::class)->setCampaignBudget($project, $planId, $planCampaign, '1216');
$planGroup = $planner->addGroup($project, $planId, $planCampaign, 'Sofy narożne');
$planner->addKeywords($project, $planId, $planGroup, ['sofa narożna', 'narożnik do salonu'], 'phrase');
$planner->saveAd($project, $planId, $planGroup, ['headlines' => ['Sofy narożne', 'Narożniki na wymiar', 'Darmowa dostawa'], 'descriptions' => ['Wybierz sofę dopasowaną do salonu.', 'Umów bezpłatną konsultację.'], 'final_url' => 'https://example.test/sofy']);
$app->make(\App\PennyPincher\Infrastructure\Database\Db::class)->update('campaign_plans', ['status' => PlanStatus::APPROVED], ['id' => $planId]);

// 4. Zestaw „wdrożony” (wpis historyczny – bez kontaktu z Google) do testu „Przygotuj cofnięcie”.
$sets = $make(ChangeSetRepository::class);
$now = gmdate('Y-m-d H:i:s');
$applied = $sets->create([
	'project_id' => $project->id, 'organization_id' => $project->organizationId, 'connection_id' => (int) $connection['id'], 'customer_id' => GoogleAdsMock::CUSTOMER,
	'title' => 'Test HTTP: zmiana budżetu (wdrożona)', 'source_type' => 'manual', 'status' => ChangeStatus::APPLIED, 'risk_level' => 'high',
	'deploy_finished_at' => $now, 'verified_at' => $now, 'created_by' => $owner,
]);
$sets->insertOperation([
	'change_set_id' => $applied, 'project_id' => $project->id, 'position' => 1, 'op_uuid' => wp_generate_uuid4(), 'type' => 'campaign_budget.update',
	'resource_type' => 'campaign_budget', 'resource_name' => $budget, 'label' => 'Zmień budżet dzienny', 'payload' => ['amount_micros' => 70_000_000],
	'before_state' => ['amount_micros' => 50_000_000, 'explicitly_shared' => false, 'reference_count' => 1], 'before_source' => 'api', 'before_read_at' => $now,
	'risk' => 'high', 'status' => ChangeStatus::OP_VERIFIED, 'applied_at' => $now, 'verified_at' => $now, 'depends_on' => [],
]);

$db = $app->make(\App\PennyPincher\Infrastructure\Database\Db::class);

// 5. Konwersje Smart w bazie, zestaw sprzed poprawki (operacje zliczania zapisane bez kontroli) i rekomendacja CT003.
$smart = [];

foreach (['903' => ['Kliknięcia przycisku połączenia w reklamach inteligentnych', 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL'], '904' => ['Kliknięcia przycisku połączenia w reklamach inteligentnych wyświetlanych w Mapach', 'SMART_CAMPAIGN_MAP_CLICKS_TO_CALL']] as $external => [$name, $type]) {
	$smart[$external] = (int) ($db->scalar('SELECT id FROM ' . $db->table('conversion_actions') . ' WHERE project_id = ? AND external_id = ?', [$project->id, $external]) ?: $db->insert('conversion_actions', [
		'project_id' => $project->id, 'natural_key' => 'id:' . $external, 'external_id' => $external, 'name' => $name, 'category' => 'Połączenie telefoniczne', 'category_code' => 'PHONE_CALL_LEAD',
		'status' => 'enabled', 'status_raw' => 'ENABLED', 'type' => $type, 'primary_for_goal' => 1, 'counting_type' => 'MANY_PER_CLICK', 'include_in_conversions' => 1, 'created_at' => $now, 'updated_at' => $now,
	]));
}

$smartSet = $sets->create([
	'project_id' => $project->id, 'organization_id' => $project->organizationId, 'connection_id' => (int) $connection['id'], 'customer_id' => GoogleAdsMock::CUSTOMER,
	'title' => 'Test HTTP: pomiar konwersji (zestaw sprzed poprawki)', 'source_type' => 'action_item', 'status' => ChangeStatus::DRAFT, 'risk_level' => 'high', 'created_by' => $owner,
]);
$smartOps = [];

foreach (['903', '904'] as $i => $external) {
	$smartOps[$external] = $sets->insertOperation([
		'change_set_id' => $smartSet, 'project_id' => $project->id, 'position' => $i + 1, 'op_uuid' => wp_generate_uuid4(), 'type' => 'conversion_action.update',
		'resource_type' => 'conversion_action', 'resource_name' => 'customers/' . GoogleAdsMock::CUSTOMER . '/conversionActions/' . $external, 'label' => 'Zmień zliczanie (Smart ' . $external . ')',
		'payload' => ['counting_type' => 'ONE_PER_CLICK'], 'before_state' => null, 'before_source' => 'sync', 'risk' => 'high', 'status' => ChangeStatus::OP_PENDING, 'depends_on' => [],
	]);
}

$auditRun = $db->insert('audit_runs', ['project_id' => $project->id, 'status' => 'completed', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'rules_version' => 'http-test', 'trigger_type' => 'manual', 'created_at' => $now]);
$smartTitle = 'Test HTTP: połączenia Smart liczone jako „każda”';

foreach ($smart as $external => $actionId) {
	$db->insert('audit_findings', [
		'audit_run_id' => $auditRun, 'project_id' => $project->id, 'rule_id' => 'CT003', 'category' => 'conversion_tracking', 'severity' => 'warning', 'title' => $smartTitle,
		'description' => $smartTitle, 'evidence' => '[]', 'recommendation' => 'Zmień zliczanie', 'entity_type' => 'conversion_action', 'entity_id' => $actionId,
		'fingerprint' => sha1('http-ct003-' . $external), 'penalty' => 2, 'score_dimension' => 'measurement', 'status' => 'open', 'detected_at' => $now,
	]);
}

$smartRec = $db->insert('recommendations', [
	'project_id' => $project->id, 'fingerprint' => sha1('http-ct003-rec-' . microtime()), 'source' => 'audit', 'type' => 'audit_ct003', 'title' => $smartTitle,
	'description' => $smartTitle, 'rationale' => '', 'evidence' => '[]', 'action' => 'Zmień zliczanie na „Jedna”', 'campaign_id' => null, 'ad_group_id' => null,
	'priority' => 'high', 'priority_score' => 99, 'confidence' => 'high', 'status' => 'new', 'is_obsolete' => 0, 'first_seen_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now,
]);

$campaign = (int) $db->scalar('SELECT id FROM ' . $db->table('campaigns') . ' WHERE project_id = ? AND external_id = ?', [$project->id, '111']);
$adGroup = (int) $db->scalar('SELECT id FROM ' . $db->table('ad_groups') . ' WHERE project_id = ? AND external_id = ?', [$project->id, '222']);

echo json_encode([
	'project' => $project->id, 'plan' => $planId, 'applied_set' => $applied, 'connection' => (int) $connection['id'], 'campaign' => $campaign, 'ad_group' => $adGroup,
	'smart_conversion' => $smart['903'], 'smart_set' => $smartSet, 'smart_op' => $smartOps['903'], 'smart_recommendation' => $smartRec,
]), "\n";
