<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Domain\Audit\AuditScore;
use App\PennyPincher\Repositories\AuditRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\AuditService;
use Illuminate\Console\Command;

class AuditCommand extends Command
{
	protected $signature = 'pp:audit {project : ID projektu}';

	protected $description = 'Penny Pincher: wykonuje audyt projektu (tylko odczyt danych)';

	public function handle(AuditService $service, ProjectRepository $projects, AuditRepository $audits): int
	{
		$project = $projects->findById((int) $this->argument('project'));

		if ($project === null) {
			$this->error('Projekt nie istnieje.');

			return self::FAILURE;
		}

		$runId = $service->run($project, 'cli');
		$run = $audits->findRun($project->id, $runId);

		$this->info(sprintf('Audyt #%d – Audit Score: %s (%s)', $runId, $run['score'] ?? '—', AuditScore::label($run['score'])));

		if ($run['score'] === null) {
			$this->line((string) ($run['score_breakdown']['reason'] ?? ''));
		}

		foreach ($audits->findings($runId) as $finding) {
			$this->line(sprintf('[%s] %s %s', $finding['severity'], $finding['rule_id'], $finding['title']));
		}

		return self::SUCCESS;
	}
}
