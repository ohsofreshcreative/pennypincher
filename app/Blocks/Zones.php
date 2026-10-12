<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Zones extends Block
{
	public $name = 'Strefy treningowe';
	public $description = 'zones';
	public $slug = 'zones';
	public $category = 'formatting';
	public $icon = 'screenoptions';
	public $keywords = ['strefy', 'kafelki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$zones = new FieldsBuilder('zones');

		$zones
			->setLocation('block', '==', 'acf/zones') // ważne!
			/*--- TAB #1 ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_zones', ['label' => ''])
			->addText('label', ['label' => 'Etykieta nad nagłówkiem'])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->addRepeater('r_features', [
				'label' => 'Lista cech',
				'layout' => 'table',
				'min' => 0,
				'button_label' => 'Dodaj pozycję'
			])
			->addText('title', [
				'label' => 'Tekst',
			])
			->endRepeater()
			->endGroup()

			/*--- TAB #2 ---*/
			->addTab('Strefy', ['placement' => 'top'])
			->addRepeater('r_zones', [
				'label' => 'Strefy',
				'layout' => 'table', // 'row', 'block', albo 'table'
				'min' => 1,
				'button_label' => 'Dodaj strefę'
			])
			->addImage('icon', [
				'label' => 'Ikonka',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('title', [
				'label' => 'Nagłówek',
			])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 3,
			])
			->endRepeater()

			/*--- USTAWIENIA BLOKU ---*/

			->addTab('Ustawienia bloku', ['placement' => 'top'])
			->addText('section_id', [
				'label' => 'ID',
			])
			->addText('section_class', [
				'label' => 'Dodatkowe klasy CSS',
			])
			->addTrueFalse('flip', [
				'label' => 'Odwrotna kolejność',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
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

		return $zones;
	}

	public function with(): array
	{
		$fields = [
			'g_zones' => get_field('g_zones'),
			'features' => get_field('g_zones')['r_features'] ?? [],
			'r_zones' => get_field('r_zones') ?: [],

			'section_id' => get_field('section_id'),
			'section_class' => get_field('section_class'),

			'flip' => (bool) get_field('flip'),
			'wide' => (bool) get_field('wide'),
			'nomt' => (bool) get_field('nomt'),
			'gap' => (bool) get_field('gap'),

			'background' => get_field('background') ?: 'none',
		];

		$fields['sectionClass'] = SectionClasses::fromMap($fields, [
			'flip' => 'order-flip',
			'wide' => 'wide',
			'nomt' => '!mt-0',
			'gap' => 'wider-gap',
		]);

		return $fields;
	}
}
