<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Import\NumberParser;
use App\PennyPincher\Domain\Import\ReportRegistry;
use App\PennyPincher\Domain\Import\RowNormalizer;
use App\PennyPincher\Domain\Import\ValueNormalizer;
use App\PennyPincher\Infrastructure\Import\Readers\CsvReader;
use App\PennyPincher\Infrastructure\Import\Readers\XlsxReader;
use App\PennyPincher\Infrastructure\Import\TableAnalyzer;
use Tests\TestCase;

class ImportParsingTest extends TestCase
{
	private function analyze(string $file): array
	{
		$table = str_ends_with($file, '.xlsx')
			? (new XlsxReader)->read($this->fixture($file), 1000)
			: (new CsvReader)->read($this->fixture($file), 1000);
		$registry = new ReportRegistry;
		$analysis = (new TableAnalyzer($registry))->analyze($table->rows);

		return [$table, $analysis, $registry->detect($analysis['headers'], $analysis['preamble'])];
	}

	public function testUtf16TabSeparatedEnglishExport(): void
	{
		[$table, $analysis, $definition] = $this->analyze('campaigns-en-period.csv');

		$this->assertSame('UTF-16LE', $table->encoding);
		$this->assertSame("\t", $table->delimiter);
		$this->assertSame(2, $analysis['header_index']);
		$this->assertSame('en', $analysis['language']);
		$this->assertSame(['2026-09-01', '2026-09-30'], $analysis['period']);
		$this->assertSame('campaigns', $definition->type());
	}

	public function testPolishSearchTermsExport(): void
	{
		[$table, $analysis, $definition] = $this->analyze('search-terms-pl.csv');

		$this->assertSame(',', $table->delimiter);
		$this->assertSame('pl', $analysis['language']);
		$this->assertSame('search_terms', $definition->type());
		$this->assertSame(['2026-09-01', '2026-09-30'], $analysis['period']);
	}

	public function testXlsxWithDateCellsAndBlankRow(): void
	{
		[$table, $analysis, $definition] = $this->analyze('keywords-pl-daily.xlsx');

		$this->assertSame('keywords', $definition->type());
		$this->assertSame(3, $analysis['header_index']);
		$this->assertSame('2026-09-01', $table->rows[4][0]);
		$this->assertSame('121.5', $table->rows[4][9]);
		$this->assertSame('[strony internetowe]', $table->rows[4][1]);
	}

	public function testWindows1250IsConverted(): void
	{
		[$table] = $this->analyze('campaigns-cp1250.csv');

		$this->assertSame('Windows-1250', $table->encoding);
		$this->assertSame('Kampania Łódź', $table->rows[1][0]);
	}

	public function testNormalizationSkipsTotalsAndKeepsUnknownAsNull(): void
	{
		[$table, $analysis, $definition] = $this->analyze('campaigns-en-period.csv');
		$mapping = $definition->guessMapping($analysis['headers']);
		$rows = [];

		foreach (array_slice($table->rows, $analysis['header_index'] + 1, null, true) as $i => $cells) {
			$rows[] = ['line' => $i + 1, 'cells' => $cells];
		}

		$result = (new RowNormalizer)->normalize($definition, $mapping, $rows, NumberParser::DOT, 'en');

		$this->assertFalse($result->hasErrors(), implode(' ', $result->errors));
		$this->assertCount(3, $result->rows);
		$this->assertSame(2, $result->totalRows);
		$this->assertSame(12345, $result->rows[0]['impressions']);
		$this->assertSame(1_498_380_000, $result->rows[0]['cost']);
		$this->assertSame('12.5', $result->rows[0]['conversions']);
		$this->assertSame('enabled', $result->rows[0]['status']['status']);
		$this->assertSame('111222333', $result->rows[0]['campaign_id']);
		$this->assertNull($result->rows[2]['impressions'], 'Brak danych („--”) to null, nie 0.');
		$this->assertSame(0, $result->rows[2]['cost']);
		$this->assertFalse(isset($mapping['ctr']), 'CTR z pliku nie jest importowany.');
	}

	public function testInvalidValuesAreReportedWithLineNumbers(): void
	{
		[$table, $analysis, $definition] = $this->analyze('invalid-values.csv');
		$mapping = $definition->guessMapping($analysis['headers']);
		$rows = [['line' => 2, 'cells' => $table->rows[1]], ['line' => 3, 'cells' => $table->rows[2]]];

		$result = (new RowNormalizer)->normalize($definition, $mapping, $rows, NumberParser::COMMA, 'pl');

		$this->assertSame(2, $result->errorCount);
		$this->assertContains('Wiersz 2', $result->errors[0]);
		$this->assertContains('Kliknięcia', $result->errors[0]);
		$this->assertContains('liczbą całkowitą', $result->errors[1]);
	}

	public function testMatchTypeAndKeywordSyntax(): void
	{
		$this->assertSame('exact', ValueNormalizer::matchType('Dopasowanie ścisłe'));
		$this->assertSame('exact_close_variant', ValueNormalizer::matchType('Exact match (close variant)'));
		$this->assertSame('phrase', ValueNormalizer::matchType('Dopasowanie do wyrażenia'));
		$this->assertSame('broad', ValueNormalizer::matchType('Broad match'));
		$this->assertSame(['strony www', 'exact'], ValueNormalizer::keywordText('[strony www]'));
		$this->assertSame(['strony www', 'phrase'], ValueNormalizer::keywordText('"strony www"'));
		$this->assertSame('paused', ValueNormalizer::status('Wstrzymana'));
		$this->assertSame('enabled', ValueNormalizer::status('Eligible (Limited)'));
	}

	public function testHeadersWithoutKnownColumnsAreRejected(): void
	{
		$this->assertThrows(\App\PennyPincher\Domain\Import\InvalidValue::class, fn () => $this->analyze('no-headers.csv'), 'nagłówkami');
	}
}
