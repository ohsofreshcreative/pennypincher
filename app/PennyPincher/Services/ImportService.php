<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Import\FieldKind;
use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Domain\Import\NumberParser;
use App\PennyPincher\Domain\Import\ReportDefinition;
use App\PennyPincher\Domain\Import\ReportRegistry;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\Import\Readers\CsvReader;
use App\PennyPincher\Infrastructure\Import\Readers\TableData;
use App\PennyPincher\Infrastructure\Import\Readers\XlsxReader;
use App\PennyPincher\Infrastructure\Import\TableAnalyzer;
use App\PennyPincher\Jobs\ProcessImport;
use App\PennyPincher\Repositories\ImportRepository;

/**
 * Przebieg importu:
 * 1. upload()  – walidacja pliku, odczyt, rozpoznanie struktury i typu, zapis surowych wierszy (staging),
 * 2. preview() – dane do podglądu i ręcznego mapowania kolumn,
 * 3. confirm() – zapis mapowania i zakresu dat, uruchomienie ProcessImport (walidacja + zapis w transakcji).
 */
class ImportService
{
	public const MAX_ROWS = 100_000;

	public function __construct(
		private Db $db,
		private ImportRepository $imports,
		private ReportRegistry $registry,
		private ProcessImport $processor,
	) {
	}

