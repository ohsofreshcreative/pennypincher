<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Audit\AuditScore;
use App\PennyPincher\Domain\Conversions\ConversionClass;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Http\Controllers\BudgetLeaksController;
use App\PennyPincher\Http\Controllers\KeywordOpportunitiesController;
use App\PennyPincher\Http\Controllers\RecommendationsController;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\ConversionActionRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\Analysis\AnalysisService;
use App\PennyPincher\Services\Analysis\BudgetLeakService;
use App\PennyPincher\Services\Analysis\ConversionBreakdown;
use App\PennyPincher\Services\Analysis\RecommendationService;
use App\PennyPincher\Services\AuditService;
use App\PennyPincher\Services\DemoProjectService;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Tests\IntegrationTestCase;

/**
 * Okres audytu, brak aktywności, historia, Budget Leaks, rekomendacje (deduplikacja, statusy, nieaktualność),
 * klasyfikacja konwersji i izolacja danych między organizacjami.
 */
class AnalysisModulesTest extends IntegrationTestCase
{
	public function testPeriodWithoutActivityIsInformationNotAnError(): void
	{
		$owner = $this->user('noact');
		$project = $this->organizationWithProject($owner, 'Bez aktywności')['project'];
		$yesterday = new DateTimeImmutable('yesterday', $project->timezone());
		// Kampania aktywna 200–150 dni temu, potem wyłączona; API pokrywa cały rok (brak wierszy = brak wydatków).
		$this->seedApiCampaign($project, $yesterday->modify('-399 days'), $yesterday, $yesterday->modify('-200 days'), $yesterday->modify('-150 days'));

		$service = $this->make(AuditService::class);
		$audits = $this->make(AuditRepository::class);
		$recent = AnalysisPeriod::fromPreset('last_30', $project->timezone());
		$year = AnalysisPeriod::fromPreset('last_365', $project->timezone());

		$recentRun = $audits->findRun($project->id, $service->run($project, 'manual', $owner, $recent));
		$this->assertSame('no_activity', $recentRun['data_quality']['activity']['state']);
		$this->assertSame(AuditScore::NO_ACTIVITY, $recentRun['score_breakdown']['reason']);
		$this->assertSame('W wybranym okresie kampanie nie generowały wydatków ani wyświetleń. Wybierz wcześniejszy okres, aby przeanalizować wyniki historyczne.', AuditScore::NO_ACTIVITY);
		$severities = array_column($audits->findings((int) $recentRun['id']), 'severity', 'rule_id');
		$this->assertFalse(in_array('critical', $severities, true), 'Brak aktywności nie jest błędem krytycznym: ' . json_encode($severities));
		$this->assertSame('info', $severities['DQ008'] ?? null);
		$this->assertSame('Google Ads API', $recentRun['data_quality']['activity']['source_label']);

		$yearRun = $audits->findRun($project->id, $service->run($project, 'manual', $owner, $year));
		$this->assertTrue(in_array($yearRun['data_quality']['activity']['state'], ['complete', 'partial'], true));
		$this->assertSame(51, (int) $yearRun['data_quality']['activity']['active_days']);
		$this->assertSame('last_365', $yearRun['range_preset']);

		// Audyty różnych okresów nie nadpisują się.
		$this->assertSame((int) $recentRun['id'], (int) $audits->latestRunForPeriod($project->id, $recent->range->fromString(), $recent->range->toString())['id']);
		$this->assertSame((int) $yearRun['id'], (int) $audits->latestRunForPeriod($project->id, $year->range->fromString(), $year->range->toString())['id']);

		// Budget Leaks w okresie bez aktywności: zero fałszywych alarmów.
		$leakRun = $this->make(BudgetLeakService::class)->run($project, $recent, 'manual', $owner);
		$this->assertSame([], $this->make(AnalysisRepository::class)->leaks($leakRun));
		$this->assertSame('no_activity', $this->make(AnalysisRepository::class)->findRun($project->id, $leakRun)['summary']['data_state']);
	}

