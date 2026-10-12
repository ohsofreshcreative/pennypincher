<?php

namespace App\Support;

class SectionBackgrounds
{
	public static function choices(array $exclude = []): array
	{
		$choices = [
			'none' => 'Brak (domyślne)',
			'section-white' => 'Białe',
			'section-light' => 'Jasne',
			'section-gray' => 'Szare',
			'section-brand' => 'Marki',
			'section-gradient' => 'Gradient',
			'section-dark' => 'Ciemne',
		];

		return array_diff_key($choices, array_flip($exclude));
	}
}
