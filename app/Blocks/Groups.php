<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Groups extends Block
{
	public $name = 'Grupy z filtrem';
	public $description = 'groups';
	public $slug = 'groups';
	public $category = 'formatting';
	public $icon = 'groups';
	public $keywords = ['grupy', 'filtr', 'kafelki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$groups = new FieldsBuilder('groups');

		$groups
			->setLocation('block', '==', 'acf/groups') // ważne!
			/*--- TAB #1 ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_groups', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->endGroup()

			/*--- TAB #2 ---*/
			->addTab('Zakładki', ['placement' => 'top'])
			->addRepeater('r_tabs', [
				'label' => 'Zakładki (filtr)',
				'layout' => 'table',
				'min' => 1,
				'button_label' => 'Dodaj zakładkę',
				'instructions' => 'Nazwa zakładki musi dokładnie odpowiadać polu "Zakładka" w kartach poniżej.',
			])
			->addText('label', ['label' => 'Nazwa'])
			->endRepeater()

			/*--- TAB #3 ---*/
			->addTab('Karty', ['placement' => 'top'])
			->addRepeater('r_cards', [
				'label' => 'Karty',
				'layout' => 'block',
				'min' => 1,
				'button_label' => 'Dodaj kartę'
			])
			->addText('tab', ['label' => 'Zakładka', 'instructions' => 'Np. Dzieci'])
			->addText('badge', ['label' => 'Etykieta', 'instructions' => 'Np. Poziom I'])
			->addText('title', ['label' => 'Nagłówek'])
			->addTextarea('text', ['label' => 'Opis', 'rows' => 3])
			->addText('meta_level', ['label' => 'Poziom'])
			->addText('meta_age', ['label' => 'Wiek'])
			->addText('meta_duration', ['label' => 'Czas zajęć'])
			->addLink('link', ['label' => 'Link (Więcej)', 'return_format' => 'array'])
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
			->addSelect('background', [
				'label' => 'Kolor tła',
				'choices' => SectionBackgrounds::choices(),
				'default_value' => 'none',
				'ui' => 0, // Ulepszony interfejs
				'allow_null' => 0,
			]);

		return $groups;
	}

	public function with(): array
	{
		$fields = [
			'g_groups' => get_field('g_groups'),
			'r_tabs' => get_field('r_tabs') ?: [],
			'r_cards' => get_field('r_cards') ?: [],

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
