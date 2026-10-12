<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Keywords\KeywordStatus;
use App\PennyPincher\Domain\Planner\BudgetAllocator;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Http\Controllers\CampaignPlannerController;
use App\PennyPincher\Http\Controllers\KeywordOpportunitiesController;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\CampaignPlanRepository;
use App\PennyPincher\Repositories\KeywordDecisionRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\Analysis\KeywordDecisionService;
use App\PennyPincher\Services\Analysis\KeywordOpportunityService;
use App\PennyPincher\Services\DemoProjectService;
use App\PennyPincher\Services\Planner\BudgetPlannerService;
use App\PennyPincher\Services\Planner\CampaignPlanService;
use Illuminate\Http\Request;
use Tests\IntegrationTestCase;

/**
 * Keyword Opportunities 2.0 (wersjonowany wynik, klastry, statusy fraz) i Campaign / Budget Planner na projekcie demo.
 * Nic nie jest wysyłane do Google Ads ani DataForSEO.
 */
class CampaignPlannerTest extends IntegrationTestCase
{
	public function testOpportunitiesAreVersionedClusteredAndHistoryIsKept(): void
	{
		[$owner, $project] = $this->demo();
		$analyses = $this->make(AnalysisRepository::class);
		$first = $this->runKeywords($project, $owner);
		$second = $this->runKeywords($project, $owner);

		$this->assertTrue($first !== $second);
		$this->assertTrue($analyses->findRun($project->id, $first) !== null, 'Wcześniejsza analiza nie jest nadpisywana.');
		$rows = $analyses->opportunities($second);
		$this->assertTrue($rows !== []);

		foreach ($rows as $row) {
			$this->assertSame('2.0', $row['score_version']);
			$this->assertTrue($row['confidence_score'] !== null && $row['cluster_key'] !== null && $row['normalized'] !== '');
			$this->assertTrue(is_array($row['explanation']) && array_key_exists('missing', $row['explanation']), 'Wynik ma wyjaśnienie.');
		}

		$normalized = array_column($rows, 'normalized');
		$this->assertSame(count($normalized), count(array_unique($normalized)), 'Bez duplikatów fraz w analizie.');

		$clusters = $analyses->clusters($second, $project->id);
		$this->assertTrue($clusters !== []);
		$members = array_merge(...array_map(fn ($c) => $c['members'], $clusters));
		$this->assertSame(count($members), count(array_unique($members)), 'Fraza należy do jednego klastra.');
	}

