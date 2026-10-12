<?php

namespace App\Fields;

use Log1x\AcfComposer\Field;
use StoutLogic\AcfBuilder\FieldsBuilder;

class PostCategory extends Field
{
	/**
	 * Definiuje grupę pól dla kategorii wpisów.
	 *
	 * @return array
	 */
	public function fields(): array
	{
		$postCategory = new FieldsBuilder('post_category_fields', [
			'title' => 'Ustawienia kategorii',
			'style' => 'seamless',
			'position' => 'normal',
		]);

		$postCategory
			// Tutaj jest kluczowa zmiana - celujemy w standardowe kategorie wpisów
			->setLocation('taxonomy', '==', 'category')
			->or('taxonomy', '==', 'team_category');

		$postCategory
			->addPageLink('linked_page', [
				'label' => 'Zastąp stronę kategorii',
				'instructions' => 'Wybierz stronę (utworzoną w zakładce "Strony"), która ma zostać wyświetlona zamiast domyślnej listy wpisów dla tej kategorii. Jeśli nic nie wybierzesz, wyświetli się standardowe archiwum.',
				'post_type' => ['page', 'team', 'offer'],
				'allow_null' => 1,
				'multiple' => 0,
				'allow_archives' => 0,
			]);

		return [$postCategory];
	}
}
