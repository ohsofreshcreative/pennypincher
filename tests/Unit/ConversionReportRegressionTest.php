<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Import\DateParser;
use App\PennyPincher\Domain\Import\HeaderNormalizer;
use App\PennyPincher\Domain\Import\NumberParser;
use App\PennyPincher\Domain\Import\ReportRegistry;
use App\PennyPincher\Domain\Import\RowNormalizer;
use App\PennyPincher\Domain\Import\ValueNormalizer;
use App\PennyPincher\Infrastructure\Import\Readers\CsvReader;
use App\PennyPincher\Infrastructure\Import\Readers\XlsxReader;
use App\PennyPincher\Infrastructure\Import\TableAnalyzer;
use Tests\TestCase;

/**
 * Regresja: „Nie znaleziono wiersza z nagłówkami kolumn” dla raportu „Konwersje” z Edytora raportów Google Ads
 * (3 kolumny, nagłówek „Działania powodujące konwersję” w liczbie mnogiej, „Konw. po obejrzeniu”).
 */
class ConversionReportRegressionTest extends TestCase
{
	/**
	 * @return array{0: \App\PennyPincher\Infrastructure\Import\Readers\TableData, 1: array, 2: ?\App\PennyPincher\Domain\Import\ReportDefinition, 3: \App\PennyPincher\Domain\Import\NormalizationResult}
	 */
	private function parse(string $file): array
	{
		$table = str_ends_with($file, '.xlsx')
			? (new XlsxReader)->read($this->fixture($file), 1000)
			: (new CsvReader)->read($this->fixture($file), 1000);
		$registry = new ReportRegistry;
		$analysis = (new TableAnalyzer($registry))->analyze($table->rows);
		$definition = $registry->detect($analysis['headers'], $analysis['preamble']);
		$mapping = $definition->guessMapping($analysis['headers']);

		$rows = [];

		foreach (array_slice($table->rows, $analysis['header_index'] + 1, null, true) as $i => $cells) {
			$rows[] = ['line' => $i + 1, 'cells' => $cells];
		}

		$values = [];

		foreach ($rows as $row) {
			foreach ([$mapping['conversions'] ?? null, $mapping['all_conversions'] ?? null] as $index) {
				if ($index !== null) {
					$values[] = $row['cells'][$index] ?? null;
				}
			}
		}

		$hint = $table->format === 'xlsx' ? NumberParser::DOT : (NumberParser::detectDecimalSeparator($values) ?? ($analysis['language'] === 'pl' ? 'comma' : 'dot'));
		$result = (new RowNormalizer)->normalize($definition, $mapping, $rows, $hint, $analysis['language']);

		return [$table, $analysis, $definition, $result];
	}

	public function testExactReportEditorExport(): void
	{
		[$table, $analysis, $definition, $result] = $this->parse('konwersje-pl-edytor-raportow.csv');

		$this->assertSame(2, $analysis['header_index'], 'Wiersz 3 to nagłówki, a nie tytuł „Konwersje”.');
		$this->assertSame('Konwersje', $analysis['title']);
		$this->assertSame(['2026-01-01', '2026-06-29'], $analysis['period']);
		$this->assertSame('pl', $analysis['language']);
		$this->assertSame('conversion_actions', $definition->type());

		$mapping = $definition->guessMapping($analysis['headers']);
		$this->assertSame(['conversion_action' => 0, 'conversions' => 1, 'view_through_conversions' => 2], $mapping);

		$this->assertFalse($result->hasErrors(), implode(' ', $result->errors));
		$this->assertCount(6, $result->rows);
		$this->assertSame('30 sekund - Bezpłatna konsultacja', $result->rows[0]['conversion_action']);
		$this->assertSame('129', $result->rows[0]['conversions']);
		$this->assertSame('0', $result->rows[0]['view_through_conversions']);

		// Konwersje i konwersje po obejrzeniu są rozdzielone.
		$last = $result->rows[5];
		$this->assertSame('klik w realizacje', $last['conversion_action']);
		$this->assertSame('0', $last['conversions']);
		$this->assertSame('2', $last['view_through_conversions']);
		$this->assertEqualsWithDelta(181.0, array_sum(array_map(fn ($r) => (float) $r['conversions'], $result->rows)), 0.0001);
		$this->assertEqualsWithDelta(2.0, array_sum(array_map(fn ($r) => (float) $r['view_through_conversions'], $result->rows)), 0.0001);
	}

	public function testSameReportAsUtf16TabSeparated(): void
	{
		[$table, $analysis, $definition, $result] = $this->parse('konwersje-pl-utf16-tab.csv');

		$this->assertSame('UTF-16LE', $table->encoding);
		$this->assertSame("\t", $table->delimiter);
		$this->assertSame('conversion_actions', $definition->type());
		$this->assertCount(6, $result->rows);
	}

