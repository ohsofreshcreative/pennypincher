<?php

namespace App\PennyPincher\Infrastructure\Import\Readers;

use App\PennyPincher\Domain\Import\DateParser;
use App\PennyPincher\Domain\Import\InvalidValue;
use XMLReader;
use ZipArchive;

/**
 * Minimalny, strumieniowy czytnik pierwszego arkusza XLSX (ZipArchive + XMLReader).
 *
 * Własna implementacja zamiast biblioteki, bo potrzebujemy tylko odczytu wartości z jednego arkusza,
 * a projekt nie ma zależności do arkuszy kalkulacyjnych. Obsługuje: shared strings, inline strings,
 * liczby, wartości logiczne, formuły z zapisaną wartością oraz komórki z formatem daty.
 * Komórki liczbowe zwraca w zapisie kanonicznym z kropką („1234.5”).
 */
final class XlsxReader
{
	private const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

	public function read(string $path, int $maxRows): TableData
	{
		if (! class_exists(ZipArchive::class) || ! class_exists(XMLReader::class)) {
			throw new InvalidValue('Serwer nie obsługuje plików XLSX (brak rozszerzeń PHP zip/xmlreader). Wyeksportuj raport jako CSV.');
		}

		$zip = new ZipArchive;

		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new InvalidValue('Plik XLSX jest uszkodzony albo nie jest arkuszem Excela.');
		}

		try {
			$this->guardAgainstZipBomb($zip);

			$sheetPath = $this->firstSheetPath($zip);
			$sharedStrings = $this->sharedStrings($zip);
			$dateStyles = $this->dateStyles($zip);

			$xml = $zip->getFromName($sheetPath);

			if ($xml === false) {
				throw new InvalidValue('W pliku XLSX nie znaleziono arkusza z danymi.');
			}

			$this->guardAgainstDoctype($xml);

			$rows = $this->rows($xml, $sharedStrings, $dateStyles, $maxRows);
		} finally {
			$zip->close();
		}

