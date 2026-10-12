<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class How extends Block
{
	public $name = 'Jak wyglądają zajęcia';
	public $description = 'how';
	public $slug = 'how';
	public $category = 'formatting';
	public $icon = 'list-view';
	public $keywords = ['zajecia', 'kroki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
		'anchor' => true,
		'customClassName' => true,
	];

	public function fields()
	{
		$how = new FieldsBuilder('how');

		$how
			->setLocation('block', '==', 'acf/how') // ważne!
			/*--- FIELDS ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_how', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])

			->addRepeater('r_how', [
				'label' => 'Kroki',
				'layout' => 'table',
				'min' => 0,
				'max' => 4,
				'button_label' => 'Dodaj krok',
			])
			->addImage('image', [
				'label' => 'Zdjęcie',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('number', ['label' => 'Numer', 'placeholder' => '01.'])
			->addText('title', ['label' => 'Tytuł'])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 3,
				'new_lines' => 'br',
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
				'ui' => 0,
				'allow_null' => 0,
			]);

		return $how;
	}

	public function with(): array
	{
		$fields = [
			'g_how' => get_field('g_how'),
			'steps' => get_field('g_how')['r_how'] ?? [],

			'section_id' => get_field('section_id'),
			'section_class' => get_field('section_class'),

			'wide' => (bool) get_field('wide'),
			'nomt' => (bool) get_field('nomt'),
			'gap' => (bool) get_field('gap'),

			'background' => get_field('background') ?: 'none',
		];

		$fields['sectionClass'] = SectionClasses::fromMap($fields, [
			'wide' => 'wide',
			'nomt' => '!mt-0',
			'gap' => 'wider-gap',
		]);

		return $fields;
	}
}