	/**
	 * @param array{path: string, name: string, format: string, size: int, hash: string} $file wynik FileInspector
	 */
	public function upload(Project $project, int $userId, array $file, ?string $requestedType = null): int
	{
		$table = $this->read($file);
		$analysis = (new TableAnalyzer($this->registry))->analyze($table->rows);

		$definition = $this->registry->get($requestedType) ?? $this->registry->detect($analysis['headers'], $analysis['preamble']);
		$mapping = $definition?->guessMapping($analysis['headers']) ?? [];

		$dataRows = [];

		foreach (array_slice($table->rows, $analysis['header_index'] + 1, null, true) as $index => $cells) {
			$dataRows[] = ['line' => $index + 1, 'cells' => $cells];
		}

		if ($dataRows === []) {
			throw new InvalidValue('Plik nie zawiera wierszy z danymi pod nagłówkiem.');
		}

		$decimalHint = $table->format === 'xlsx' ? NumberParser::DOT : $this->detectDecimalHint($definition, $mapping, $dataRows, $analysis['language']);

		return $this->db->transaction(function () use ($project, $userId, $file, $table, $analysis, $definition, $mapping, $dataRows, $decimalHint) {
			$importId = $this->imports->create([
				'project_id' => $project->id,
				'user_id' => $userId > 0 ? $userId : null,
				'report_type' => $definition?->type(),
				'status' => ImportRepository::STATUS_UPLOADED,
				'original_filename' => mb_substr($file['name'], 0, 255),
				'file_format' => $file['format'],
				'file_size' => $file['size'],
				'file_hash' => $file['hash'],
				'encoding' => $table->encoding,
				'delimiter' => $table->delimiter === "\t" ? 'tab' : $table->delimiter,
				'header_row' => $analysis['header_index'] + 1,
				'number_format' => $decimalHint,
				'column_mapping' => wp_json_encode($mapping),
				'meta' => wp_json_encode([
					'headers' => $analysis['headers'],
					'language' => $analysis['language'],
					'title' => $analysis['title'],
					'detected_period' => $analysis['period'],
					'period' => $analysis['period'],
				], JSON_UNESCAPED_UNICODE),
				'rows_total' => count($dataRows),
			]);

			$this->imports->stage($importId, $dataRows);

			return $importId;
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	public function preview(Project $project, array $import): array
	{
		$definition = $this->registry->get($import['report_type']);
		$headers = $import['meta']['headers'] ?? [];
		$sample = $this->imports->staged((int) $import['id'], 12);
		$duplicate = $this->imports->completedWithHash($project->id, $import['file_hash'], (int) $import['id']);
		$mapping = $import['column_mapping'];

		return [
			'definition' => $definition,
			'definitions' => $this->registry->all(),
			'headers' => $headers,
			'sample' => $sample,
			'mapping' => $mapping,
			'missing' => $definition ? $definition->missingRequirements($mapping) : [],
			'duplicate' => $duplicate,
			'period' => $import['meta']['period'] ?? null,
			'detectedPeriod' => $import['meta']['detected_period'] ?? null,
			'hasDateColumn' => isset($mapping['date']),
		];
	}

	/**
	 * Zapisuje wybory użytkownika (typ, mapowanie, zakres) i uruchamia przetwarzanie.
	 *
	 * @param array<string, mixed> $input
	 */
	public function confirm(Project $project, array $import, array $input): ProcessResult
	{
		$definition = $this->registry->get((string) ($input['report_type'] ?? ''));

		if ($definition === null) {
			throw new ValidationFailed(['report_type' => 'Wybierz typ raportu.']);
		}

		$headers = $import['meta']['headers'] ?? [];
		$mapping = [];
		$used = [];
		$errors = [];

		foreach ($definition->fields() as $field) {
			$value = $input['mapping'][$field->key] ?? '';

			if ($value === '' || $value === null) {
				continue;
			}

			$index = (int) $value;

			if (! array_key_exists($index, $headers)) {
				$errors["mapping.{$field->key}"] = 'Wybrana kolumna nie istnieje w pliku.';
				continue;
			}

			if (isset($used[$index])) {
				$errors["mapping.{$field->key}"] = "Kolumna „{$headers[$index]}” jest już przypisana do pola „{$used[$index]}”.";
				continue;
			}

			$used[$index] = $field->label;
			$mapping[$field->key] = $index;
		}

		foreach ($definition->missingRequirements($mapping) as $i => $message) {
			$errors["missing.{$i}"] = $message;
		}

		$period = null;

		if (! isset($mapping['date'])) {
			$from = trim((string) ($input['date_from'] ?? ''));
			$to = trim((string) ($input['date_to'] ?? ''));

			if (! DateRange::isValidDate($from) || ! DateRange::isValidDate($to)) {
				$errors['period'] = 'Raport nie ma kolumny „Dzień”, więc podaj zakres dat, którego dotyczy (taki jak w Google Ads przy eksporcie).';
			} elseif ($from > $to) {
				$errors['period'] = 'Data początkowa nie może być późniejsza niż końcowa.';
			} else {
				$period = [$from, $to];
			}
		}

		$meta = $import['meta'];
		$meta['period'] = $period;

		$this->imports->update((int) $import['id'], [
			'report_type' => $definition->type(),
			'column_mapping' => $mapping,
			'meta' => $meta,
		]);

		if ($errors !== []) {
			throw new ValidationFailed($errors, 'Popraw mapowanie kolumn.');
		}

		return $this->processor->handle($project->id, (int) $import['id']);
	}

	public function cancel(array $import): void
	{
		$this->db->transaction(function () use ($import) {
			$this->imports->clearStaged((int) $import['id']);
			$this->imports->update((int) $import['id'], ['status' => ImportRepository::STATUS_CANCELLED]);
		});
	}

	public function read(array $file): TableData
	{
		return $file['format'] === 'xlsx'
			? (new XlsxReader)->read($file['path'], self::MAX_ROWS)
			: (new CsvReader)->read($file['path'], self::MAX_ROWS);
	}

	/**
	 * Separator dziesiętny wykrywany z wartości liczbowych w kolumnach metryk.
	 */
	private function detectDecimalHint(?ReportDefinition $definition, array $mapping, array $rows, string $language): ?string
	{
		$values = [];

		if ($definition !== null) {
			foreach ($definition->fields() as $field) {
				if ($field->kind->isNumeric() && isset($mapping[$field->key])) {
					foreach (array_slice($rows, 0, 2000) as $row) {
						$values[] = $row['cells'][$mapping[$field->key]] ?? null;
					}
				}
			}
		}

		return NumberParser::detectDecimalSeparator($values) ?? ($language === 'pl' ? NumberParser::COMMA : NumberParser::DOT);
	}
}
