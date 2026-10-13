<?php

/**
 * Przygotowanie JEDNORAZOWEJ bazy testowej do testów HTTP formularzy Promptu 5.
 *
 *   PP_DB=<baza_testowa> PP_HTTP_PROJECT=31 PP_FAKE_GOOGLE_ADS_STATE=/tmp/pp-fake.json \
 *   PP_GOOGLE_ADS_CLIENT_ID=… PP_GOOGLE_ADS_CLIENT_SECRET=… PP_ENCRYPTION_KEY=… php tests/Http/setup.php
 *
 * Łączy projekt z atrapą konta Google Ads (OAuth na atrapie), synchronizuje dane z atrapy, zapisuje stan atrapy
 * konta (budżet, kampania, grupa, słowo, konwersje), tworzy zatwierdzony plan kampanii i wdrożony zestaw zmian
 * (do testu „Przygotuj cofnięcie”). NIGDY nie uruchamiaj na bazie produkcyjnej – skrypt odmawia pracy, jeśli
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
$campaign = (int) $db->scalar('SELECT id FROM ' . $db->table('campaigns') . ' WHERE project_id = ? AND external_id = ?', [$project->id, '111']);
$adGroup = (int) $db->scalar('SELECT id FROM ' . $db->table('ad_groups') . ' WHERE project_id = ? AND external_id = ?', [$project->id, '222']);

echo json_encode(['project' => $project->id, 'plan' => $planId, 'applied_set' => $applied, 'connection' => (int) $connection['id'], 'campaign' => $campaign, 'ad_group' => $adGroup]), "\n";
