<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Submenu extends Block
{
	public $name = 'Podmenu';
	public $description = 'submenu';
	public $slug = 'submenu';
	public $category = 'formatting';
	public $icon = 'menu-alt3';
	public $keywords = ['menu', 'nawigacja', 'podmenu'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$submenu = new FieldsBuilder('submenu');

		$submenu
			->setLocation('block', '==', 'acf/submenu') // ważne!
			/*--- TAB #1 ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addRepeater('r_submenu', [
				'label' => 'Pozycje menu',
				'layout' => 'table', // 'row', 'block', albo 'table'
				'min' => 1,
				'button_label' => 'Dodaj pozycję'
			])
			->addLink('link', [
				'label' => 'Link',
				'return_format' => 'array',
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
			->addSelect('background', [
				'label' => 'Kolor tła',
				'choices' => SectionBackgrounds::choices(),
				'default_value' => 'none',
				'ui' => 0, // Ulepszony interfejs
				'allow_null' => 0,
			]);

		return $submenu;
	}

	public function with(): array
	{
		$fields = [
			'r_submenu' => get_field('r_submenu') ?: [],

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