		return new TableData($rows, 'UTF-8', null, 'xlsx');
	}

	private function guardAgainstZipBomb(ZipArchive $zip): void
	{
		$total = 0;

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			$total += (int) ($stat['size'] ?? 0);
			$name = (string) ($stat['name'] ?? '');

			if (str_contains($name, '..') || str_starts_with($name, '/')) {
				throw new InvalidValue('Plik XLSX ma nieprawidłową strukturę.');
			}
		}

		if ($total > self::MAX_UNCOMPRESSED_BYTES) {
			throw new InvalidValue('Plik XLSX po rozpakowaniu jest zbyt duży.');
		}

		if ($zip->locateName('xl/workbook.xml') === false) {
			throw new InvalidValue('To nie jest arkusz Excela (brak xl/workbook.xml).');
		}
	}

	/**
	 * Arkusze Excela nie zawierają deklaracji DOCTYPE – jej obecność oznacza próbę ataku XXE / „billion laughs”.
	 */
	private function guardAgainstDoctype(string $xml): void
	{
		if (stripos(substr($xml, 0, 2048), '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
			throw new InvalidValue('Plik XLSX ma nieprawidłową strukturę.');
		}
	}

	private function firstSheetPath(ZipArchive $zip): string
	{
		$workbook = $this->loadXml($zip->getFromName('xl/workbook.xml'));
		$relations = $this->loadXml($zip->getFromName('xl/_rels/workbook.xml.rels') ?: '<Relationships/>');

		$workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
		$sheets = $workbook->xpath('//m:sheets/m:sheet');

		if (! $sheets) {
			return 'xl/worksheets/sheet1.xml';
		}

		$relationId = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

		foreach ($relations->children() as $relation) {
			if ((string) $relation['Id'] === $relationId) {
				$target = ltrim((string) $relation['Target'], '/');

				return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
			}
		}

		return 'xl/worksheets/sheet1.xml';
	}

	/**
	 * @return array<int, string>
	 */
	private function sharedStrings(ZipArchive $zip): array
	{
		$xml = $zip->getFromName('xl/sharedStrings.xml');

		if ($xml === false) {
			return [];
		}

		$this->guardAgainstDoctype($xml);

		$reader = new XMLReader;
		$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
		$strings = [];
		$current = null;

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
				$current = '';
			} elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
				$current .= $reader->readString();
			} elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'rPh') {
				$reader->next();
			} elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
				$strings[] = $current;
				$current = null;
			}
		}

		$reader->close();

		return $strings;
	}

	/**
	 * Indeksy stylów komórek, które formatują liczbę jako datę.
	 *
	 * @return array<int, true>
	 */
	private function dateStyles(ZipArchive $zip): array
	{
		$xml = $zip->getFromName('xl/styles.xml');

		if ($xml === false) {
			return [];
		}

		$styles = $this->loadXml($xml);
		$styles->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

		$dateFormats = array_fill_keys([14, 15, 16, 17, 22, 27, 30, 36, 45, 46, 47, 50, 57], true);

		foreach ($styles->xpath('//m:numFmts/m:numFmt') ?: [] as $format) {
			$code = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $format['formatCode']));

			if (preg_match('/[dy]/', $code) || preg_match('/m.*[dy]|[dy].*m/', $code)) {
				$dateFormats[(int) $format['numFmtId']] = true;
			}
		}

		$result = [];

		foreach ($styles->xpath('//m:cellXfs/m:xf') ?: [] as $index => $xf) {
			if (isset($dateFormats[(int) $xf['numFmtId']])) {
				$result[$index] = true;
			}
		}

		return $result;
	}

	/**
	 * @return array<int, array<int, string|null>>
	 */
	private function rows(string $xml, array $sharedStrings, array $dateStyles, int $maxRows): array
	{
		$reader = new XMLReader;
		$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);

		$rows = [];
		$row = null;
		$rowIndex = 0;
		$cell = null;

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::ELEMENT) {
				switch ($reader->localName) {
					case 'row':
						$number = (int) $reader->getAttribute('r');
						$rowIndex = $number > 0 ? $number - 1 : count($rows);

						// Puste wiersze pomiędzy (np. odstęp pod tytułem) zachowujemy jako puste.
						while (count($rows) < $rowIndex) {
							$rows[] = [];
						}

						if (count($rows) >= $maxRows) {
							$reader->close();

							throw new InvalidValue("Plik ma więcej niż {$maxRows} wierszy. Podziel eksport na mniejsze zakresy dat.");
						}

						$row = [];

						if ($reader->isEmptyElement) {
							$rows[] = [];
							$row = null;
						}

						break;

					case 'c':
						$cell = [
							'ref' => (string) $reader->getAttribute('r'),
							'type' => (string) ($reader->getAttribute('t') ?? 'n'),
							'style' => (int) $reader->getAttribute('s'),
							'value' => null,
						];

						if ($reader->isEmptyElement && $row !== null) {
							$row[$this->columnIndex($cell['ref'], count($row))] = null;
							$cell = null;
						}

						break;

					case 'v':
						if ($cell !== null) {
							$cell['value'] = $reader->readString();
						}

						break;

					case 'is':
						if ($cell !== null) {
							$cell['value'] = $this->inlineString($reader);
						}

						break;
				}
			} elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
				if ($reader->localName === 'c' && $cell !== null && $row !== null) {
					$row[$this->columnIndex($cell['ref'], count($row))] = $this->cellValue($cell, $sharedStrings, $dateStyles);
					$cell = null;
				} elseif ($reader->localName === 'row' && $row !== null) {
					$rows[] = $this->fillGaps($row);
					$row = null;
				}
			}
		}

		$reader->close();

		return $rows;
	}

	private function inlineString(XMLReader $reader): string
	{
		$value = '';
		$depth = $reader->depth;

		while ($reader->read() && ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'is' && $reader->depth === $depth)) {
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
				$value .= $reader->readString();
			}
		}

		return $value;
	}

	private function cellValue(array $cell, array $sharedStrings, array $dateStyles): ?string
	{
		$value = $cell['value'];

		if ($value === null || $value === '') {
			return null;
		}

		return match ($cell['type']) {
			's' => trim((string) ($sharedStrings[(int) $value] ?? '')),
			'inlineStr', 'str' => trim($value),
			'b' => $value === '1' ? 'TRUE' : 'FALSE',
			'e' => null,
			default => $this->numericValue($value, isset($dateStyles[$cell['style']])),
		};
	}

	private function numericValue(string $value, bool $isDate): string
	{
		if (! is_numeric($value)) {
			return trim($value);
		}

		if ($isDate) {
			return DateParser::fromExcelSerial((int) floor((float) $value));
		}

		// Liczby w XLSX są zapisane w notacji kanonicznej; zamieniamy tylko notację wykładniczą.
		if (stripos($value, 'e') !== false) {
			$value = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');
		}

		return $value;
	}

	private function columnIndex(string $reference, int $fallback): int
	{
		if (! preg_match('/^([A-Z]+)/', $reference, $m)) {
			return $fallback;
		}

		$index = 0;

		foreach (str_split($m[1]) as $letter) {
			$index = $index * 26 + (ord($letter) - 64);
		}

		return $index - 1;
	}

	/**
	 * @param array<int, string|null> $row
	 * @return array<int, string|null>
	 */
	private function fillGaps(array $row): array
	{
		if ($row === []) {
			return [];
		}

		$max = max(array_keys($row));
		$filled = [];

		for ($i = 0; $i <= $max; $i++) {
			$filled[$i] = $row[$i] ?? null;
		}

		return $filled;
	}

	private function loadXml(string|false $xml): \SimpleXMLElement
	{
		if ($xml === false) {
			throw new InvalidValue('Plik XLSX ma nieprawidłową strukturę.');
		}

		$this->guardAgainstDoctype($xml);

		$previous = libxml_use_internal_errors(true);
		$element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
		libxml_use_internal_errors($previous);

		if ($element === false) {
			throw new InvalidValue('Plik XLSX ma nieprawidłową strukturę.');
		}

		return $element;
	}
}
