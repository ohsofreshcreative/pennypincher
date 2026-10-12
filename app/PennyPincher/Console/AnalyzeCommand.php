<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\Analysis\AnalysisService;
use Illuminate\Console\Command;

class AnalyzeCommand extends Command
{
	protected $signature = 'pp:analyze {project : ID projektu}
		{--period=last_30 : last_30 | last_90 | last_180 | last_365}
		{--from= : początek własnego zakresu (RRRR-MM-DD)}
		{--to= : koniec własnego zakresu (RRRR-MM-DD)}';

	protected $description = 'Penny Pincher: audyt, Budget Leaks, słowa kluczowe i rekomendacje dla okresu (tylko odczyt danych z bazy)';

	public function handle(AnalysisService $service, ProjectRepository $projects): int
	{
		$project = $projects->findById((int) $this->argument('project'));

		if ($project === null) {
			$this->error('Projekt nie istnieje.');

			return self::FAILURE;
		}

		$period = $this->option('from') && $this->option('to')
			? AnalysisPeriod::custom((string) $this->option('from'), (string) $this->option('to'), $project->timezone())
			: (array_key_exists((string) $this->option('period'), AnalysisPeriod::PRESETS) && $this->option('period') !== 'custom' ? AnalysisPeriod::fromPreset((string) $this->option('period'), $project->timezone()) : null);

		if ($period === null) {
			$this->error('Nieprawidłowy okres.');

			return self::FAILURE;
		}

		$this->line('Okres: ' . $period->label() . ' (' . $period->range->fromString() . ' – ' . $period->range->toString() . ')');
		$result = $service->run($project, $period, 'cli');

		$this->info(sprintf('Audyt #%s · Budget Leaks #%s · Słowa kluczowe #%s', $result['audit_run_id'] ?? '—', $result['leak_run_id'] ?? '—', $result['keyword_run_id'] ?? '—'));

		if ($result['recommendations'] !== null) {
			$r = $result['recommendations'];
			$this->line(sprintf('Rekomendacje: %d (nowe %d, zaktualizowane %d, nieaktualne %d, przywrócone %d)', $r['total'], $r['created'], $r['updated'], $r['obsolete'], $r['restored']));
		}

		foreach ($result['errors'] as $module => $error) {
			$this->warn($module . ': ' . $error);
		}

		return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
	}
}
