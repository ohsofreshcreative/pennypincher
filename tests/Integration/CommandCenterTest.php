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
 * Centrum dowodzenia (pp2_dashboard): parytet KPI ze starym Przeglądem, onboarding, zdarzenia, wykresy tylko z danych dziennych.
 */
class CommandCenterTest extends IntegrationTestCase
{
	public function tearDown(): void
	{
		remove_all_filters('pennypincher/feature/' . Feature::DASHBOARD);
		parent::tearDown();
	}

	private function page(string $url, bool $commandCenter): string
	{
		remove_all_filters('pennypincher/feature/' . Feature::DASHBOARD);
		if ($commandCenter) {
			add_filter('pennypincher/feature/' . Feature::DASHBOARD, '__return_true');
		}

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$response = Application::getInstance()->make(Kernel::class)->handle(Request::create($url, 'GET'));
		$this->assertSame(200, $response->getStatusCode(), $url);

		return (string) $response->getContent();
	}

	private function project(?string $fixture): array
	{
		$owner = $this->user('cc_owner');
		$setup = $this->organizationWithProject($owner, 'Centrum');
		wp_set_current_user($owner);
		$this->make(AccessService::class)->rememberProject($setup['project']);

		if ($fixture) {
			$this->import($setup['project'], $owner, $fixture);
		}

		return [$owner, $setup['project']];
	}

	/**
	 * Wartości KPI ze starego Przeglądu (etykieta karty → wartość) i główna kwota wydatków.
	 *
	 * @return array<string, string>
	 */
	private function legacyKpis(string $body): array
	{
		preg_match_all('~<dt[^>]*>([^<]+)</dt>\s*<dd[^>]*>([^<]*)</dd>~u', $body, $m, PREG_SET_ORDER);
		$cards = [];
		foreach ($m as [, $label, $value]) {
			$cards[trim($label)] = trim(html_entity_decode($value));
		}

		preg_match('~id="pp-spend-heading"[^>]*>.*?</h2>\s*<p[^>]*>([^<]+)</p>~su', $body, $spend);

		return [
			'spend' => trim(html_entity_decode($spend[1] ?? '')),
			'conversions' => $cards['Konwersje'] ?? '',
			'cpa' => $cards['Koszt konwersji'] ?? '',
			'ctr' => $cards['CTR'] ?? '',
			'cpc' => $cards['Średni CPC'] ?? '',
			'clicks' => $cards['Kliknięcia'] ?? '',
			'impressions' => $cards['Wyświetlenia'] ?? '',
			'monthly_budget' => $cards['Budżet miesięczny'] ?? '',
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function newKpis(string $body): array
	{
		preg_match_all('~data-kpi="([a-z_]+)"[^>]*>\s*<dt[^>]*>[^<]*</dt>\s*<dd[^>]*>([^<]*)</dd>~u', $body, $m, PREG_SET_ORDER);
		$kpis = [];
		foreach ($m as [, $key, $value]) {
			$kpis[$key] = trim(html_entity_decode($value));
		}

		return $kpis;
	}

	public function testKpisMatchLegacyOverviewForTheSamePeriod(): void
	{
		[, $project] = $this->project('campaigns-pl-bez-znakow.csv');

		foreach ([['range' => 'all'], ['range' => 'last_30']] as $query) {
			$url = AppUrl::project($project->id, '', $query);
			$legacy = $this->legacyKpis($this->page($url, false));
			$new = $this->newKpis($this->page($url, true));

			$this->assertTrue($legacy['spend'] !== '', 'Nie odczytano wydatków ze starego Przeglądu.');
			foreach ($legacy as $key => $value) {
				$this->assertSame($value, $new[$key] ?? null, "KPI {$key} ({$query['range']}) różni się między widokami.");
			}
		}
	}

	public function testEmptyProjectShowsOnboardingInsteadOfKpis(): void
	{
		[, $project] = $this->project(null);
		$body = $this->page(AppUrl::project($project->id), true);

		$this->assertContains('Pierwsze kroki', $body);
		$this->assertContains('1 z 3', $body, 'Budżet miesięczny jest ustawiony, dane i analiza nie.');
		$this->assertContains('Połącz Google Ads', $body);
		$this->assertContains('Nie połączono – dane tylko z importów.', $body);
		$this->assertFalse(str_contains($body, 'data-kpi='), 'Bez danych nie pokazujemy KPI (żadnych zer zastępczych).');
		$this->assertFalse(str_contains($body, 'Szczegóły eksperckie'));
	}

	public function testActivityAndSourcesAfterImport(): void
	{
		[, $project] = $this->project('campaigns-pl-bez-znakow.csv');
		$body = $this->page(AppUrl::project($project->id, '', ['range' => 'all']), true);

		$this->assertContains('Ostatnie zdarzenia', $body);
		$this->assertContains('Import:</span> <span class="font-medium text-ink">campaigns-pl-bez-znakow.csv', $body);
		$this->assertContains('Ostatni: campaigns-pl-bez-znakow.csv', $body);
		$this->assertContains('title="Źródło danych">Import</span>', $body);
		$this->assertContains('Szczegóły eksperckie', $body);
		$this->assertContains('Analizuj ten zakres', $body);
	}

	public function testNoDailyChartsFromPeriodReports(): void
	{
		[, $project] = $this->project('campaigns-en-period.csv');
		$body = $this->page(AppUrl::project($project->id, '', ['range' => 'all']), true);

		$this->assertFalse(str_contains($body, 'Wydatki dziennie'), 'Raport okresowy nie może dać wykresu dziennego.');
		$this->assertContains('Wykresy dzienne wymagają raportu kampanii z kolumną „Dzień”', $body);
	}

	public function testLegacyOverviewStaysWhenFlagIsOff(): void
	{
		[, $project] = $this->project('campaigns-pl-bez-znakow.csv');
		$body = $this->page(AppUrl::project($project->id, '', ['range' => 'all']), false);

		$this->assertContains('Wydatki w wybranym zakresie', $body);
		$this->assertContains('Skuteczność kampanii', $body);
		$this->assertContains('Ostatnie importy', $body);
		$this->assertFalse(str_contains($body, 'Ostatnie zdarzenia'));
	}
}
