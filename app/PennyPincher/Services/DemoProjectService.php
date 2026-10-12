<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Metrics\Money;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\Import\FileInspector;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * Projekt demonstracyjny z fikcyjnymi danymi.
 *
 * - zawsze w osobnej organizacji „Demo”, z flagą is_demo (etykieta DEMO w całym interfejsie),
 * - dane powstają jako pliki CSV w formacie polskiego eksportu Google Ads i przechodzą przez ten sam
 *   import co prawdziwe raporty (to jednocześnie test end-to-end importera),
 * - generator jest deterministyczny (stałe ziarno), więc dane są powtarzalne.
 */
class DemoProjectService
{
	private const CAMPAIGNS = [
		['Szukaj | Sofy i narożniki', 'Sieć wyszukiwania', 120, 1.9, 0.055, 2.6],
		['Szukaj | Stoły dębowe', 'Sieć wyszukiwania', 80, 1.4, 0.048, 2.1],
		['PMax | Cały katalog', 'Performance Max', 150, 0.9, 0.022, 1.1],
		['Szukaj | Marka', 'Sieć wyszukiwania', 30, 0.6, 0.09, 0.9],
	];

	private const TERMS = [
		['narożnik z funkcją spania', 'Szukaj | Sofy i narożniki', 'Narożniki', 'Dopasowanie do wyrażenia'],
		['sofa 3 osobowa', 'Szukaj | Sofy i narożniki', 'Sofy', 'Dopasowanie przybliżone'],
		['sofa rozkładana tania', 'Szukaj | Sofy i narożniki', 'Sofy', 'Dopasowanie przybliżone'],
		['narożnik do salonu', 'Szukaj | Sofy i narożniki', 'Narożniki', 'Dopasowanie ścisłe'],
		['sofa ikea', 'Szukaj | Sofy i narożniki', 'Sofy', 'Dopasowanie przybliżone'],
		['naprawa sofy', 'Szukaj | Sofy i narożniki', 'Sofy', 'Dopasowanie przybliżone'],
		['sofa używana olx', 'Szukaj | Sofy i narożniki', 'Sofy', 'Dopasowanie przybliżone'],
		['stół dębowy rozkładany', 'Szukaj | Stoły dębowe', 'Stoły', 'Dopasowanie ścisłe'],
		['stół z litego drewna', 'Szukaj | Stoły dębowe', 'Stoły', 'Dopasowanie do wyrażenia'],
		['stół dębowy 200 cm', 'Szukaj | Stoły dębowe', 'Stoły', 'Dopasowanie ścisłe (bliska odmiana)'],
		['jak zrobić stół z drewna', 'Szukaj | Stoły dębowe', 'Stoły', 'Dopasowanie przybliżone'],
		['blat dębowy cena', 'Szukaj | Stoły dębowe', 'Stoły', 'Dopasowanie przybliżone'],
		['krzesła do stołu dębowego', 'Szukaj | Stoły dębowe', 'Stoły', 'Dopasowanie przybliżone'],
		['lipa meble', 'Szukaj | Marka', 'Marka', 'Dopasowanie ścisłe'],
		['lipa meble opinie', 'Szukaj | Marka', 'Marka', 'Dopasowanie do wyrażenia'],
		['lipa meble sklep warszawa', 'Szukaj | Marka', 'Marka', 'Dopasowanie do wyrażenia'],
	];

	public function __construct(
		private Db $db,
		private OrganizationService $organizations,
		private ProjectRepository $projects,
		private ImportService $imports,
		private ImportRepository $importRepository,
	) {
	}

	public function create(int $userId): int
	{
		return $this->db->transaction(fn () => $this->createProject($userId));
	}

