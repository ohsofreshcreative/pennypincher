<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Metrics\Money;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Http\Controllers\MonitoringController;
use App\PennyPincher\Repositories\AlertRepository;
use App\PennyPincher\Services\Monitoring\AlertNotifier;
use App\PennyPincher\Services\Monitoring\MonitoringService;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Tests\IntegrationTestCase;

/**
 * Monitoring: wykrywanie na danych z bazy, idempotencja per synchronizacja, statusy, deduplikacja i e-mail (opt-in).
 * Wysyłka e-mail jest przechwytywana filtrem `pre_wp_mail` – nic nie wychodzi.
 */
class MonitoringTest extends IntegrationTestCase
{
	private array $mails = [];

	private ?\Closure $mailFilter = null;

	public function setUp(): void
	{
		parent::setUp();
		$this->mails = [];
		$this->mailFilter = function ($return, $atts) {
			$this->mails[] = $atts;

			return true;
		};
		add_filter('pre_wp_mail', $this->mailFilter, 1, 2);
	}

	public function tearDown(): void
	{
		remove_filter('pre_wp_mail', $this->mailFilter, 1);
		parent::tearDown();
	}

	public function testDetectsCpcSpikeOncePerSyncRunAndWithoutDuplicates(): void
	{
		[$owner, $project] = $this->project();
		$this->seed($project, 'enabled');
		$service = $this->make(MonitoringService::class);
		$alerts = $this->make(AlertRepository::class);

		$first = $service->run($project, 'sync', 501, $this->today());
		$this->assertFalse($first['skipped']);
		$this->assertSame(1, $first['created']);
		$alert = array_values($alerts->byFingerprint($project->id))[0];
		$this->assertSame('cpc_spike', $alert['type']);
		$this->assertSame('new', $alert['status']);
		$this->assertTrue($alert['campaign_id'] !== null);

		$this->assertTrue($service->run($project, 'sync', 501, $this->today())['skipped'], 'Ten sam przebieg synchronizacji nie jest monitorowany drugi raz.');
		$second = $service->run($project, 'sync', 502, $this->today());
		$this->assertSame(0, $second['created']);
		$this->assertSame(1, $second['updated']);
		$this->assertCount(1, $alerts->byFingerprint($project->id), 'Bez duplikatów alertów.');
		$this->assertSame(2, (int) $alerts->find($project->id, (int) $alert['id'])['occurrences']);
		$this->assertSame(1, $alerts->newCount($project->id));
	}

	public function testPausedCampaignAndMissingDataDoNotAlert(): void
	{
		[, $project] = $this->project();
		$this->seed($project, 'paused');
		$this->assertSame(0, $this->make(MonitoringService::class)->run($project, 'manual', null, $this->today())['detected']);

		[, $empty] = $this->project();
		$this->assertSame(0, $this->make(MonitoringService::class)->run($empty, 'manual', null, $this->today())['detected'], 'Brak danych – brak alarmów o spadkach.');
	}

	public function testStatusesMuteResolveReopenAndHistory(): void
	{
		[$owner, $project] = $this->project();
		$this->seed($project, 'enabled');
		$service = $this->make(MonitoringService::class);
		$alerts = $this->make(AlertRepository::class);
		$service->run($project, 'manual', null, $this->today());
		$id = (int) array_values($alerts->byFingerprint($project->id))[0]['id'];

		$service->updateStatus($project, $id, 'acknowledged', $owner, 'Sprawdzam stawki');
		$this->assertSame('acknowledged', $alerts->find($project->id, $id)['status']);
		$service->run($project, 'manual', null, $this->today());
		$this->assertSame('acknowledged', $alerts->find($project->id, $id)['status'], 'Potwierdzony alert nie wraca do „Nowy”.');

		$service->updateStatus($project, $id, 'muted', $owner, null, 7);
		$this->assertTrue($alerts->find($project->id, $id)['muted_until'] !== null);
		$service->run($project, 'manual', null, $this->today());
		$this->assertSame('muted', $alerts->find($project->id, $id)['status'], 'Wyciszony do daty – bez zmian.');
		$this->db->update('alerts', ['muted_until' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $id]);
		$service->run($project, 'manual', null, $this->today());
		$this->assertSame('new', $alerts->find($project->id, $id)['status'], 'Po wyciszeniu problem nadal występuje.');

		$service->updateStatus($project, $id, 'resolved', $owner);
		$service->run($project, 'manual', null, $this->today());
		$this->assertSame('new', $alerts->find($project->id, $id)['status'], 'Rozwiązany, ale problem wrócił – ponownie otwarty.');

		$events = array_column($alerts->events($project->id, $id), 'event');
		$this->assertTrue(in_array('reopened', $events, true) && in_array('unmuted', $events, true) && in_array('detected', $events, true));
		$this->assertThrows(ValidationFailed::class, fn () => $service->updateStatus($project, $id, 'deleted', $owner));

		// Problem znika – alert nie jest zamykany automatycznie, tylko oznaczony jako nieaktywny.
		$this->db->statement('DELETE FROM ' . $this->db->table('campaign_metrics') . ' WHERE project_id = ? AND date_from >= ?', [$project->id, '2026-10-05']);
		$this->seedDays($project, (int) $alerts->find($project->id, $id)['campaign_id'], '2026-10-05', '2026-10-11', 15, 30 * Money::MICROS, 1.0);
		$service->run($project, 'manual', null, $this->today());
		$after = $alerts->find($project->id, $id);
		$this->assertSame('new', $after['status']);
		$this->assertFalse((bool) $after['condition_active']);
	}

