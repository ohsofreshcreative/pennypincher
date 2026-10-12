<?php

namespace App\PennyPincher\Jobs;

use App\PennyPincher\Domain\Import\ReportDefinition;
use App\PennyPincher\Domain\Import\ReportRegistry;
use App\PennyPincher\Domain\Import\RowNormalizer;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\Import\Writers\CampaignWriter;
use App\PennyPincher\Infrastructure\Import\Writers\ConversionActionWriter;
use App\PennyPincher\Infrastructure\Import\Writers\ImportContext;
use App\PennyPincher\Infrastructure\Import\Writers\KeywordWriter;
use App\PennyPincher\Infrastructure\Import\Writers\ReportWriter;
use App\PennyPincher\Infrastructure\Import\Writers\SearchTermWriter;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\ProcessResult;
use Throwable;

/**
 * Walidacja i zapis importu. Zadanie jest samodzielne (tylko identyfikatory na wejściu), więc może być
 * uruchamiane synchronicznie z kontrolera, z WP-CLI (`wp acorn pp:import:process`) albo z kolejki.
 *
 * Gwarancje:
 * - jakikolwiek błąd wartości w pliku = nic nie jest zapisywane (użytkownik poprawia mapowanie i ponawia),
 * - zapis encji i metryk odbywa się w jednej transakcji,
 * - ponowny import tego samego raportu nadpisuje te same wiersze metryk (unikalny klucz), nie dubluje ich.
 */
class ProcessImport
{
	public function __construct(
		private Db $db,
		private ImportRepository $imports,
		private ProjectRepository $projects,
		private ReportRegistry $registry,
	) {
	}

	public function handle(int $projectId, int $importId): ProcessResult
	{
		$import = $this->imports->findInProject($projectId, $importId);
		$project = $this->projects->findById($projectId);

		if ($import === null || $project === null || $import['status'] !== ImportRepository::STATUS_UPLOADED) {
			return new ProcessResult(false, ['Import nie istnieje albo został już przetworzony.'], 1);
		}

		$definition = $this->registry->get($import['report_type']);

		if ($definition === null) {
			return $this->reject($importId, ['Nie wybrano typu raportu.']);
		}

		$mapping = array_map('intval', $import['column_mapping']);
		$result = (new RowNormalizer)->normalize(
			$definition,
			$mapping,
			$this->imports->staged($importId),
			$import['number_format'] ?: null,
			$import['meta']['language'] ?? 'pl'
		);

		if ($result->hasErrors()) {
			$errors = $result->errors;

			if ($result->errorCount > count($errors)) {
				$errors[] = sprintf('…oraz %d kolejnych błędów.', $result->errorCount - count($errors));
			}

			return $this->reject($importId, $errors, $result->errorCount, $result->skipped);
		}

		if ($result->rows === []) {
			return $this->reject($importId, ['Plik nie zawiera żadnych wierszy z danymi (po pominięciu pustych wierszy i podsumowań).'], 1, $result->skipped);
		}

		$daily = isset($mapping['date']);
		$period = $import['meta']['period'] ?? null;

		if ($daily) {
			$withoutDate = array_filter($result->rows, fn (array $row) => empty($row['date']));

			if ($withoutDate !== []) {
				$lines = array_slice(array_column($withoutDate, '_line'), 0, 10);

				return $this->reject($importId, ['Kolumna „Dzień” jest pusta w wierszach: ' . implode(', ', $lines) . '. Raport dzienny musi mieć datę w każdym wierszu.']);
			}
		}

		if (! $daily && (! is_array($period) || count($period) !== 2)) {
			return $this->reject($importId, ['Podaj zakres dat raportu – plik nie ma kolumny „Dzień”.']);
		}

		$currencies = array_keys($result->currencies);

		if (count($currencies) > 1) {
			return $this->reject($importId, ['Raport zawiera kilka walut (' . implode(', ', $currencies) . '). Zaimportuj każde konto osobno.']);
		}

		if ($currencies !== [] && $currencies[0] !== $project->currency) {
			return $this->reject($importId, [sprintf(
				'Waluta raportu (%s) jest inna niż waluta projektu (%s). Zmień walutę w ustawieniach projektu albo zaimportuj właściwe konto.',
				$currencies[0],
				$project->currency
			)]);
		}

		$context = new ImportContext(
			projectId: $projectId,
			importId: $importId,
			daily: $daily,
			dateFrom: $daily ? $result->minDate : $period[0],
			dateTo: $daily ? $result->maxDate : $period[1],
			currency: $project->currency,
		);

		try {
			$write = $this->db->transaction(function () use ($definition, $context, $result, $importId, $daily) {
				$write = $this->writer($definition)->write($context, $result->rows);

				$this->imports->clearStaged($importId);
				$this->imports->update($importId, [
					'status' => ImportRepository::STATUS_COMPLETED,
					'granularity' => $daily ? 'day' : 'period',
					'date_from' => $context->dateFrom,
					'date_to' => $context->dateTo,
					'currency' => $context->currency,
					'rows_imported' => count($result->rows),
					'rows_skipped' => $result->skipped,
					'errors' => null,
					'meta' => array_merge($this->meta($importId), [
						'metric_rows' => $write->metricRows,
						'entities' => $write->entities,
						'merged_rows' => $write->mergedRows,
						'total_rows_skipped' => $result->totalRows,
					]),
					'completed_at' => $this->db->now(),
				]);

				return $write;
			});
		} catch (Throwable $e) {
			error_log('[Penny Pincher] Import #' . $importId . ' failed: ' . $e->getMessage());

			$this->imports->update($importId, [
				'status' => ImportRepository::STATUS_FAILED,
				'errors' => ['Zapis danych nie powiódł się – żadne dane z tego pliku nie zostały zapisane. Szczegóły są w logu serwera.'],
			]);

			return new ProcessResult(false, ['Zapis danych nie powiódł się – żadne dane z tego pliku nie zostały zapisane.'], 1);
		}

		return new ProcessResult(true, [], 0, count($result->rows), $result->skipped);
	}

	public function writer(ReportDefinition $definition): ReportWriter
	{
		return match ($definition->type()) {
			ReportDefinition::CAMPAIGNS => new CampaignWriter($this->db),
			ReportDefinition::SEARCH_TERMS => new SearchTermWriter($this->db),
			ReportDefinition::KEYWORDS => new KeywordWriter($this->db),
			ReportDefinition::CONVERSION_ACTIONS => new ConversionActionWriter($this->db),
		};
	}

	/**
	 * Błędy walidacji: import pozostaje w stanie „uploaded”, żeby można było poprawić mapowanie.
	 */
	private function reject(int $importId, array $errors, ?int $count = null, int $skipped = 0): ProcessResult
	{
		$this->imports->update($importId, ['errors' => $errors, 'rows_skipped' => $skipped]);

		return new ProcessResult(false, $errors, $count ?? count($errors), 0, $skipped);
	}

	private function meta(int $importId): array
	{
		$row = $this->db->first('SELECT meta FROM ' . $this->db->table('imports') . ' WHERE id = ?', [$importId]);

		return $row && $row['meta'] ? (json_decode($row['meta'], true) ?: []) : [];
	}
}
