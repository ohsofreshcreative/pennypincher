<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Services\Analysis\RecommendationService;
use App\PennyPincher\Services\Assistant\AssistantService;
use App\PennyPincher\Services\GoogleAds\SyncService;
use App\PennyPincher\Services\Monitoring\MonitoringService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use App\PennyPincher\Support\Navigation;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;

require_once __DIR__ . '/ChangeCenterTestCase.php';

/**
 * Asystent (pp2_assistant): tylko odczyt, wyjaśnienia z istniejących wyników, zapisy wyłącznie przez Change Center
 * po zgodzie; harmonogramy – istniejące (WP-Cron sprzątania importów, kolejka synchronizacji, monitoring per przebieg).
 */
class AssistantTest extends ChangeCenterTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::ASSISTANT);
		parent::tearDown();
	}

	private function flag(bool $on): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::ASSISTANT);
		if ($on) {
			add_filter('pennypincher/feature/' . Feature::ASSISTANT, '__return_true');
		}
	}

	private function get(string $url, ?int $user = null): \Symfony\Component\HttpFoundation\Response
	{
		wp_set_current_user($user ?? $this->owner);
		$_SERVER['REQUEST_METHOD'] = 'GET';

		return Application::getInstance()->make(Kernel::class)->handle(Request::create($url, 'GET'));
	}

	private function body(string $url): string
	{
		$response = $this->get($url);
		$this->assertSame(200, $response->getStatusCode(), $url);

		return (string) $response->getContent();
	}

	private function recommendation(string $type, string $title, int $score, ?int $campaignId = null): int
	{
		$now = $this->db->now();

		return $this->db->insert('recommendations', [
			'project_id' => $this->project->id, 'fingerprint' => md5($type . $title . microtime()), 'source' => 'audit', 'type' => $type, 'title' => $title,
			'description' => $title . ' – 12 kliknięć bez konwersji.', 'rationale' => '', 'evidence' => wp_json_encode(['pairs' => [['Kliknięcia', '12']], 'sources' => ['audit']]),
			'action' => 'Wyłącz sieć partnerów wyszukiwania.', 'campaign_id' => $campaignId, 'ad_group_id' => null, 'priority' => 'medium', 'priority_score' => $score,
			'confidence' => 'high', 'status' => 'new', 'is_obsolete' => 0, 'date_from' => '2026-05-01', 'date_to' => '2026-05-31',
			'first_seen_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now,
		]);
	}

	private function state(): array
	{
		$out = [];
		foreach (['recommendations', 'recommendation_events', 'action_items', 'change_sets', 'change_operations', 'alerts', 'monitoring_runs', 'sync_runs', 'analysis_runs'] as $table) {
			$out[$table] = $this->db->select('SELECT * FROM ' . $this->db->table($table) . ' WHERE project_id = ? ORDER BY id', [$this->project->id]);
		}

		return $out;
	}

	public function testAssistantIsReadOnlyAndExplainsExistingResults(): void
	{
		$this->account('as-read', true);
		$this->flag(true);
		$low = $this->recommendation('x_low', 'Niski priorytet', 10);
		$top = [];
		foreach ([90, 70, 50] as $score) {
			$top[] = $this->recommendation('audit_cs001', 'Rekomendacja ' . $score, $score, $this->campaignId());
		}

		$before = $this->state();
		$cron = array_keys(_get_cron_array() ?: []);
		$html = $this->body(AppUrl::project($this->project->id, 'assistant'));
		$explain = $this->body(AppUrl::project($this->project->id, 'assistant', ['recommendation' => $top[0]]));
		$this->assertSame($before, $this->state(), 'Asystent nic nie zapisuje (statusy, historia, zmiany, przebiegi).');
		$this->assertSame($cron, array_keys(_get_cron_array() ?: []), 'Asystent nie dodaje harmonogramów.');

		foreach ([$html, $explain] as $page) {
			$main = substr($page, strpos($page, '<main'));
			$this->assertFalse(str_contains(strtolower($main), 'method="post"'), 'Brak formularzy zapisu w asystencie.');
		}

		// TOP 3 wg priorytetu, bez rekomendacji o niskim priorytecie.
		preg_match_all('/data-top="(\d+)"/', $html, $m);
		$expected = array_map('intval', $this->db->column('SELECT id FROM ' . $this->db->table('recommendations') . " WHERE project_id = ? AND status IN ('new', 'to_review') AND is_obsolete = 0 ORDER BY priority_score DESC, id DESC LIMIT 3", [$this->project->id]));
		$this->assertSame($expected, array_map('intval', $m[1]), 'TOP 3 = kolejność priorytetu z bazy.');
		$this->assertSame($top[0], $expected[0]);
		$this->assertFalse(str_contains($html, 'data-top="' . $low . '"'));

		// Wyjaśnienie: dowody i opis z wyniku reguły, następny krok = decyzja użytkownika.
		$this->assertContains('data-explain="' . $top[0] . '"', $explain);
		$this->assertContains('12 kliknięć bez konwersji', $explain);
		$this->assertContains('data-next="recommendation"', $explain);

		// Po akceptacji: szkic zmiany tylko z wymaganą zgodą; szkic nie wysyła nic do Google Ads.
		$this->make(RecommendationService::class)->updateStatus($this->project, $top[0], 'accepted', $this->owner, null);
		$this->assertContains('data-next="prepare"', $this->body(AppUrl::project($this->project->id, 'assistant', ['recommendation' => $top[0]])));
		$setId = $this->proposals()->fromRecommendation($this->project, $this->owner, $top[0]);
		$explain = $this->body(AppUrl::project($this->project->id, 'assistant', ['recommendation' => $top[0]]));
		$this->assertContains('data-next="change"', $explain);
		$this->assertContains('wymaga Twojej zgody', $explain);
		$this->assertContains('href="' . AppUrl::project($this->project->id, 'changes/' . $setId) . '"', $explain);
		$this->assertSame(0, $this->fake->executedMutations());

		$this->approve($setId);
		$this->assertSame(ChangeStatus::READY, $this->set($setId)['status']);
		$this->assertContains('data-consent="' . $setId . '"', $this->body(AppUrl::project($this->project->id, 'assistant')), 'Zatwierdzony zestaw czeka na świadome wdrożenie.');
		$this->assertSame(0, $this->fake->executedMutations(), 'Asystent i zatwierdzenie nie wdrażają.');
	}

	public function testFallbackFlagAndIsolation(): void
	{
		$this->account('as-flag');
		$rec = $this->recommendation('x', 'A', 40);

		$this->flag(false);
		$this->assertTrue($this->get(AppUrl::project($this->project->id, 'assistant'))->isRedirect(AppUrl::project($this->project->id, 'recommendations')));
		$this->assertTrue($this->get(AppUrl::project($this->project->id, 'assistant', ['recommendation' => $rec]))->isRedirect(AppUrl::project($this->project->id, 'recommendations', ['obsolete' => 'all', 'open' => $rec]) . '#rec-' . $rec));
		$this->assertSame(Navigation::GROUPS['optimization'], Navigation::groups()['optimization']);

		$this->flag(true);
		$items = array_keys(Navigation::groups()['optimization']['items']);
		$this->assertSame('assistant', end($items));
		$this->assertSame('optimization', Navigation::groupOf('assistant'));

		// Projekt bez analiz: jeden krok konfiguracji zamiast list „brak analizy”.
		$empty = $this->organizationWithProject($this->owner, 'Pusty')['project'];
		$html = $this->body(AppUrl::project($empty->id, 'assistant'));
		$this->assertContains('Brak analizy', $html);
		$this->assertSame(1, substr_count($html, 'Brak analizy'));

		$other = $this->user('as-other');
		$foreign = $this->organizationWithProject($other, 'Obcy');
		$this->assertSame(404, $this->get(AppUrl::project($foreign['project']->id, 'assistant'))->getStatusCode());
		$this->assertSame(404, $this->get(AppUrl::project($this->project->id, 'assistant', ['recommendation' => 999999]))->getStatusCode());
		$foreignRec = $this->db->insert('recommendations', ['project_id' => $foreign['project']->id] + array_diff_key($this->db->first('SELECT * FROM ' . $this->db->table('recommendations') . ' WHERE id = ?', [$rec]), ['id' => 0, 'project_id' => 0, 'fingerprint' => 0]) + ['fingerprint' => md5('obcy' . microtime())]);
		$this->assertSame(404, $this->get(AppUrl::project($this->project->id, 'assistant', ['recommendation' => $foreignRec]))->getStatusCode(), 'Cudza rekomendacja = 404.');
		$this->assertThrows(\App\PennyPincher\Domain\Support\NotFound::class, fn () => $this->make(AssistantService::class)->explain($this->project, $foreignRec));
	}

	public function testExistingSchedulesRunOnceWithoutDuplicates(): void
	{
		$this->account('as-cron');

		// WP-Cron: sprzątanie importów zaplanowane raz, kolejne „init” nie dubluje zdarzenia.
		do_action('init');
		do_action('init');
		$events = 0;
		foreach (_get_cron_array() ?: [] as $hooks) {
			$events += count($hooks['pennypincher_prune_staged_imports'] ?? []);
		}
		$this->assertSame(1, $events, 'Jedno zdarzenie sprzątania importów.');
		$this->assertSame([], array_values(array_filter(array_merge(...array_map('array_keys', array_values(_get_cron_array() ?: []))), fn ($h) => str_contains($h, 'assistant'))), 'Asystent bez własnych zdarzeń cron.');

		// Kolejka synchronizacji: po zakończonym backfillu nic nowego; przy otwartym przebiegu brak duplikatu.
		$sync = $this->make(SyncService::class);
		$this->assertSame(0, $sync->schedule(), 'Świeża synchronizacja – nic do zaplanowania.');
		$this->db->update('sync_runs', ['finished_at' => gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS)], ['connection_id' => (int) $this->connection['id']]);
		$this->assertSame(1, $sync->schedule(), 'Po upływie doby – jedna synchronizacja przyrostowa.');
		$this->assertSame(0, $sync->schedule(), 'Otwarty przebieg blokuje duplikat.');
		$this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM " . $this->db->table('sync_runs') . " WHERE connection_id = ? AND status IN ('queued', 'running')", [(int) $this->connection['id']]));

		// Po synchronizacji: analiza i monitoring uruchomione przez istniejący przebieg, monitoring raz na przebieg.
		$runId = (int) $this->db->scalar("SELECT id FROM " . $this->db->table('sync_runs') . " WHERE connection_id = ? AND status = 'completed' ORDER BY id LIMIT 1", [(int) $this->connection['id']]);
		$this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('monitoring_runs') . ' WHERE project_id = ? AND sync_run_id = ?', [$this->project->id, $runId]));
		$this->assertTrue((int) $this->db->scalar("SELECT COUNT(*) FROM " . $this->db->table('analysis_runs') . " WHERE project_id = ? AND trigger_type = 'sync'", [$this->project->id]) > 0, 'Analiza po synchronizacji.');
		$this->assertTrue($this->make(MonitoringService::class)->run($this->project, 'sync', $runId)['skipped'], 'Ponowne uruchomienie dla tego samego przebiegu jest pomijane.');
	}
}
