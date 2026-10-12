<?php

namespace App\PennyPincher\Infrastructure\Import\Readers;

use App\PennyPincher\Domain\Import\InvalidValue;

/**
 * Czytnik eksportów CSV/TSV z Google Ads.
 *
 * Google Ads oferuje m.in. „CSV” (UTF-8, przecinek) i „Excel CSV” (UTF-16LE, tabulator).
 * Wykrywamy kodowanie (BOM, UTF-16 bez BOM, UTF-8, Windows-1250) i separator.
 */
final class CsvReader
{
	private const DELIMITERS = [',', ';', "\t", '|'];

	public function read(string $path, int $maxRows): TableData
	{
		$raw = file_get_contents($path);

		if ($raw === false) {
			throw new InvalidValue('Nie udało się odczytać pliku.');
		}

		[$text, $encoding] = self::toUtf8($raw);
		$text = str_replace(["\r\n", "\r"], "\n", $text);

		if (str_contains($text, "\0")) {
			throw new InvalidValue('Plik zawiera dane binarne – to nie jest prawidłowy plik CSV.');
		}

		$delimiter = self::detectDelimiter($text);

		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $text);
		rewind($stream);

		$rows = [];

		while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
			if (count($rows) >= $maxRows) {
				fclose($stream);

				throw new InvalidValue("Plik ma więcej niż {$maxRows} wierszy. Podziel eksport na mniejsze zakresy dat.");
			}

			$rows[] = $row === [null] ? [] : array_map(fn ($cell) => $cell === null ? null : trim($cell), $row);
		}

		fclose($stream);

		return new TableData($rows, $encoding, $delimiter, 'csv');
	}

	/**
	 * @return array{0: string, 1: string} [tekst UTF-8, wykryte kodowanie]
	 */
	public static function toUtf8(string $raw): array
	{
		if (str_starts_with($raw, "\xEF\xBB\xBF")) {
			return [substr($raw, 3), 'UTF-8'];
		}

		if (str_starts_with($raw, "\xFF\xFE")) {
			return [self::convert(substr($raw, 2), 'UTF-16LE'), 'UTF-16LE'];
		}

		if (str_starts_with($raw, "\xFE\xFF")) {
			return [self::convert(substr($raw, 2), 'UTF-16BE'), 'UTF-16BE'];
		}

		// UTF-16 bez BOM: co drugi bajt zerowy w próbce.
		$sample = substr($raw, 0, 400);
		$length = strlen($sample);

		if ($length >= 4) {
			$oddZeros = 0;
			$evenZeros = 0;

			for ($i = 0; $i < $length; $i++) {
				if ($sample[$i] === "\0") {
					$i % 2 ? $oddZeros++ : $evenZeros++;
				}
			}

			if ($oddZeros > $length / 4) {
				return [self::convert($raw, 'UTF-16LE'), 'UTF-16LE'];
			}

			if ($evenZeros > $length / 4) {
				return [self::convert($raw, 'UTF-16BE'), 'UTF-16BE'];
			}
		}

		if (mb_check_encoding($raw, 'UTF-8')) {
			return [$raw, 'UTF-8'];
		}

		return [self::convert($raw, 'Windows-1250'), 'Windows-1250'];
	}

	public static function detectDelimiter(string $text): string
	{
		$lines = array_slice(array_values(array_filter(explode("\n", $text), fn ($line) => trim($line) !== '')), 0, 40);
		$best = ',';
		$bestScore = -1;

		foreach (self::DELIMITERS as $delimiter) {
			$counts = [];

			foreach ($lines as $line) {
				$fields = count(str_getcsv($line, $delimiter, '"', ''));

				if ($fields > 1) {
					$counts[$fields] = ($counts[$fields] ?? 0) + 1;
				}
			}

			if ($counts === []) {
				continue;
			}

			arsort($counts);
			$fieldCount = array_key_first($counts);
			$score = $counts[$fieldCount] * 100 + $fieldCount;

			if ($score > $bestScore) {
				$best = $delimiter;
				$bestScore = $score;
			}
		}

		return $best;
	}

	private static function convert(string $raw, string $from): string
	{
		$converted = $from === 'Windows-1250'
			? iconv('Windows-1250', 'UTF-8//IGNORE', $raw)
			: mb_convert_encoding($raw, 'UTF-8', $from);

		if ($converted === false || ! mb_check_encoding($converted, 'UTF-8')) {
			throw new InvalidValue('Nie udało się rozpoznać kodowania pliku. Zapisz eksport jako CSV w UTF-8.');
		}

		return $converted;
	}
}
