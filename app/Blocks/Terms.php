<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Terms extends Block
{
	public $name = 'Zasady i regulaminy';
	public $description = 'terms';
	public $slug = 'terms';
	public $category = 'formatting';
	public $icon = 'media-document';
	public $keywords = ['regulamin', 'zasady', 'pdf'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$terms = new FieldsBuilder('terms');

		$terms
			->setLocation('block', '==', 'acf/terms') // ważne!
			/*--- FIELDS ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_terms', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])

			->addRepeater('r_terms', [
				'label' => 'Regulaminy',
				'layout' => 'table', // 'row', 'block', albo 'table'
				'min' => 1,
				'button_label' => 'Dodaj regulamin'
			])
			->addText('title', [
				'label' => 'Nazwa',
			])
			->addFile('file', [
				'label' => 'Plik PDF',
				'return_format' => 'array',
				'mime_types' => 'pdf',
			])
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
			->addSelect('background', [
				'label' => 'Kolor tła',
				'choices' => SectionBackgrounds::choices(),
				'default_value' => 'none',
				'ui' => 0, // Ulepszony interfejs
				'allow_null' => 0,
			]);

		return $terms;
	}

	public function with(): array
	{
		$fields = [
			'g_terms' => get_field('g_terms'),
			'terms' => get_field('g_terms')['r_terms'] ?? [],

			'section_id' => get_field('section_id'),
			'section_class' => get_field('section_class'),

			'wide' => (bool) get_field('wide'),
			'nomt' => (bool) get_field('nomt'),

			'background' => get_field('background') ?: 'none',
		];

		$fields['sectionClass'] = SectionClasses::fromMap($fields, [
			'wide' => 'wide',
			'nomt' => '!mt-0',
		]);

		return $fields;
	}
}
