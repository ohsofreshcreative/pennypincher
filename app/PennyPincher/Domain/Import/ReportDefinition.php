<?php

namespace App\PennyPincher\Domain\Import;

/**
 * Definicja typu raportu Google Ads: jakie pola rozumiemy, jak się nazywają w eksportach i które są wymagane.
 * Logika zapisu do bazy jest w Infrastructure\Import\Writers – definicja opisuje tylko dane.
 */
abstract class ReportDefinition
{
	public const CAMPAIGNS = 'campaigns';

	public const SEARCH_TERMS = 'search_terms';

	public const KEYWORDS = 'keywords';

	public const CONVERSION_ACTIONS = 'conversion_actions';

	abstract public function type(): string;

	abstract public function label(): string;

	abstract public function description(): string;

	/**
	 * @return array<int, FieldDefinition>
	 */
	abstract public function fields(): array;

	/**
	 * Co najmniej jedno z tych pól musi być zmapowane (np. jakakolwiek metryka).
	 *
	 * @return array<int, string>
	 */
	public function requiresOneOf(): array
	{
		return ['impressions', 'clicks', 'cost'];
	}

	/**
	 * Wzorce tytułu raportu w pierwszych wierszach eksportu.
	 *
	 * @return array<int, string>
	 */
	public function titlePatterns(): array
	{
		return [];
	}

	/**
	 * Nagłówki wskazujące, że plik jest innym typem raportu (np. raport kampanii nie ma kolumny „Wyszukiwane hasło”).
	 *
	 * @return array<int, string>
	 */
	protected function foreignHeaders(): array
	{
		return [];
	}

	/**
	 * @param array<int, string> $normalizedHeaders
	 */
	public function conflictsWith(array $normalizedHeaders): bool
	{
		$foreign = array_map([HeaderNormalizer::class, 'key'], $this->foreignHeaders());

		return array_intersect($foreign, $normalizedHeaders) !== [];
	}

	/**
	 * Pole tekstowe identyfikujące wiersz (używane m.in. do rozpoznawania wierszy podsumowań).
	 */
	abstract public function primaryField(): string;

	public function field(string $key): ?FieldDefinition
	{
		foreach ($this->fields() as $field) {
			if ($field->key === $key) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Automatyczne mapowanie: pole → indeks kolumny.
	 *
	 * @param array<int, string|null> $headers
	 * @return array<string, int>
	 */
	public function guessMapping(array $headers): array
	{
		$normalized = array_map([HeaderNormalizer::class, 'key'], $headers);
		$mapping = [];
		$used = [];

		// Najpierw dokładne dopasowania, w kolejności aliasów (pierwszy alias jest najbardziej typowy).
		foreach ($this->fields() as $field) {
			foreach ($field->aliases as $alias) {
				foreach ($normalized as $index => $header) {
					if ($header === $alias && ! isset($used[$index])) {
						$mapping[$field->key] = $index;
						$used[$index] = true;
						continue 3;
					}
				}
			}
		}

		return $mapping;
	}

	/**
	 * @param array<string, int> $mapping
	 * @return array<int, string> brakujące wymagania (komunikaty)
	 */
	public function missingRequirements(array $mapping): array
	{
		$missing = [];

		foreach ($this->fields() as $field) {
			if ($field->required && ! isset($mapping[$field->key])) {
				$missing[] = "Brak wymaganej kolumny: {$field->label}.";
			}
		}

		$oneOf = $this->requiresOneOf();

		if ($oneOf !== [] && array_intersect($oneOf, array_keys($mapping)) === []) {
			$labels = array_map(fn ($key) => $this->field($key)?->label ?? $key, $oneOf);
			$missing[] = 'Zmapuj co najmniej jedną z kolumn: ' . implode(', ', $labels) . '.';
		}

		return $missing;
	}

	/**
	 * Ocena dopasowania nagłówków do typu raportu (do automatycznego rozpoznania).
	 *
	 * @param array<int, string|null> $headers
	 */
	public function score(array $headers, array $preambleRows = []): int
	{
		$mapping = $this->guessMapping($headers);
		$normalized = array_map([HeaderNormalizer::class, 'key'], $headers);

		if ($this->missingRequirements($mapping) !== [] || $this->conflictsWith($normalized)) {
			return 0;
		}

		$score = 0;

		foreach ($this->fields() as $field) {
			if (isset($mapping[$field->key])) {
				$score += $field->required ? 10 : 1;
			}
		}

		$preamble = mb_strtolower(implode(' ', array_map(fn ($row) => implode(' ', array_filter((array) $row)), $preambleRows)));

		foreach ($this->titlePatterns() as $pattern) {
			if (preg_match($pattern, $preamble)) {
				$score += 15;
			}
		}

		return $score;
	}
}
