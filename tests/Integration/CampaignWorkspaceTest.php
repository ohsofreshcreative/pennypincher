<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Repositories\RecommendationRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Analysis\AnalysisService;
use App\PennyPincher\Services\DemoProjectService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;
use Tests\IntegrationTestCase;

/**
 * Sekcja Kampanie (pp2_campaigns): zakładki kampanii w kontekście, parytet danych i filtrów, izolacja projektów.
 */
class CampaignWorkspaceTest extends IntegrationTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::CAMPAIGNS);
		parent::tearDown();
	}

	private function get(string $url, bool $flag): \Symfony\Component\HttpFoundation\Response
	{
		remove_all_filters('pennypincher/feature/' . Feature::CAMPAIGNS);
		if ($flag) {
			add_filter('pennypincher/feature/' . Feature::CAMPAIGNS, '__return_true');
		}

		$_SERVER['REQUEST_METHOD'] = 'GET';

		return Application::getInstance()->make(Kernel::class)->handle(Request::create($url, 'GET'));
	}

	private function body(string $url, bool $flag): string
	{
		$response = $this->get($url, $flag);
		$this->assertSame(200, $response->getStatusCode(), $url);

		return (string) $response->getContent();
	}

	private function between(string $body, string $start, string $end): string
	{
		$from = strpos($body, $start);
		$this->assertTrue($from !== false, "Brak fragmentu {$start}.");

		return substr($body, $from, strpos($body, $end, $from) - $from);
	}

	/**
	 * Projekt demonstracyjny z przeanalizowanym ostatnim rokiem (wycieki i rekomendacje przypisane do kampanii).
	 */
	private function analysedProject(): array
	{
		$owner = $this->user('cw_owner');
		$id = $this->make(DemoProjectService::class)->create($owner);
		$project = $this->make(ProjectRepository::class)->findForUser($owner, $id);
		wp_set_current_user($owner);
		$this->make(AccessService::class)->rememberProject($project);
		$result = $this->make(AnalysisService::class)->run($project, AnalysisPeriod::fromPreset('last_365', $project->timezone()), 'manual', $owner);
		$this->assertSame([], $result['errors']);

		return [$project, $result];
	}

	public function testCampaignTabsKeepCampaignContext(): void
	{
		[$project] = $this->analysedProject();
		$campaign = (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? ORDER BY id LIMIT 1', [$project->id]);

		$tabs = [
			'results' => AppUrl::project($project->id, 'campaigns/' . $campaign),
			'search-terms' => AppUrl::project($project->id, 'search-terms', ['campaign' => $campaign]),
			'optimization' => AppUrl::project($project->id, 'campaigns/' . $campaign . '/optimization'),
			'planning' => AppUrl::project($project->id, 'campaigns/' . $campaign . '/planning'),
		];

		foreach ($tabs as $active => $url) {
			$nav = $this->between($this->body($url, true), 'aria-label="Kampania:', '</nav>');

			foreach ($tabs as $tabUrl) {
				$this->assertContains('href="' . esc_url($tabUrl) . '"', $nav, "Zakładki na {$url}.");
			}

			$this->assertSame(1, substr_count($nav, 'aria-current="page"'));
			$this->assertTrue((bool) preg_match('~href="' . preg_quote(esc_url($url), '~') . '"[^>]*aria-current="page"~', $nav), "Aktywna zakładka na {$url}.");
		}

		// Filtr kampanii w hasłach zostaje wybrany (kontekst nie ginie).
		$this->assertContains('<option value="' . $campaign . '" selected', $this->body($tabs['search-terms'], true));
	}

	public function testDataIsTheSameWithAndWithoutFlag(): void
	{
		[$project] = $this->analysedProject();
		$campaign = (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? ORDER BY id LIMIT 1', [$project->id]);
		$range = ['range' => 'all'];

		$show = AppUrl::project($project->id, 'campaigns/' . $campaign, $range);
		$this->assertSame(
			$this->between($this->body($show, false), 'aria-label="Wyniki kampanii"', '</section>'),
			$this->between($this->body($show, true), 'aria-label="Wyniki kampanii"', '</section>'),
		);

		foreach ([AppUrl::project($project->id, 'search-terms', $range + ['campaign' => $campaign, 'sort' => 'clicks']), AppUrl::project($project->id, 'campaigns', $range + ['sort' => 'ctr', 'dir' => 'asc'])] as $url) {
			$this->assertSame(
				$this->between($this->body($url, false), '<table class="pp-table">', '</table>'),
				$this->between($this->body($url, true), '<table class="pp-table">', '</table>'),
				$url,
			);
		}

		// Bez flagi: stary układ bez zakładek.
		$this->assertFalse(str_contains($this->body($show, false), 'aria-label="Kampania:'));
	}

	public function testOptimizationTabListsOnlyThisCampaign(): void
	{
		[$project, $result] = $this->analysedProject();
		$analyses = $this->make(AnalysisRepository::class);
		$checked = 0;

		foreach ($this->db->column('SELECT id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ?', [$project->id]) as $campaign) {
			$campaign = (int) $campaign;
			$body = $this->body(AppUrl::project($project->id, 'campaigns/' . $campaign . '/optimization'), true);
			$leaks = array_filter($analyses->leaks((int) $result['leak_run_id'], ['campaign_id' => $campaign]), fn ($l) => $l['status'] !== 'dismissed');
			$recs = $this->make(RecommendationRepository::class)->paginate($project->id, ['campaign_id' => $campaign], 1, 10);

			$this->assertSame(count($leaks), substr_count($body, '#leak-'), "Wycieki kampanii {$campaign}.");
			$this->assertSame(count($recs->items), substr_count($body, '#rec-'), "Rekomendacje kampanii {$campaign}.");
			$checked += count($leaks) + count($recs->items);
		}

		$this->assertTrue($checked > 0, 'Analiza projektu demo powinna dać wycieki lub rekomendacje kampanii.');
	}

	public function testForeignCampaignTabsAreNotFound(): void
	{
		[$project] = $this->analysedProject();
		$foreign = (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? ORDER BY id LIMIT 1', [$project->id]);

		$other = $this->user('cw_other');
		$setup = $this->organizationWithProject($other, 'Inny');
		wp_set_current_user($other);

		foreach (['campaigns/' . $foreign, 'campaigns/' . $foreign . '/optimization', 'campaigns/' . $foreign . '/planning'] as $path) {
			$this->assertSame(404, $this->get(AppUrl::project($setup['project']->id, $path), true)->getStatusCode(), $path);
			$this->assertSame(404, $this->get(AppUrl::project($project->id, $path), true)->getStatusCode(), "Cudzy projekt: {$path}");
		}
	}
}
