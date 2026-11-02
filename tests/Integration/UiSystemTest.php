<?php

namespace Tests\Integration;

use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;
use Tests\IntegrationTestCase;

/**
 * Wspólne elementy interfejsu: okruszki i nagłówki nawigacji 2.0, oznaczenie źródła danych.
 */
class UiSystemTest extends IntegrationTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::NAVIGATION);
		parent::tearDown();
	}

	private function page(string $url): string
	{
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$response = Application::getInstance()->make(Kernel::class)->handle(Request::create($url, 'GET'));
		$this->assertSame(200, $response->getStatusCode(), $url);

		return (string) $response->getContent();
	}

	private function breadcrumbs(string $body): ?string
	{
		$start = strpos($body, 'aria-label="Ścieżka"');

		return $start === false ? null : strip_tags(substr($body, $start, strpos($body, '</nav>', $start) - $start));
	}

	private function heading(string $body): string
	{
		preg_match('~<h1[^>]*>(.*?)</h1>~s', $body, $m);

		return trim(html_entity_decode(strip_tags($m[1] ?? '')));
	}

	private function setUpProject(): array
	{
		$owner = $this->user('ui_owner');
		$setup = $this->organizationWithProject($owner, 'UI');
		wp_set_current_user($owner);
		$this->make(AccessService::class)->rememberProject($setup['project']);
		$this->import($setup['project'], $owner, 'campaigns-pl-bez-znakow.csv');

		return [$owner, $setup['project']];
	}

	public function testBreadcrumbsAndHeadingsFollowNewNavigation(): void
	{
		[, $project] = $this->setUpProject();
		add_filter('pennypincher/feature/' . Feature::NAVIGATION, '__return_true');

		$landing = $this->page(AppUrl::project($project->id, 'budget-leaks'));
		$this->assertSame('Wycieki budżetu', $this->heading($landing));
		$trail = (string) $this->breadcrumbs($landing);
		foreach ([$project->name, 'Optymalizacja', 'Wycieki budżetu'] as $crumb) {
			$this->assertContains($crumb, $trail);
		}

		$overview = $this->page(AppUrl::project($project->id));
		$this->assertSame('Centrum dowodzenia', $this->heading($overview));

		// Szczegół: ostatni okruszek to tytuł strony, podwidok jest linkiem powrotu.
		$campaignId = (int) $this->db->scalar('SELECT id FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? ORDER BY id LIMIT 1', [$project->id]);
		$detail = $this->page(AppUrl::project($project->id, 'campaigns/' . $campaignId));
		$this->assertContains('href="' . esc_url(AppUrl::project($project->id, 'campaigns')) . '" class="truncate', $detail);
		$this->assertContains('Lista kampanii', (string) $this->breadcrumbs($detail));
		$this->assertTrue(str_ends_with(trim((string) $this->breadcrumbs($detail)), $this->heading($detail)), 'Ostatni okruszek = nagłówek strony.');
	}

	public function testLegacyHeaderWithoutFlag(): void
	{
		[, $project] = $this->setUpProject();
		$body = $this->page(AppUrl::project($project->id, 'budget-leaks'));

		$this->assertNull($this->breadcrumbs($body));
		$this->assertSame('Budget Leaks', $this->heading($body));
		$this->assertContains($project->organizationName . ' / ' . $project->name, $body);
	}

	public function testDataSourceIsLabelled(): void
	{
		[, $project] = $this->setUpProject();
		$body = $this->page(AppUrl::project($project->id, 'campaigns', ['range' => 'all']));

		$this->assertContains('title="Źródło danych">Import</span>', $body);
	}
}
