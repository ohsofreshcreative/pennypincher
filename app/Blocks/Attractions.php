<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Attractions extends Block
{
	public $name = 'Atrakcje';
	public $description = 'attractions';
	public $slug = 'attractions';
	public $category = 'formatting';
	public $icon = 'images-alt2';
	public $keywords = ['atrakcje', 'kafelki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$attractions = new FieldsBuilder('attractions');

		$attractions
			->setLocation('block', '==', 'acf/attractions') // ważne!
			/*--- FIELDS ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_attractions', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])

			->addRepeater('r_attractions', [
				'label' => 'Atrakcje',
				'layout' => 'block',
				'min' => 1,
				'button_label' => 'Dodaj atrakcję'
			])
			->addImage('image', [
				'label' => 'Zdjęcie',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('title', [
				'label' => 'Nazwa',
			])
			->addWysiwyg('text', [
				'label' => 'Parametry',
				'tabs' => 'all',
				'toolbar' => 'basic',
				'media_upload' => false,
			])
			->addLink('link', [
				'label' => 'Link w popupie',
				'instructions' => 'Opcjonalny link na dole popupu, np. Warunki korzystania. Kliknięcie kafelka otwiera popup.',
				'return_format' => 'array',
			])
			->addGroup('popup', ['label' => 'Popup'])
			->addImage('image', [
				'label' => 'Zdjęcie w popupie',
				'instructions' => 'Opcjonalne. Jeśli puste, wyświetli się zdjęcie z kafelka.',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addWysiwyg('text', [
				'label' => 'Rozszerzony opis',
				'instructions' => 'Jeśli puste, wyświetli się krótki opis z kafelka.',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->addRepeater('parameters', [
				'label' => 'Parametry z ikonami',
				'layout' => 'row',
				'button_label' => 'Dodaj parametr',
			])
			->addImage('image', [
				'label' => 'Ikona',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('header', [
				'label' => 'Nazwa parametru',
			])
			->addText('value', [
				'label' => 'Wartość',
			])
			->endRepeater()
			->endGroup()
			->endRepeater()

			->endGroup()

			/*--- USTAWIENIA BLOKU ---*/

			->addTab('Ustawienia bloku', ['placement' => 'top'])
			->addText('section_id', [
				'label' => 'ID',
			])
			->addText('section_class', [
				'label' => 'Dodatkowe klasy CSS',
			])
			->addTrueFalse('wide', [
				'label' => 'Szeroka kolumna',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])
			->addTrueFalse('nomt', [
				'label' => 'Usunięcie marginesu górnego',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])
			->addTrueFalse('gap', [
				'label' => 'Większy odstęp',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])
			->addSelect('background', [
				'label' => 'Kolor tła',
				'choices' => SectionBackgrounds::choices(),
				'default_value' => 'none',
				'ui' => 0, // Ulepszony interfejs
				'allow_null' => 0,
			]);

		return $attractions;
	}

	public function with(): array
	{
		$fields = [
			'g_attractions' => get_field('g_attractions'),
			'attractions' => get_field('g_attractions')['r_attractions'] ?? [],

			'section_id' => get_field('section_id'),
			'section_class' => get_field('section_class'),

			'wide' => (bool) get_field('wide'),
			'nomt' => (bool) get_field('nomt'),
			'gap' => (bool) get_field('gap'),

			'background' => get_field('background') ?: 'none',
		];

		$fields['attractions'] = array_map(static function (array $item): array {
			$popup = $item['popup'] ?? [];
			$item['popup_id'] = wp_unique_id('attraction-');
			$item['popup_image'] = ($popup['image'] ?? null) ?: ($item['image'] ?? null);
			$item['popup_text'] = ($popup['text'] ?? '') ?: ($item['text'] ?? '');
			$item['popup_parameters'] = array_filter(($popup['parameters'] ?? []) ?: [], static function (array $parameter): bool {
				return filled($parameter['header'] ?? '') || filled($parameter['value'] ?? '');
			});

			return $item;
		}, $fields['attractions']);

		$fields['sectionClass'] = SectionClasses::fromMap($fields, [
			'wide' => 'wide',
			'nomt' => '!mt-0',
			'gap' => 'wider-gap',
		]);

		return $fields;
	}
}