	public function testPipelineCreatesDedupedRecommendationsAndKeepsStatuses(): void
	{
		$owner = $this->user('pipeline');
		$project = $this->demo($owner);
		$period = AnalysisPeriod::fromPreset('last_365', $project->timezone());
		$analysis = $this->make(AnalysisService::class);
		$recommendations = $this->make(RecommendationRepository::class);
		$service = $this->make(RecommendationService::class);

		$first = $analysis->run($project, $period, 'manual', $owner);
		$this->assertSame([], $first['errors']);
		$this->assertTrue($first['recommendations']['created'] > 0);
		$this->assertTrue($first['leak_run_id'] !== null && $first['keyword_run_id'] !== null && $first['audit_run_id'] !== null);

		$all = $recommendations->byFingerprint($project->id);
		$target = array_values($all)[0];
		$service->updateStatus($project, (int) $target['id'], 'accepted', $owner, 'Wdrożymy w poniedziałek');
		$service->updateNote($project, (int) $target['id'], '<script>x</script>Notatka', $owner);

		$second = $analysis->run($project, $period, 'manual', $owner);
		$this->assertSame(0, $second['recommendations']['created'], 'Ponowna analiza nie dubluje rekomendacji.');
		$this->assertSame(count($all), count($recommendations->byFingerprint($project->id)));
		$after = $recommendations->find($project->id, (int) $target['id']);
		$this->assertSame('accepted', $after['status']);
		$this->assertSame('Notatka', $after['note'], 'Notatka bez znaczników HTML.');
		$events = array_column($recommendations->events($project->id, (int) $target['id']), 'event');
		$this->assertSame(['note', 'status', 'created'], $events);

		// Problem znika w analizie tego samego okresu → nieaktualna; wraca → przywrócona.
		$generated = $this->candidates($recommendations, $project->id);
		unset($generated[$target['fingerprint']]);
		$stats = $service->persist($project->id, $generated, $period->range->fromString(), $period->range->toString(), null);
		$this->assertSame(1, $stats['obsolete']);
		$this->assertTrue($recommendations->find($project->id, (int) $target['id'])['is_obsolete']);
		$this->assertSame('accepted', $recommendations->find($project->id, (int) $target['id'])['status'], 'Status zostaje.');

		$analysis->run($project, $period, 'manual', $owner);
		$this->assertFalse($recommendations->find($project->id, (int) $target['id'])['is_obsolete'], 'Problem wrócił – rekomendacja aktualna.');

		// Ręcznie oznaczona jako nieaktualna nie wraca automatycznie.
		$service->markObsolete($project, (int) $target['id'], true, $owner);
		$analysis->run($project, $period, 'manual', $owner);
		$this->assertTrue($recommendations->find($project->id, (int) $target['id'])['manually_obsolete']);

		// Analiza innego okresu nie oznacza rekomendacji z 12 miesięcy jako nieaktualnych.
		$before = count(array_filter($recommendations->byFingerprint($project->id), fn ($r) => $r['is_obsolete']));
		$analysis->run($project, AnalysisPeriod::fromPreset('last_30', $project->timezone()), 'manual', $owner);
		$obsoleteAfter = array_filter($recommendations->byFingerprint($project->id), fn ($r) => $r['is_obsolete'] && $r['date_to'] === $period->range->toString() && $r['date_from'] === $period->range->fromString());
		$this->assertTrue(count($obsoleteAfter) <= $before);

		$this->assertThrows(ValidationFailed::class, fn () => $service->updateStatus($project, (int) $target['id'], 'deleted', $owner));
		$this->assertThrows(ValidationFailed::class, fn () => $service->updateNote($project, (int) $target['id'], str_repeat('a', 2001), $owner));
	}

	public function testRecommendationPriorityIsNotDrivenBySpendAlone(): void
	{
		$owner = $this->user('priority');
		$project = $this->demo($owner);
		$this->make(AnalysisService::class)->run($project, AnalysisPeriod::fromPreset('last_365', $project->timezone()), 'manual', $owner);

		foreach ($this->make(RecommendationRepository::class)->byFingerprint($project->id) as $rec) {
			$this->assertTrue($rec['priority_score'] >= 0 && $rec['priority_score'] <= 100);
			$this->assertTrue(in_array($rec['confidence'], ['high', 'medium', 'low'], true));
			$this->assertTrue($rec['title'] !== '' && $rec['action'] !== '' && $rec['date_from'] !== null);
		}
	}

	public function testLeakStatusesCarryOverBetweenRuns(): void
	{
		$owner = $this->user('leaks');
		$project = $this->demo($owner);
		$period = AnalysisPeriod::fromPreset('last_365', $project->timezone());
		$service = $this->make(BudgetLeakService::class);
		$analyses = $this->make(AnalysisRepository::class);

		$first = $service->run($project, $period, 'manual', $owner);
		$leaks = $analyses->leaks($first);
		$this->assertTrue($leaks !== [], 'Dane demo zawierają kosztowne hasła bez konwersji.');
		$service->updateStatus($project, (int) $leaks[0]['id'], 'dismissed', $owner);

		$second = $service->run($project, $period, 'manual', $owner);
		$carried = array_values(array_filter($analyses->leaks($second), fn ($l) => $l['fingerprint'] === $leaks[0]['fingerprint']));
		$this->assertSame('dismissed', $carried[0]['status']);
		$this->assertSame(count($leaks), count($analyses->leaks($second)));
		$this->assertSame(count($leaks) - 1, count($analyses->leaks($second, ['status' => 'new'])) + count($analyses->leaks($second, ['status' => 'reviewed'])));

		$summary = $analyses->findRun($project->id, $second)['summary'];
		$savings = BudgetLeakService::savings($analyses->leaks($second), $summary);
		$this->assertTrue($savings['total'] <= array_sum($summary['campaign_monthly_spend']), 'Oszczędności nie przekraczają wydatków.');

		$this->assertThrows(ValidationFailed::class, fn () => $service->updateStatus($project, (int) $leaks[0]['id'], 'hacked', $owner));
	}

