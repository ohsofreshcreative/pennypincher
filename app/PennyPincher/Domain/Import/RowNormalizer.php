<?php

namespace App\PennyPincher\Domain\Import;

use App\PennyPincher\Domain\Metrics\Money;

/**
 * Zamienia surowe wiersze (po nagłówku) na wartości typowane według definicji raportu i mapowania kolumn.
 *
 * Wynik:
 * - liczby całkowite (int), kwoty w mikrojednostkach (int), konwersje jako string dziesiętny,
 * - null tam, gdzie eksport nie ma wartości („--”, puste pole) – nigdy 0,
 * - wiersze podsumowań i puste są pomijane i liczone osobno,
 * - każdy błąd wartości jest raportowany z numerem wiersza pliku.
 */
final class RowNormalizer
{
	private const MAX_REPORTED_ERRORS = 50;

	/**
	 * @param array<string, int> $mapping pole → indeks kolumny
	 * @param array<int, array{line: int, cells: array<int, string|null>}> $rows
	 */
	public function normalize(ReportDefinition $definition, array $mapping, array $rows, ?string $decimalHint, string $language): NormalizationResult
	{
		$result = new NormalizationResult;
		$primary = $definition->primaryField();

		foreach ($rows as $row) {
			$cells = $row['cells'];
			$line = $row['line'];

			if ($this->isEmpty($cells)) {
				$result->skipped++;
				continue;
			}

			if ($this->isTotalRow($cells, $mapping[$primary] ?? null, $mapping['campaign'] ?? null)) {
				$result->skipped++;
				$result->totalRows++;
				continue;
			}

			$normalized = [];
			$rowErrors = [];

			foreach ($definition->fields() as $field) {
				if (! isset($mapping[$field->key])) {
					$normalized[$field->key] = null;
					continue;
				}

				$raw = $cells[$mapping[$field->key]] ?? null;

				try {
					$normalized[$field->key] = $this->value($field, $raw, $decimalHint, $language);
				} catch (InvalidValue $e) {
					$rowErrors[] = "{$field->label}: {$e->getMessage()}";
					$normalized[$field->key] = null;
				}

				if ($field->required && $normalized[$field->key] === null && $rowErrors === []) {
					$rowErrors[] = "brak wartości w kolumnie „{$field->label}”";
				}
			}

			if ($rowErrors !== []) {
				$result->addError("Wiersz {$line}: " . implode('; ', $rowErrors) . '.', self::MAX_REPORTED_ERRORS);
				continue;
			}

			$normalized['_line'] = $line;
			$result->rows[] = $normalized;

			if (! empty($normalized['date'])) {
				$result->minDate = $result->minDate === null ? $normalized['date'] : min($result->minDate, $normalized['date']);
				$result->maxDate = $result->maxDate === null ? $normalized['date'] : max($result->maxDate, $normalized['date']);
			}

			if (! empty($normalized['currency'])) {
				$result->currencies[$normalized['currency']] = true;
			}
		}

		return $result;
	}

	/**
	 * @throws InvalidValue
	 */
	public function value(FieldDefinition $field, ?string $raw, ?string $decimalHint, string $language): mixed
	{
		$raw = $raw === null ? null : trim($raw);

		if ($raw === null || $raw === '' || $raw === '--' || $raw === ' --') {
			return null;
		}

		switch ($field->kind) {
			case FieldKind::Text:
				return mb_substr($raw, 0, 500);

			case FieldKind::Id:
				$id = preg_replace('/[\s-]/', '', $raw);

				if (! preg_match('/^\d{1,30}$/', $id)) {
					throw new InvalidValue("„{$raw}” nie jest identyfikatorem liczbowym.");
				}

				return $id;

			case FieldKind::Integer:
				$number = NumberParser::parse($raw, $decimalHint);

				if ($number === null) {
					return null;
				}

				if (! preg_match('/^-?\d+$/', $number)) {
					throw new InvalidValue("„{$raw}” powinno być liczbą całkowitą.");
				}

				if (strlen(ltrim($number, '-')) > 15) {
					throw new InvalidValue("„{$raw}” jest poza zakresem.");
				}

				return (int) $number;

			case FieldKind::Money:
				$number = NumberParser::parse($raw, $decimalHint);

				if ($number === null) {
					return null;
				}

				try {
					return Money::toMicros($number);
				} catch (\InvalidArgumentException $e) {
					throw new InvalidValue($e->getMessage());
				}

			case FieldKind::Decimal:
				$number = NumberParser::parse($raw, $decimalHint);

				return $number === null ? null : $this->roundDecimal($number, 4);

			case FieldKind::Date:
				return DateParser::parse($raw, $language);

			case FieldKind::Status:
				return ['status' => ValueNormalizer::status($raw), 'raw' => mb_substr($raw, 0, 120)];

			case FieldKind::MatchType:
				return ValueNormalizer::matchType($raw);

			case FieldKind::Boolean:
				return ValueNormalizer::boolean($raw);

			case FieldKind::Currency:
				return ValueNormalizer::currency($raw);
		}

		return $raw;
	}

	/**
	 * Zaokrąglenie połówkowe łańcucha dziesiętnego bez użycia floatów.
	 */
	private function roundDecimal(string $number, int $scale): string
	{
		$negative = str_starts_with($number, '-');
		$number = ltrim($number, '-');
		[$integer, $fraction] = array_pad(explode('.', $number, 2), 2, '');

		if (strlen($fraction) > $scale) {
			$roundUp = (int) $fraction[$scale] >= 5;
			$fraction = substr($fraction, 0, $scale);

			if ($roundUp) {
				$digits = str_split(str_pad($integer, 1, '0') . $fraction);

				for ($i = count($digits) - 1; $i >= 0; $i--) {
					if ($digits[$i] === '9') {
						$digits[$i] = '0';
					} else {
						$digits[$i] = (string) ((int) $digits[$i] + 1);
						break;
					}
				}

				$joined = implode('', $digits);

				if ($i < 0) {
					$joined = '1' . $joined;
				}

				$integer = substr($joined, 0, strlen($joined) - $scale);
				$fraction = substr($joined, -$scale);
			}
		}

		$fraction = rtrim($fraction, '0');
		$result = ($integer === '' ? '0' : $integer) . ($fraction !== '' ? '.' . $fraction : '');

		return ($negative && $result !== '0' ? '-' : '') . $result;
	}

	private function isEmpty(array $cells): bool
	{
		foreach ($cells as $cell) {
			if ($cell !== null && trim($cell) !== '') {
				return false;
			}
		}

		return true;
	}

	private function isTotalRow(array $cells, ?int $primaryIndex, ?int $campaignIndex): bool
	{
		$first = null;

		foreach ($cells as $cell) {
			if ($cell !== null && trim($cell) !== '') {
				$first = $cell;
				break;
			}
		}

		foreach ([$first, $primaryIndex !== null ? ($cells[$primaryIndex] ?? null) : null, $campaignIndex !== null ? ($cells[$campaignIndex] ?? null) : null] as $value) {
			if (HeaderNormalizer::isTotalLabel($value)) {
				return true;
			}
		}

		return false;
	}
}
