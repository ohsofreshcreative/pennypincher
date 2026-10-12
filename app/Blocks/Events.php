<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Events extends Block
{
	public $name = 'Wydarzenia';
	public $description = 'events';
	public $slug = 'events';
	public $category = 'formatting';
	public $icon = 'calendar-alt';
	public $keywords = ['wydarzenia', 'seanse', 'zdjecia'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$events = new FieldsBuilder('events');

		$events
			->setLocation('block', '==', 'acf/events') // ważne!
			/*--- TAB #1 ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_events', ['label' => ''])
			->addImage('image1', [
				'label' => 'Zdjęcie 1',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addImage('image2', [
				'label' => 'Zdjęcie 2',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addImage('image3', [
				'label' => 'Zdjęcie 3',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->addLink('button', [
				'label' => 'Przycisk',
				'return_format' => 'array',
			])
			->endGroup()

			/*--- TAB #2 ---*/
			->addTab('Karty', ['placement' => 'top'])
			->addRepeater('r_events', [
				'label' => 'Karty',
				'layout' => 'table', // 'row', 'block', albo 'table'
				'min' => 1,
				'button_label' => 'Dodaj kartę'
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

		return $events;
	}

	public function with(): array
	{
		$fields = [
			'g_events' => get_field('g_events'),
			'r_events' => get_field('r_events') ?: [],

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