	public function testKeywordStatusesSurviveReanalysisAndAreDeduplicated(): void
	{
		[$owner, $project] = $this->demo();
		$runId = $this->runKeywords($project, $owner);
		$rows = $this->make(AnalysisRepository::class)->opportunities($runId);
		$decisions = $this->make(KeywordDecisionService::class);
		$repo = $this->make(KeywordDecisionRepository::class);
		[$a, $b] = [$rows[0]['phrase'], $rows[1]['phrase']];

		$this->assertSame(2, $decisions->setStatus($project, [$a, $b, mb_strtoupper($a)], KeywordStatus::ACCEPTED, $owner));
		$this->assertSame(0, $decisions->setStatus($project, [$a], KeywordStatus::ACCEPTED, $owner), 'Ten sam status – bez nowej decyzji.');
		$decisions->setStatus($project, [$b], KeywordStatus::REJECTED, $owner, '<b>nie nasza usługa</b>');
		$this->assertThrows(ValidationFailed::class, fn () => $decisions->setStatus($project, [$a], KeywordStatus::USED, $owner));

		$this->runKeywords($project, $owner);
		$map = $repo->forPhrases($project->id, [$rows[0]['normalized'], $rows[1]['normalized']]);
		$this->assertSame(KeywordStatus::ACCEPTED, $map[$rows[0]['normalized']]['status'], 'Status przetrwał ponowną analizę.');
		$this->assertSame(KeywordStatus::REJECTED, $map[$rows[1]['normalized']]['status']);
		$this->assertSame('nie nasza usługa', $map[$rows[1]['normalized']]['note']);
		$this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->table('keyword_decisions') . ' WHERE project_id = ?', [$project->id]));
		$events = array_column($repo->events($project->id, (int) $map[$rows[1]['normalized']]['id']), 'event');
		$this->assertSame(['status', 'created'], $events, 'Historia zmian statusu.');

		// Przywrócenie odrzuconej frazy.
		$decisions->setStatus($project, [$b], KeywordStatus::NEW, $owner);
		$this->assertSame(KeywordStatus::NEW, $repo->find($project->id, $rows[1]['normalized'])['status']);

		// Fraza w planie, która pojawi się jako aktywne słowo kluczowe, dostaje status „Wykorzystana”.
		$decisions->setStatus($project, [$a], KeywordStatus::IN_PLAN, $owner);
		$this->make(KeywordOpportunityService::class)->markUsed($project->id, [['text' => $rows[0]['phrase'], 'status' => 'enabled']]);
		$this->assertSame(KeywordStatus::USED, $repo->find($project->id, $rows[0]['normalized'])['status']);
	}

	public function testClusterStatusAndPlanFromOpportunities(): void
	{
		[$owner, $project] = $this->demo();
		$runId = $this->runKeywords($project, $owner);
		$analyses = $this->make(AnalysisRepository::class);
		$cluster = array_values(array_filter($analyses->clusters($runId, $project->id), fn ($c) => $c['phrase_count'] >= 1))[0];
		$planner = $this->make(CampaignPlanService::class);

		$changed = $this->make(KeywordDecisionService::class)->setClusterStatus($project, $runId, $cluster['cluster_key'], KeywordStatus::ACCEPTED, $owner);
		$this->assertSame((int) $cluster['phrase_count'], $changed);

		$result = $planner->addFromOpportunities($project, null, $runId, [], [$cluster['cluster_key']], $owner);
		$this->assertSame((int) $cluster['phrase_count'], $result['added']);
		$tree = $this->make(CampaignPlanRepository::class)->tree($project->id, $result['plan_id']);
		$keywords = $this->keywords($tree);
		$this->assertCount((int) $cluster['phrase_count'], $keywords);

		foreach ($keywords as $keyword) {
			$this->assertTrue($keyword['match_type'] !== 'broad', 'Nigdy automatycznie przybliżone.');
			$this->assertTrue($keyword['source'] !== '');
			$this->assertSame(KeywordStatus::IN_PLAN, $this->make(KeywordDecisionRepository::class)->find($project->id, $keyword['normalized'])['status']);
		}

		$again = $planner->addFromOpportunities($project, $result['plan_id'], $runId, [], [$cluster['cluster_key']], $owner);
		$this->assertSame(0, $again['added'], 'Ponowne dodanie nie tworzy duplikatów.');
		$this->assertSame((int) $cluster['phrase_count'], $again['skipped']);

		$this->assertThrows(ValidationFailed::class, fn () => $planner->addFromOpportunities($project, $result['plan_id'], $runId, [], [str_repeat('a', 40)], $owner));
	}

	public function testPlanStructureEditingCopyAndApprovalLock(): void
	{
		[$owner, $project] = $this->demo();
		$planner = $this->make(CampaignPlanService::class);
		$plans = $this->make(CampaignPlanRepository::class);

		$this->assertThrows(ValidationFailed::class, fn () => $planner->create($project, ['name' => ''], $owner));
		$planId = $planner->create($project, ['name' => 'Plan <i>Q4</i>', 'goal' => '', 'language' => 'pl', 'monthly_budget' => '4 000,50'], $owner);
		$plan = $plans->find($project->id, $planId);
		$this->assertSame('Plan Q4', $plan['name']);
		$this->assertSame('draft', $plan['status']);
		$this->assertSame($owner, (int) $plan['created_by']);
		$this->assertSame(4_000_500_000, (int) $plan['monthly_budget_micros']);

		$campaignId = $planner->addCampaign($project, $planId, 'Strony www');
		$groupA = $planner->addGroup($project, $planId, $campaignId, 'Cena strony');
		$groupB = $planner->addGroup($project, $planId, $campaignId, 'Sklepy');
		$added = $planner->addKeywords($project, $planId, $groupA, ['strona www cena', 'Strona WWW cena', '[koszt strony]', ''], null);
		$this->assertSame(2, $added['added'], 'Duplikaty i puste linie pominięte.');
		$this->assertThrows(ValidationFailed::class, fn () => $planner->addKeywords($project, $planId, $groupA, [str_repeat('słowo ', 12)]));
		$this->assertThrows(ValidationFailed::class, fn () => $planner->addKeywords($project, $planId, $groupA, ['x'], 'broad_modified'));

		$keywords = $this->keywords($plans->tree($project->id, $planId));
		$keyword = $keywords[0];
		$planner->moveKeyword($project, $planId, (int) $keyword['id'], $groupB);
		$planner->setMatchType($project, $planId, (int) $keyword['id'], 'broad');
		$moved = $this->keywords($plans->tree($project->id, $planId));
		$movedRow = array_values(array_filter($moved, fn ($k) => (int) $k['id'] === (int) $keyword['id']))[0];
		$this->assertSame($groupB, (int) $movedRow['plan_ad_group_id']);
		$this->assertSame('broad', $movedRow['match_type']);

		$planner->updateGroup($project, $planId, $groupA, ['name' => 'Cena strony www']);
		$copyId = $planner->copy($project, $planId, $owner);
		$copy = $plans->find($project->id, $copyId);
		$this->assertSame('draft', $copy['status']);
		$this->assertCount(2, $this->keywords($plans->tree($project->id, $copyId)), 'Kopia zawiera słowa kluczowe.');

		$planner->setStatus($project, $planId, 'approved', $owner);
		$this->assertThrows(ValidationFailed::class, fn () => $planner->addKeywords($project, $planId, $groupA, ['nowa fraza']));
		$this->assertThrows(ValidationFailed::class, fn () => $planner->setStatus($project, $planId, 'published', $owner));
		$planner->setStatus($project, $planId, 'draft', $owner);
		$planner->removeKeyword($project, $planId, (int) $keyword['id']);
		$this->assertCount(1, $this->keywords($plans->tree($project->id, $planId)));
		$this->assertTrue(count($plans->events($project->id, $planId)) >= 4, 'Historia planu.');

		// Nic nie trafia do Google Ads: plan nie ma żadnych identyfikatorów zasobów konta.
		$this->assertFalse(array_key_exists('external_id', $plans->tree($project->id, $planId)['campaigns'][$campaignId]));
	}

	public function testNegativesConflictsAndAds(): void
	{
		[$owner, $project] = $this->demo();
		$planner = $this->make(CampaignPlanService::class);
		$plans = $this->make(CampaignPlanRepository::class);
		$planId = $planner->create($project, ['name' => 'Wykluczenia', 'language' => 'pl'], $owner);
		$campaignId = $planner->addCampaign($project, $planId, 'Meble');
		$groupId = $planner->addGroup($project, $planId, $campaignId, 'Dębowe');
		$planner->addKeywords($project, $planId, $groupId, ['blat dębowy', 'stół dębowy'], 'phrase');

		$result = $planner->addNegatives($project, $planId, 'plan', null, ['dębowy', 'darmowe', 'darmowe'], 'broad');
		$this->assertSame(2, $result['added']);
		$conflicts = $planner->conflicts($plans->tree($project->id, $planId));
		$this->assertCount(1, $conflicts);
		$this->assertCount(2, $conflicts[0]['keywords']);
		$again = $planner->addNegatives($project, $planId, 'plan', null, ['darmowe'], 'broad');
		$this->assertSame(0, $again['added'], 'Bez duplikatów wykluczeń.');
		$this->assertThrows(ValidationFailed::class, fn () => $planner->addNegatives($project, $planId, 'ad_group', 999999, ['x'], 'phrase'));

		$planner->suggestAd($project, $planId, $groupId);
		$ad = array_values($plans->tree($project->id, $planId)['campaigns'][$campaignId]['groups'][$groupId]['ads'])[0];
		$this->assertTrue(count($ad['headlines']) >= 1, 'Propozycja reklamy zapisana jako szkic.');
		$this->assertSame('Dębowe', $ad['headlines'][0]);
		$this->assertThrows(ValidationFailed::class, fn () => $planner->suggestAd($project, $planId, $groupId));
		$bad = fn () => $planner->saveAd($project, $planId, $groupId, ['headlines' => [str_repeat('a', 31)], 'descriptions' => ['Opis'], 'final_url' => 'ftp://x']);
		$this->assertThrows(ValidationFailed::class, $bad);
		$saved = $planner->saveAd($project, $planId, $groupId, ['headlines' => ['Blaty dębowe', 'Stoły z drewna', 'Poproś o wycenę'], 'descriptions' => ['Meble z litego drewna na wymiar.', 'Wycena w 24 h.'], 'final_url' => 'https://example.com/meble', 'cta' => 'Poproś o wycenę']);
		$this->assertTrue(is_array($saved));
	}

	public function testBudgetPlannerAllocatesAndWarnsAboutOverspend(): void
	{
		[$owner, $project] = $this->demo();
		$planner = $this->make(CampaignPlanService::class);
		$budgets = $this->make(BudgetPlannerService::class);
		$plans = $this->make(CampaignPlanRepository::class);
		$planId = $planner->create($project, ['name' => 'Budżet', 'language' => 'pl'], $owner);
		$a = $planner->addCampaign($project, $planId, 'A');
		$b = $planner->addCampaign($project, $planId, 'B');
		$planner->addKeywords($project, $planId, $planner->addGroup($project, $planId, $a, 'A1'), ['strona www cena']);
		$planner->addKeywords($project, $planId, $planner->addGroup($project, $planId, $b, 'B1'), ['sklep internetowy']);

		$budgets->updateBudget($project, $planId, ['monthly_budget' => '3000', 'scenario' => 'cautious'], $owner);
		$this->assertThrows(ValidationFailed::class, fn () => $budgets->updateBudget($project, $planId, ['monthly_budget' => '-5', 'scenario' => 'balanced'], $owner));
		$this->assertThrows(ValidationFailed::class, fn () => $budgets->updateBudget($project, $planId, ['monthly_budget' => '1000', 'scenario' => 'yolo'], $owner));

		$measurement = $planner->measurement($project);
		$applied = $budgets->applyProposal($project, $planId, $owner, $measurement);
		$this->assertSame(2, $applied);
		$plan = $plans->find($project->id, $planId);
		$tree = $plans->tree($project->id, $planId);
		$this->assertSame(3000 * 1_000_000, array_sum(array_map(fn ($c) => (int) $c['budget_micros'], $tree['campaigns'])));

		$budgets->setCampaignBudget($project, $planId, $a, '2900');
		$summary = $budgets->summary($project, $plans->find($project->id, $planId), $plans->tree($project->id, $planId), $measurement);
		$this->assertTrue($summary['allocated'] > $summary['budget'], 'Ręczna korekta może przekroczyć budżet…');
		$this->assertTrue($summary['warnings'] !== [], '…ale wtedy pokazujemy ostrzeżenie.');

		$this->assertCount(2, $summary['rows']);

		foreach ($summary['rows'] as $row) {
			if ($row['forecast']['lead_message'] !== null) {
				$this->assertSame(BudgetAllocator::NO_LEAD_FORECAST, $row['forecast']['lead_message']);
			}
		}

		$this->assertSame('cautious', $plan['scenario']);
	}

	public function testPlannerIsolationRolesAndExport(): void
	{
		[$owner, $project] = $this->demo();
		$bob = $this->user('planner-bob');
		$member = $this->user('planner-member');
		$this->addMember($project->organizationId, $member, Role::Member);
		$other = $this->organizationWithProject($bob, 'Inna firma')['project'];
		$planner = $this->make(CampaignPlanService::class);
		$planId = $planner->create($project, ['name' => 'Tajny plan', 'language' => 'pl'], $owner);
		$campaignId = $planner->addCampaign($project, $planId, 'Kampania');

		// Usługi: plan z innego projektu nie istnieje.
		$this->assertThrows(ValidationFailed::class, fn () => $planner->addCampaign($other, $planId, 'X'));
		$this->assertThrows(ValidationFailed::class, fn () => $planner->addGroup($other, $planId, $campaignId, 'X'));
		$this->assertNull($this->make(CampaignPlanRepository::class)->find($other->id, $planId));

		// Kontrolery: osoba spoza organizacji nie widzi planu ani eksportu.
		wp_set_current_user($bob);
		$controller = $this->make(CampaignPlannerController::class);
		$this->assertThrows(NotFound::class, fn () => $controller->show($project->id, $planId, Request::create('/')));
		$this->assertThrows(NotFound::class, fn () => $controller->export($project->id, $planId, 'csv'));
		$this->assertThrows(NotFound::class, fn () => $controller->export($other->id, $planId, 'csv'));

		// Członek: edytuje szkic, ale nie zatwierdza i nie ustala budżetu; eksport działa.
		wp_set_current_user($member);
		$this->assertSame(200, $controller->show($project->id, $planId, Request::create('/', 'GET', ['tab' => 'budget']))->getStatusCode());
		$csv = $controller->export($project->id, $planId, 'csv');
		$this->assertSame(200, $csv->getStatusCode());
		$this->assertContains('text/csv', (string) $csv->headers->get('Content-Type'));
		$this->assertContains('Tajny plan', (string) $csv->getContent());
		$html = $controller->export($project->id, $planId, 'html');
		$this->assertContains('Nie został utworzony na koncie Google Ads', (string) $html->getContent());
		$controller->addCampaign($project->id, $planId, Request::create('/', 'POST', ['name' => 'Od członka']));
		$this->assertCount(2, $this->make(CampaignPlanRepository::class)->tree($project->id, $planId)['campaigns']);
		$this->assertThrows(AccessDenied::class, fn () => $controller->status($project->id, $planId, Request::create('/', 'POST', ['status' => 'approved'])));
		$this->assertThrows(AccessDenied::class, fn () => $controller->updateBudget($project->id, $planId, Request::create('/', 'POST', ['monthly_budget' => '100'])));
		$this->assertThrows(AccessDenied::class, fn () => $this->make(KeywordOpportunitiesController::class)->updateSeeds($project->id, Request::create('/', 'POST', ['new_seeds' => 'x'])));
		$controller->status($project->id, $planId, Request::create('/', 'POST', ['status' => 'review']));
		$this->assertSame('review', $this->make(CampaignPlanRepository::class)->find($project->id, $planId)['status']);

		wp_set_current_user($owner);
		$controller->status($project->id, $planId, Request::create('/', 'POST', ['status' => 'approved']));
		$this->assertSame('approved', $this->make(CampaignPlanRepository::class)->find($project->id, $planId)['status']);
	}

	// --- Pomocnicze ---------------------------------------------------------------------------

	/**
	 * @return array{0: int, 1: Project}
	 */
	private function demo(): array
	{
		$owner = $this->user('planner');
		$id = $this->make(DemoProjectService::class)->create($owner);

		return [$owner, $this->make(ProjectRepository::class)->findForUser($owner, $id)];
	}

	private function runKeywords(Project $project, int $userId): int
	{
		return $this->make(KeywordOpportunityService::class)->run($project, AnalysisPeriod::fromPreset('last_365', $project->timezone()), 'manual', $userId);
	}

	private function keywords(array $tree): array
	{
		$out = [];

		foreach ($tree['campaigns'] as $campaign) {
			foreach ($campaign['groups'] as $group) {
				array_push($out, ...$group['keywords']);
			}
		}

		return $out;
	}
}
