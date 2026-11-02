<?php

namespace Tests\Integration;

use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;
use App\PennyPincher\Support\Navigation;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Roots\Acorn\Application;
use Symfony\Component\HttpFoundation\Response;
use Tests\IntegrationTestCase;

/**
 * Nawigacja 2.0: aliasy sekcji głównych, stare skróty i dostępność każdego modułu w nowym i starym menu.
 */
class NavigationTest extends IntegrationTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::NAVIGATION);
		parent::tearDown();
	}

	private function get(string $url): Response
	{
		$_SERVER['REQUEST_METHOD'] = 'GET';

		return Application::getInstance()->make(Kernel::class)->handle(Request::create($url, 'GET'));
	}

	/**
	 * Fragment HTML nowej nawigacji (null, gdy strona ma stare menu).
	 */
	private function navigation(string $body): ?string
	{
		$start = strpos($body, 'aria-label="Sekcje aplikacji"');

		return $start === false ? null : substr($body, $start, strpos($body, '</nav>', $start) - $start);
	}

	private function isCurrent(string $nav, string $url): bool
	{
		return (bool) preg_match('~href="' . preg_quote(esc_url($url), '~') . '"[^>]*aria-current="page"~', $nav);
	}

	private function owner(): array
	{
		$owner = $this->user('nav_owner');
		$setup = $this->organizationWithProject($owner, 'Nawigacja');
		wp_set_current_user($owner);
		$this->make(AccessService::class)->rememberProject($setup['project']);

		return $setup;
	}

	public function testGroupAliasesAndShortcutsKeepQueryParameters(): void
	{
		$id = $this->owner()['project']->id;
		$query = ['period' => 'last_90'];

		$cases = [
			AppUrl::project($id, 'optimization', $query) => AppUrl::project($id, 'audits', $query),
			AppUrl::project($id, 'command-center', $query) => AppUrl::project($id, '', $query),
			AppUrl::project($id, 'administration') => AppUrl::project($id, 'settings'),
			AppUrl::to('optimization', $query) => AppUrl::project($id, 'audits', $query),
			AppUrl::to('command-center') => AppUrl::project($id),
			AppUrl::to('administration') => AppUrl::project($id, 'settings'),
			// Stare skróty bez zmian.
			AppUrl::to('budget-leaks', $query) => AppUrl::project($id, 'budget-leaks', $query),
			AppUrl::to('conversion-management') => AppUrl::project($id, 'conversion-management'),
		];

		foreach ($cases as $from => $to) {
			$response = $this->get($from);
			$this->assertSame(302, $response->getStatusCode(), $from);
			$this->assertSame($to, (string) $response->headers->get('Location'), $from);
		}

		$this->assertSame(302, $this->get(AppUrl::to('switch', ['project' => $id, 'section' => 'keywords']))->getStatusCode());
	}

	public function testAliasOfForeignProjectIsNotFound(): void
	{
		$foreign = $this->organizationWithProject($this->user('nav_other'), 'Obcy')['project'];
		$this->owner();

		$this->assertSame(404, $this->get(AppUrl::project($foreign->id, 'optimization'))->getStatusCode());
		$this->assertSame(404, $this->get(AppUrl::project($foreign->id, 'command-center'))->getStatusCode());
	}

	public function testNewNavigationReachesEveryModule(): void
	{
		$setup = $this->owner();
		$id = $setup['project']->id;
		add_filter('pennypincher/feature/' . Feature::NAVIGATION, '__return_true');

		foreach (Navigation::GROUPS as $group => $definition) {
			foreach ($definition['items'] as $key => $item) {
				$url = ! empty($item['global']) ? AppUrl::to($item['path']) : AppUrl::project($id, $item['path']);
				$response = $this->get($url);
				$nav = $this->navigation((string) $response->getContent());

				$this->assertSame(200, $response->getStatusCode(), $url);
				$this->assertNotNull($nav, "Brak nowej nawigacji na {$url}.");
				$this->assertSame(1, substr_count($nav, 'aria-current="page"'), "Jedna aktywna pozycja na {$url}.");
				$this->assertTrue($this->isCurrent($nav, $url), "Aktywna pozycja na {$url} wskazuje inny adres.");

				if (empty($item['global'])) {
					// Wszystkie sekcje główne są osiągalne z każdego ekranu projektu, a podwidoki aktywnej sekcji są rozwinięte.
					foreach (Navigation::GROUPS as $other) {
						$this->assertContains($other['label'], $nav, $url);
					}

					// Sekcja z jednym podwidokiem (Centrum dowodzenia) jest zwykłym linkiem bez listy podwidoków.
					foreach (count($definition['items']) > 1 ? $definition['items'] : [] as $sibling) {
						$this->assertContains($sibling['label'], $nav, $url);
					}
				}
			}
		}

		// Ekran spoza menu (członkowie organizacji) zostaje osiągalny i podświetla Projekty.
		$members = $this->get(AppUrl::to('organizations/' . $setup['organization'] . '/members'));
		$this->assertSame(200, $members->getStatusCode());
		$this->assertTrue($this->isCurrent((string) $this->navigation((string) $members->getContent()), AppUrl::to('projects')));
	}

	public function testLegacyNavigationStaysWhenFlagIsOff(): void
	{
		$id = $this->owner()['project']->id;
		$body = (string) $this->get(AppUrl::project($id))->getContent();

		$this->assertNull($this->navigation($body));

		foreach (['Przegląd', 'Wyszukiwane hasła', 'Budget Leaks', 'Keyword Opportunities', 'Campaign Planner', 'Action Plan', 'Change Center', 'Google Ads Management', 'Conversion Management', 'Importy', 'Projekty', 'Ustawienia'] as $label) {
			$this->assertContains($label, $body);
		}

		foreach (Navigation::SECTIONS as $section) {
			$this->assertContains('href="' . esc_url(AppUrl::project($id, $section)) . '"', $body, $section);
		}
	}
}