	public function testEmailIsOptInRateLimitedAndDeduplicated(): void
	{
		[$owner, $project] = $this->project();
		$member = $this->user('mon-member');
		$this->addMember($project->organizationId, $member, Role::Member);
		$this->seed($project, 'enabled');
		$service = $this->make(MonitoringService::class);
		$notifier = $this->make(AlertNotifier::class);
		$service->run($project, 'manual', null, $this->today());

		$this->assertSame(0, $notifier->notify($project), 'Bez zgody – brak e-maili.');
		$this->assertSame([], $this->mails);

		AlertNotifier::savePreferences($owner, $project->id, true, 'critical');
		$this->assertSame(0, $notifier->notify($project), 'Alert „ostrzeżenie” nie spełnia progu „krytyczne”.');
		AlertNotifier::savePreferences($owner, $project->id, true, 'warning');
		$this->assertSame(1, $notifier->notify($project));
		$this->assertCount(1, $this->mails);
		$this->assertContains('CPC', (string) $this->mails[0]['message']);
		$this->assertContains('nie zmienia niczego na koncie Google Ads', (string) $this->mails[0]['message']);

		$this->assertSame(0, $notifier->notify($project), 'Najwyżej jeden e-mail na dobę.');
		$prefs = get_user_meta($owner, AlertNotifier::META, true);
		$prefs[$project->id]['last_sent'] = time() - 2 * DAY_IN_SECONDS;
		update_user_meta($owner, AlertNotifier::META, $prefs);
		$service->run($project, 'manual', null, $this->today());
		$this->assertSame(0, $notifier->notify($project), 'Ten sam, trwający alert nie jest wysyłany ponownie.');

		AlertNotifier::savePreferences($owner, $project->id, false, 'warning');
		$this->assertFalse(AlertNotifier::preferences($owner, $project->id)['enabled']);
		$this->assertCount(1, $this->mails);
	}

	public function testMonitoringIsIsolatedAndRoleChecked(): void
	{
		[$owner, $project] = $this->project();
		$this->seed($project, 'enabled');
		$this->make(MonitoringService::class)->run($project, 'manual', null, $this->today());
		$id = (int) array_values($this->make(AlertRepository::class)->byFingerprint($project->id))[0]['id'];
		$bob = $this->user('mon-bob');
		[, $other] = $this->project($bob);
		$member = $this->user('mon-member2');
		$this->addMember($project->organizationId, $member, Role::Member);

		$this->assertThrows(ValidationFailed::class, fn () => $this->make(MonitoringService::class)->updateStatus($other, $id, 'resolved', $bob));

		wp_set_current_user($bob);
		$controller = $this->make(MonitoringController::class);
		$this->assertThrows(NotFound::class, fn () => $controller->index($project->id, Request::create('/')));
		$this->assertThrows(NotFound::class, fn () => $controller->updateStatus($project->id, $id, Request::create('/', 'POST', ['status' => 'resolved'])));

		$this->assertSame('new', $this->make(AlertRepository::class)->find($project->id, $id)['status']);

		// Członek organizacji obsługuje alerty (potwierdza, wycisza, rozwiązuje).
		wp_set_current_user($member);
		$this->assertSame(200, $controller->index($project->id, Request::create('/', 'GET', ['status' => 'new']))->getStatusCode());
		$controller->updateStatus($project->id, $id, Request::create('/', 'POST', ['status' => 'acknowledged']));
		$this->assertSame('acknowledged', $this->make(AlertRepository::class)->find($project->id, $id)['status']);
	}

	// --- Pomocnicze ---------------------------------------------------------------------------

	private function today(): DateTimeImmutable
	{
		return new DateTimeImmutable('2026-10-12', new \DateTimeZone('Europe/Warsaw'));
	}

	/**
	 * @return array{0: int, 1: Project}
	 */
	private function project(?int $owner = null): array
	{
		$owner ??= $this->user('monitor');

		return [$owner, $this->organizationWithProject($owner, 'Monitoring ' . wp_generate_password(4, false))['project']];
	}

	/**
	 * 28 dni bazowych (CPC 2 zł) i 7 ostatnich dni ze wzrostem CPC do 3,5 zł – pełne pokrycie danymi z API.
	 */
	private function seed(Project $project, string $status): void
	{
		$now = $this->db->now();
		$campaignId = $this->db->insert('campaigns', [
			'project_id' => $project->id, 'natural_key' => sha1('mon' . $project->id), 'external_id' => '777', 'name' => 'Szukaj | Strony',
			'status' => $status, 'channel_type' => 'search', 'created_at' => $now, 'updated_at' => $now,
		]);
		$this->seedDays($project, $campaignId, '2026-09-07', '2026-10-04', 15, 30 * Money::MICROS, 1.0);
		$this->seedDays($project, $campaignId, '2026-10-05', '2026-10-11', 15, (int) (52.5 * Money::MICROS), 1.0);
		$this->db->insert('data_coverage', ['project_id' => $project->id, 'source' => 'api', 'dataset' => 'campaigns', 'date_from' => '2026-08-01', 'date_to' => '2026-10-11', 'created_at' => $now]);
	}

	private function seedDays(Project $project, int $campaignId, string $from, string $to, int $clicks, int $cost, float $conversions): void
	{
		$rows = [];
		$now = $this->db->now();

		for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
			$rows[] = [
				'project_id' => $project->id, 'campaign_id' => $campaignId, 'granularity' => 'day', 'date_from' => $day->format('Y-m-d'), 'date_to' => $day->format('Y-m-d'),
				'source' => 'api', 'impressions' => 300, 'clicks' => $clicks, 'cost_micros' => $cost, 'conversions' => $conversions, 'all_conversions' => $conversions, 'currency' => 'PLN',
				'created_at' => $now, 'updated_at' => $now,
			];
		}

		$this->db->insertMany('campaign_metrics', $rows);
	}
}