	private function createProject(int $userId): int
	{
		$organizationId = $this->organizations->create('Demo', $userId);

		$projectId = $this->projects->create($organizationId, [
			'name' => 'Demo: sklep z meblami',
			'platform' => 'google_ads',
			'currency' => 'PLN',
			'timezone' => 'Europe/Warsaw',
			'monthly_budget_micros' => 12000 * Money::MICROS,
			'website_url' => 'https://example.com/',
			'landing_page_url' => 'https://example.com/sofy/',
			'business_description' => 'Fikcyjny sklep meblowy – dane demonstracyjne do zapoznania się z aplikacją.',
			'target_customer' => 'Osoby urządzające mieszkanie w dużych miastach.',
			'min_project_value_micros' => 1500 * Money::MICROS,
			'max_project_value_micros' => 12000 * Money::MICROS,
			'status' => 'active',
		], true);

		$project = $this->projects->findForUser($userId, $projectId);

		if ($project === null) {
			throw new RuntimeException('Nie udało się utworzyć projektu demonstracyjnego.');
		}

		$to = (new DateTimeImmutable('yesterday', $project->timezone()))->setTime(0, 0);
		$from = $to->modify('-59 days');

		foreach ([
			'demo-kampanie.csv' => $this->campaignCsv($from, $to),
			'demo-wyszukiwane-hasla.csv' => $this->searchTermsCsv($from, $to),
		] as $name => $content) {
			$path = tempnam(get_temp_dir(), 'pp-demo-');
			file_put_contents($path, $content);

			try {
				$importId = $this->imports->upload($project, $userId, FileInspector::inspectPath($path, $name));
				$import = $this->importRepository->findInProject($projectId, $importId);
				$mapping = $import['column_mapping'];

				$result = $this->imports->confirm($project, $import, [
					'report_type' => $import['report_type'],
					'mapping' => $mapping,
					'date_from' => $from->format('Y-m-d'),
					'date_to' => $to->format('Y-m-d'),
				]);

				if (! $result->success) {
					throw new RuntimeException('Import danych demonstracyjnych nie powiódł się: ' . implode(' ', $result->errors));
				}
			} finally {
				@unlink($path);
			}
		}

		return $projectId;
	}

	private function campaignCsv(DateTimeImmutable $from, DateTimeImmutable $to): string
	{
		mt_srand(20261012);

		$lines = [
			['Raport kampanii'],
			[$this->polishDate($from) . ' - ' . $this->polishDate($to)],
			['Dzień', 'Kampania', 'Stan kampanii', 'Typ kampanii', 'Budżet', 'Kod waluty', 'Wyśw.', 'Kliknięcia', 'CTR', 'Koszt', 'Konwersje'],
		];

		for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
			$weekend = (int) $day->format('N') >= 6;

			foreach (self::CAMPAIGNS as [$name, $type, $budget, $cpc, $conversionRate, $ctr]) {
				$impressions = (int) round($budget / $cpc / ($ctr / 100) * (0.75 + mt_rand(0, 50) / 100) * ($weekend ? 0.8 : 1));
				$clicks = (int) round($impressions * $ctr / 100 * (0.8 + mt_rand(0, 40) / 100));
				$cost = round($clicks * $cpc * (0.85 + mt_rand(0, 30) / 100), 2);
				$conversions = round($clicks * $conversionRate * (mt_rand(0, 200) / 100), 0);

				$lines[] = [
					$day->format('Y-m-d'),
					$name,
					'Włączona',
					$type,
					number_format($budget, 2, ',', ''),
					'PLN',
					number_format($impressions, 0, ',', ' '),
					number_format($clicks, 0, ',', ' '),
					$impressions ? number_format($clicks / $impressions * 100, 2, ',', '') . '%' : '0,00%',
					number_format($cost, 2, ',', ' '),
					number_format($conversions, 2, ',', ''),
				];
			}
		}

		$lines[] = ['Łącznie: konto', '', '', '', '', '', '', '', '', '', ''];

		return $this->csv($lines);
	}

	private function searchTermsCsv(DateTimeImmutable $from, DateTimeImmutable $to): string
	{
		mt_srand(1012);

		$lines = [
			['Raport wyszukiwanych haseł'],
			[$this->polishDate($from) . ' - ' . $this->polishDate($to)],
			['Wyszukiwane hasło', 'Typ dopasowania', 'Kampania', 'Grupa reklam', 'Wyśw.', 'Kliknięcia', 'Koszt', 'Konwersje'],
		];

		foreach (self::TERMS as [$term, $campaign, $adGroup, $matchType]) {
			$clicks = mt_rand(8, 260);
			$impressions = $clicks * mt_rand(12, 40);
			$cost = round($clicks * (0.8 + mt_rand(0, 180) / 100), 2);
			$irrelevant = preg_match('/(ikea|naprawa|olx|jak zrobić|używana)/u', $term);
			$conversions = $irrelevant ? 0 : mt_rand(0, max(1, intdiv($clicks, 25)));

			$lines[] = [$term, $matchType, $campaign, $adGroup, (string) $impressions, (string) $clicks, number_format($cost, 2, ',', ' '), number_format($conversions, 2, ',', '')];
		}

		return $this->csv($lines);
	}

	private function csv(array $lines): string
	{
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, "\xEF\xBB\xBF");

		foreach ($lines as $line) {
			fputcsv($stream, $line, ',', '"', '');
		}

		rewind($stream);
		$content = stream_get_contents($stream);
		fclose($stream);

		return $content;
	}

	private function polishDate(DateTimeImmutable $date): string
	{
		$months = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

		return $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
	}
}
