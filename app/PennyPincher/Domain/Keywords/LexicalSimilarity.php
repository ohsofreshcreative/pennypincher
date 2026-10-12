<?php

namespace App\PennyPincher\Domain\Keywords;

/**
 * Deterministyczne podobieństwo fraz na pojęciach ze słownika synonimów (`Concepts`): Jaccard zbiorów pojęć
 * bez modyfikatorów; frazy z różnymi modyfikatorami (np. „cena”, „za darmo”, lokalizacja) nie są łączone.
 */
final class LexicalSimilarity implements PhraseSimilarity
{
	public function signature(string $phrase): array
	{
		$concepts = Concepts::of($phrase);

		return [
			'core' => array_values(array_diff($concepts, Concepts::MODIFIERS)),
			'modifiers' => array_values(array_intersect($concepts, Concepts::MODIFIERS)),
		];
	}

	public function similarity(array $a, array $b): float
	{
		$union = array_unique([...$a['core'], ...$b['core']]);

		if ($union === []) {
			return 0.0;
		}

		return count(array_intersect($a['core'], $b['core'])) / count($union);
	}

	public function compatible(array $a, array $b): bool
	{
		$ma = $a['modifiers'];
		$mb = $b['modifiers'];
		sort($ma);
		sort($mb);

		return $ma === $mb;
	}
}
