<?php

namespace App\PennyPincher\Services\Analysis;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Services\AuditService;
use Throwable;

/**
 * Pełna analiza projektu dla okresu: audyt → Budget Leaks → słowa kluczowe i wykluczenia → rekomendacje.
 * Działa wyłącznie na danych w bazie (bez zapytań do Google Ads i DataForSEO) i niczego nie zmienia na koncie.
 */
class AnalysisService
{
	public function __construct(
		private AuditService $audit,
		private BudgetLeakService $leaks,
		private KeywordOpportunityService $keywords,
		private RecommendationService $recommendations,
	) {
	}

	/**
	 * @return array{audit_run_id: ?int, leak_run_id: ?int, keyword_run_id: ?int, recommendations: ?array, errors: array<string, string>}
	 */
	public function run(Project $project, AnalysisPeriod $period, string $trigger = 'manual', ?int $userId = null, bool $withAudit = true): array
	{
		$result = ['audit_run_id' => null, 'leak_run_id' => null, 'keyword_run_id' => null, 'recommendations' => null, 'errors' => []];

		// Każdy moduł osobno – błąd jednego nie blokuje pozostałych (zostaje zapisany w logu i w wyniku).
		$steps = [
			'audit_run_id' => $withAudit ? fn () => $this->audit->run($project, $trigger, $userId, $period) : null,
			'leak_run_id' => fn () => $this->leaks->run($project, $period, $trigger, $userId),
			'keyword_run_id' => fn () => $this->keywords->run($project, $period, $trigger, $userId),
		];

		foreach ($steps as $key => $step) {
			if ($step === null) {
				continue;
			}

			try {
				$result[$key] = $step();
			} catch (Throwable $e) {
				$result['errors'][$key] = 'Moduł nie zakończył analizy. Szczegóły są w logu serwera.';
				error_log('[Penny Pincher] Analiza ' . $key . ' projektu #' . $project->id . ': ' . $e->getMessage());
			}
		}

		try {
			$result['recommendations'] = $this->recommendations->refresh($project, $period, $result['leak_run_id']);
		} catch (Throwable $e) {
			$result['errors']['recommendations'] = 'Nie udało się odświeżyć rekomendacji. Szczegóły są w logu serwera.';
			error_log('[Penny Pincher] Rekomendacje projektu #' . $project->id . ': ' . $e->getMessage());
		}

		return $result;
	}
}
