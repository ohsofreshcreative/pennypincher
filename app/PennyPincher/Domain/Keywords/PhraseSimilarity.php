<?php

namespace App\PennyPincher\Domain\Keywords;

/**
 * Podobieństwo znaczeniowe fraz – używane przez klastrowanie. Obecna implementacja jest leksykalna (słownik pojęć),
 * a interfejs pozwala później podłączyć model semantyczny (np. embeddingi) bez zmian w `KeywordClusterer`.
 */
interface PhraseSimilarity
{
	/**
	 * Reprezentacja frazy (dowolna struktura porównywalna przez similarity()).
	 */
	public function signature(string $phrase): array;

	/**
	 * Podobieństwo 0–1 dwóch reprezentacji.
	 */
	public function similarity(array $a, array $b): float;

	/**
	 * Czy dwie frazy mogą być w jednym klastrze niezależnie od wyniku similarity() (np. różne modyfikatory intencji).
	 */
	public function compatible(array $a, array $b): bool;
}
