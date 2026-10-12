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
	private const POLISH_MARKERS = ['kampania', 'koszt', 'klikniecia', 'wysw', 'konwersje', 'konw', 'dzien', 'grupa reklam', 'wyszukiwane haslo', 'slowo kluczowe', 'dzialania', 'dzialanie', 'stan'];

	public function __construct(private ReportRegistry $registry)
	{
	}

	/**
	 * @param array<int, array<int, string|null>> $rows
	 * @return array{header_index: int, headers: array<int, string|null>, preamble: array<int, array<int, string|null>>, language: string, title: string|null, period: array{0: string, 1: string}|null}
	 */
	public function analyze(array $rows): array
	{
		$headerIndex = $this->headerIndex($rows);

		if ($headerIndex === null) {
			throw new InvalidValue('Nie znaleziono wiersza z nagłówkami kolumn. Plik powinien zawierać wiersz z nazwami kolumn z Google Ads (np. „Kampania”, „Koszt” albo „Działania powodujące konwersję”, „Konwersje”).');
		}

		$headers = array_map(fn ($cell) => $cell === null ? null : trim($cell), $rows[$headerIndex]);
		$preamble = array_slice($rows, 0, $headerIndex);

		$headerWords = ' ' . implode(' | ', array_map([HeaderNormalizer::class, 'key'], $headers)) . ' ';
		$language = 'en';

		foreach (self::POLISH_MARKERS as $marker) {
			if (preg_match('/(^|\W)' . preg_quote($marker, '/') . '(\W|$)/u', $headerWords)) {
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

	/**
	 * Wiersz nagłówków to wiersz w pierwszych 30, który:
	 * - ma co najmniej 2 niepuste komórki (tytuł raportu i zakres dat to zwykle jedna komórka),
	 * - zawiera co najmniej 2 znane nagłówki albo 1 nagłówek identyfikujący typ raportu
	 *   (np. „Działania powodujące konwersję” w 3-kolumnowym raporcie konwersji).
	 * Wygrywa wiersz z największą liczbą znanych nagłówków; przy remisie – wcześniejszy.
	 *
	 * @param array<int, array<int, string|null>> $rows
	 */
	public function headerIndex(array $rows): ?int
	{
		$known = $this->registry->knownHeaders();
		$primary = $this->registry->primaryHeaders();
		$best = null;
		$bestScore = 0;

		foreach (array_slice($rows, 0, 30, true) as $index => $row) {
			$cells = array_values(array_filter($row, fn ($cell) => $cell !== null && trim($cell) !== ''));

			if (count($cells) < 2) {
				continue;
			}

			$keys = array_map([HeaderNormalizer::class, 'key'], $cells);
			$matches = count(array_filter($keys, fn ($key) => isset($known[$key])));
			$hasPrimary = array_filter($keys, fn ($key) => isset($primary[$key])) !== [];

			if ($matches === 0 || ($matches < 2 && ! $hasPrimary)) {
				continue;
			}

			$score = $matches * 10 + ($hasPrimary ? 5 : 0);

			if ($score > $bestScore) {
				$best = $index;
				$bestScore = $score;
			}
		}

		return $best;
	}
}
