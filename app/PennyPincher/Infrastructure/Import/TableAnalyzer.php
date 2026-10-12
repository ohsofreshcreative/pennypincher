<?php

namespace App\PennyPincher\Infrastructure\Import;

use App\PennyPincher\Domain\Import\DateParser;
use App\PennyPincher\Domain\Import\HeaderNormalizer;
use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Domain\Import\ReportRegistry;

/**
 * Rozpoznaje strukturę eksportu: wiersze tytułowe, wiersz nagłówków, język i zakres dat z nagłówka raportu.
 */
final class TableAnalyzer
{
	private const POLISH_MARKERS = ['kampania', 'koszt', 'kliknięcia', 'wyśw', 'wyświetlenia', 'konwersje', 'dzień', 'grupa reklam', 'wyszukiwane hasło', 'słowo kluczowe'];

	public function __construct(private ReportRegistry $registry)
	{
	}

	/**
	 * @param array<int, array<int, string|null>> $rows
	 * @return array{header_index: int, headers: array<int, string|null>, preamble: array<int, array<int, string|null>>, language: string, title: string|null, period: array{0: string, 1: string}|null}
	 */
	public function analyze(array $rows): array
	{
		$known = $this->registry->knownHeaders();
		$headerIndex = null;
		$bestMatches = 1;

		foreach (array_slice($rows, 0, 30, true) as $index => $row) {
			$matches = 0;

			foreach ($row as $cell) {
				if ($cell !== null && isset($known[HeaderNormalizer::normalize($cell)])) {
					$matches++;
				}
			}

			if ($matches > $bestMatches) {
				$headerIndex = $index;
				$bestMatches = $matches;
			}

			// Pierwszy wiersz z wieloma znanymi nagłówkami wygrywa – dalej są już dane.
			if ($matches >= 3) {
				break;
			}
		}

		if ($headerIndex === null) {
			throw new InvalidValue('Nie znaleziono wiersza z nagłówkami kolumn. Upewnij się, że to eksport raportu z Google Ads.');
		}

		$headers = array_map(fn ($cell) => $cell === null ? null : trim($cell), $rows[$headerIndex]);
		$preamble = array_slice($rows, 0, $headerIndex);

		$normalizedHeaders = implode(' ', array_map([HeaderNormalizer::class, 'normalize'], $headers));
		$language = 'en';

		foreach (self::POLISH_MARKERS as $marker) {
			if (str_contains($normalizedHeaders, $marker)) {
				$language = 'pl';
				break;
			}
		}

		$title = null;
		$period = null;

		foreach ($preamble as $row) {
			foreach ($row as $cell) {
				if ($cell === null || trim($cell) === '') {
					continue;
				}

				$period ??= DateParser::parseRange($cell, $language);

				if ($title === null && DateParser::parseRange($cell, $language) === null) {
					$title = trim($cell);
				}
			}
		}

		return [
			'header_index' => $headerIndex,
			'headers' => $headers,
			'preamble' => $preamble,
			'language' => $language,
			'title' => $title,
			'period' => $period,
		];
	}
}