	public function testClassificationChangesBusinessMetricsButNotGoogleMetrics(): void
	{
		$owner = $this->user('classify');
		$project = $this->organizationWithProject($owner, 'Klasyfikacja')['project'];
		$this->import($project, $owner, 'konwersje-pl-edytor-raportow.csv');
		$range = new \App\PennyPincher\Domain\Metrics\DateRange('2026-01-01', '2026-06-29', 'custom');
		$breakdown = $this->make(ConversionBreakdown::class);
		$actions = $this->make(ConversionActionRepository::class);

		$before = $breakdown->forPeriod($project->id, $range);
		$this->assertTrue($before['actions'] !== [] && (float) $before['account']['google'] > 0);
		$this->assertFalse($before['confirmed_available']);
		$this->assertNull($before['account']['confirmed'], 'Bez danych o potwierdzonych leadach – brak liczby, nie zero ani szacunek.');

		foreach ($actions->forProject($project->id) as $action) {
			$actions->classify($project->id, (int) $action['id'], ConversionClass::Micro, false, $owner);
		}

		$after = $breakdown->forPeriod($project->id, $range);
		$this->assertEqualsWithDelta((float) $before['account']['google'], (float) $after['account']['google'], 0.0001, 'Konwersje Google Ads bez zmian.');
		$this->assertEqualsWithDelta(0.0, (float) $after['account']['goals'], 0.0001);
		$this->assertEqualsWithDelta(0.0, (float) $after['account']['leads'], 0.0001);
		$this->assertTrue($after['manual']);
	}

	public function testModulesAreIsolatedAndRespectRoles(): void
	{
		$alice = $this->user('iso-alice');
		$bob = $this->user('iso-bob');
		$member = $this->user('iso-member');
		$a = $this->demo($alice);
		$aOrg = $a->organizationId;
		$this->addMember($aOrg, $member, Role::Member);
		$b = $this->organizationWithProject($bob, 'Izolacja B')['project'];
		$period = AnalysisPeriod::fromPreset('last_365', $a->timezone());
		$this->make(AnalysisService::class)->run($a, $period, 'manual', $alice);
		$recId = (int) array_values($this->make(RecommendationRepository::class)->byFingerprint($a->id))[0]['id'];
		$leakRun = $this->make(AnalysisRepository::class)->latestRun($a->id, BudgetLeakService::TYPE);
		$leakId = (int) $this->make(AnalysisRepository::class)->leaks((int) $leakRun['id'])[0]['id'];

		// Usługi: identyfikator z innego projektu nie działa.
		$this->assertThrows(ValidationFailed::class, fn () => $this->make(RecommendationService::class)->updateStatus($b, $recId, 'done', $bob));
		$this->assertThrows(ValidationFailed::class, fn () => $this->make(BudgetLeakService::class)->updateStatus($b, $leakId, 'dismissed', $bob));
		$this->assertNull($this->make(AnalysisRepository::class)->findRun($b->id, (int) $leakRun['id']));

		// Kontrolery: osoba spoza organizacji nie widzi stron projektu.
		wp_set_current_user($bob);
		$request = Request::create('/');
		$this->assertThrows(NotFound::class, fn () => $this->make(BudgetLeaksController::class)->index($a->id, $request));
		$this->assertThrows(NotFound::class, fn () => $this->make(RecommendationsController::class)->index($a->id, $request));
		$this->assertThrows(NotFound::class, fn () => $this->make(KeywordOpportunitiesController::class)->index($a->id, $request));
		$this->assertThrows(NotFound::class, fn () => $this->make(RecommendationsController::class)->updateStatus($a->id, $recId, Request::create('/', 'POST', ['status' => 'done'])));

		// Członek: widzi i zmienia statusy, ale nie ustawia limitu DataForSEO ani nie zleca płatnych zapytań.
		wp_set_current_user($member);
		$this->assertSame(200, $this->make(BudgetLeaksController::class)->index($a->id, Request::create('/', 'GET', ['period' => 'last_365']))->getStatusCode());
		$this->assertSame(200, $this->make(RecommendationsController::class)->index($a->id, $request)->getStatusCode());
		$this->assertSame(200, $this->make(KeywordOpportunitiesController::class)->index($a->id, Request::create('/', 'GET', ['tab' => 'dataforseo']))->getStatusCode());
		$this->assertThrows(AccessDenied::class, fn () => $this->make(KeywordOpportunitiesController::class)->updateLimit($a->id, Request::create('/', 'POST', ['limit' => '100'])));
		$this->assertThrows(AccessDenied::class, fn () => $this->make(KeywordOpportunitiesController::class)->requestDataForSeo($a->id, Request::create('/', 'POST', ['consent' => '1'])));
		$this->make(RecommendationsController::class)->updateStatus($a->id, $recId, Request::create('/', 'POST', ['status' => 'to_review', 'back' => 'page=2&evil=https://x.test']));
		$this->assertSame('to_review', $this->make(RecommendationRepository::class)->find($a->id, $recId)['status']);
	}