	public function testSameReportAsXlsx(): void
	{
		[, $analysis, $definition, $result] = $this->parse('konwersje-pl.xlsx');

		$this->assertSame('conversion_actions', $definition->type());
		$this->assertSame(['2026-01-01', '2026-06-29'], $analysis['period']);
		$this->assertFalse($result->hasErrors(), implode(' ', $result->errors));
		$this->assertSame('129', $result->rows[0]['conversions']);
	}

	public function testMetadataRowsBlankLinesTotalsAndNumberVariants(): void
	{
		[$table, $analysis, $definition, $result] = $this->parse('konwersje-pl-warianty.csv');

		$this->assertSame(';', $table->delimiter);
		$this->assertSame(5, $analysis['header_index']);
		$this->assertSame('conversion_actions', $definition->type());
		$this->assertFalse($result->hasErrors(), implode(' ', $result->errors));
		$this->assertCount(3, $result->rows);
		$this->assertSame(1, $result->totalRows);

		// „1 234,56”, „1.234,56” i „1234.56” to ta sama liczba.
		foreach ($result->rows as $row) {
			$this->assertSame('1234.56', $row['conversions'], $row['conversion_action']);
		}

		$this->assertNull($result->rows[1]['conversions_value'], '„--” to brak danych, nie zero.');
		$this->assertSame(12_500_000, $result->rows[2]['conversions_value']);
	}

	public function testEnglishReportEditorExport(): void
	{
		[, $analysis, $definition, $result] = $this->parse('conversions-en-editor.csv');

		$this->assertSame('en', $analysis['language']);
		$this->assertSame(['2026-01-01', '2026-06-29'], $analysis['period']);
		$this->assertSame('conversion_actions', $definition->type());
		$this->assertSame('2', $result->rows[2]['view_through_conversions']);
		$this->assertSame('18', $result->rows[0]['conversions']);
	}

	public function testHeadersWithoutPolishCharacters(): void
	{
		[, $analysis, $definition, $result] = $this->parse('campaigns-pl-bez-znakow.csv');

		$this->assertSame('campaigns', $definition->type());
		$this->assertSame(['2026-09-01', '2026-09-30'], $analysis['period']);
		$this->assertSame(120_500_000, $result->rows[0]['cost']);
		$this->assertSame('1', $result->rows[0]['view_through_conversions']);
		$this->assertSame('enabled', $result->rows[0]['status']['status']);
	}

	public function testHeaderKeysAreTolerant(): void
	{
		$this->assertSame(HeaderNormalizer::key('Działania powodujące konwersję'), HeaderNormalizer::key('dzialania powodujace konwersje'));
		$this->assertSame('konw po obejrzeniu', HeaderNormalizer::key('Konw. po obejrzeniu'));
		$this->assertSame('view through conv', HeaderNormalizer::key('View-through conv.'));
		$this->assertSame('uwzglednij w konwersjach', HeaderNormalizer::key('Uwzględnij w „Konwersjach”'));
		$this->assertSame('koszt', HeaderNormalizer::key('Koszt (PLN)'));
	}

	public function testAllConversionHeaderVariantsAreRecognized(): void
	{
		$definition = (new ReportRegistry)->get('conversion_actions');

		foreach (['Działania powodujące konwersję', 'Działanie powodujące konwersję', 'Conversion action', 'Conversion actions'] as $header) {
			$mapping = $definition->guessMapping([$header, 'Konwersje']);
			$this->assertSame(0, $mapping['conversion_action'] ?? null, $header);
		}

		foreach (['Konwersje', 'Conversions'] as $header) {
			$this->assertSame(1, $definition->guessMapping(['Conversion action', $header])['conversions'] ?? null, $header);
		}

		foreach (['Konw. po obejrzeniu', 'View-through conversions', 'View-through conv.'] as $header) {
			$this->assertSame(1, $definition->guessMapping(['Conversion action', $header])['view_through_conversions'] ?? null, $header);
		}
	}

	public function testSingleCellTitleIsNeverTakenAsHeader(): void
	{
		$analyzer = new TableAnalyzer(new ReportRegistry);

		$this->assertSame(2, $analyzer->headerIndex([['Kampania'], ['Koszt'], ['Kampania', 'Koszt'], ['A', '1']]));
		$this->assertNull($analyzer->headerIndex([['Konwersje'], ['foo', 'bar'], ['1', '2']]));
	}

	public function testFoldedDatesAndStatuses(): void
	{
		$this->assertSame('2026-09-01', DateParser::parse('1 wrzesnia 2026'));
		$this->assertSame('2026-06-29', DateParser::parse('29 czerwca 2026'));
		$this->assertSame('enabled', ValueNormalizer::status('Wlaczona'));
		$this->assertSame('exact', ValueNormalizer::matchType('Dopasowanie scisle'));
	}
}
