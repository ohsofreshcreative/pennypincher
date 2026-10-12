<?php

namespace App\PennyPincher\Domain\Import;

use App\PennyPincher\Domain\Import\Reports\CampaignReport;
use App\PennyPincher\Domain\Import\Reports\ConversionActionReport;
use App\PennyPincher\Domain\Import\Reports\KeywordReport;
use App\PennyPincher\Domain\Import\Reports\SearchTermReport;

/**
 * Rejestr obsługiwanych typów raportów. Nowy typ = nowa definicja + writer.
 */
final class ReportRegistry
{
	/** @var array<string, ReportDefinition> */
	private array $definitions;

	public function __construct()
	{
		foreach ([new CampaignReport, new SearchTermReport, new KeywordReport, new ConversionActionReport] as $definition) {
			$this->definitions[$definition->type()] = $definition;
		}
	}

	/**
	 * @return array<string, ReportDefinition>
	 */
	public function all(): array
	{
		return $this->definitions;
	}

	public function get(?string $type): ?ReportDefinition
	{
		return $type !== null ? ($this->definitions[$type] ?? null) : null;
	}

	/**
	 * Rozpoznaje typ raportu po nagłówkach i tytule eksportu.
	 *
	 * @param array<int, string|null> $headers
	 */
	public function detect(array $headers, array $preambleRows = []): ?ReportDefinition
	{
		$best = null;
		$bestScore = 0;

		foreach ($this->definitions as $definition) {
			$score = $definition->score($headers, $preambleRows);

			if ($score > $bestScore) {
				$best = $definition;
				$bestScore = $score;
			}
		}

		return $best;
	}

	/**
	 * Wszystkie znane aliasy nagłówków (do wykrywania wiersza nagłówka).
	 *
	 * @return array<string, true>
	 */
	public function knownHeaders(): array
	{
		$known = [];

		foreach ($this->definitions as $definition) {
			foreach ($definition->fields() as $field) {
				foreach ($field->aliases as $alias) {
					$known[$alias] = true;
				}
			}
		}

		return $known;
	}
}