	public function testRecommendationsPageEscapesContent(): void
	{
		$owner = $this->user('escape');
		$project = $this->demo($owner);
		$this->make(RecommendationService::class)->persist($project->id, [sha1('x') => [
			'source' => 'audit', 'sources' => ['audit'], 'type' => 'test', 'title' => '<img src=x onerror=alert(1)>', 'description' => 'Opis', 'rationale' => '',
			'evidence' => [['<b>klucz</b>', 'wartość']], 'action' => 'Działanie', 'campaign_id' => null, 'ad_group_id' => null, 'priority_score' => 50,
			'confidence' => 'medium', 'impact_micros' => null, 'impact_label' => null,
		]], '2026-01-01', '2026-01-31', null);

		wp_set_current_user($owner);
		$html = (string) $this->make(RecommendationsController::class)->index($project->id, Request::create('/'))->getContent();
		$this->assertFalse(str_contains($html, '<img src=x'));
		$this->assertTrue(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;'));
	}

	// --- Pomocnicze ---------------------------------------------------------------------------

	private function demo(int $owner): Project
	{
		$id = $this->make(DemoProjectService::class)->create($owner);

		return $this->make(ProjectRepository::class)->findForUser($owner, $id);
	}

	private function candidates(RecommendationRepository $repository, int $projectId): array
	{
		$out = [];

		foreach ($repository->byFingerprint($projectId) as $fingerprint => $row) {
			$out[$fingerprint] = [
				'source' => $row['source'], 'sources' => $row['sources'], 'type' => $row['type'], 'title' => $row['title'], 'description' => $row['description'],
				'rationale' => $row['rationale'], 'evidence' => $row['evidence'], 'action' => $row['action'], 'campaign_id' => $row['campaign_id'],
				'ad_group_id' => $row['ad_group_id'], 'priority_score' => (int) $row['priority_score'], 'confidence' => $row['confidence'],
				'impact_micros' => $row['impact_micros'], 'impact_label' => $row['impact_label'],
			];
		}

		return $out;
	}

	private function seedApiCampaign(Project $project, DateTimeImmutable $coverFrom, DateTimeImmutable $coverTo, DateTimeImmutable $activeFrom, DateTimeImmutable $activeTo): void
	{
		$now = $this->db->now();
		$campaignId = $this->db->insert('campaigns', [
			'project_id' => $project->id, 'natural_key' => sha1('seed' . $project->id), 'external_id' => '555', 'name' => 'Szukaj | Wyłączona',
			'status' => 'paused', 'channel_type' => 'search', 'created_at' => $now, 'updated_at' => $now,
		]);
		$rows = [];

		for ($day = $activeFrom; $day <= $activeTo; $day = $day->modify('+1 day')) {
			$rows[] = [
				'project_id' => $project->id, 'campaign_id' => $campaignId, 'granularity' => 'day', 'date_from' => $day->format('Y-m-d'), 'date_to' => $day->format('Y-m-d'),
				'source' => 'api', 'impressions' => 500, 'clicks' => 20, 'cost_micros' => 40_000_000, 'conversions' => 1, 'all_conversions' => 1, 'currency' => 'PLN',
				'created_at' => $now, 'updated_at' => $now,
			];
		}

		$this->db->insertMany('campaign_metrics', $rows);
		$this->db->insert('data_coverage', ['project_id' => $project->id, 'source' => 'api', 'dataset' => 'campaigns', 'date_from' => $coverFrom->format('Y-m-d'), 'date_to' => $coverTo->format('Y-m-d'), 'created_at' => $now]);
	}
}
