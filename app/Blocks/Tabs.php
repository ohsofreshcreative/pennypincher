<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;

class Tabs extends Block
{
	public $name = 'Zakładki';
	public $description = 'tabs';
	public $slug = 'tabs';
	public $category = 'formatting';
	public $icon = 'table-row-after';
	public $keywords = ['tabs', 'kafelki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$tabs = new FieldsBuilder('tabs');

		$tabs
			->setLocation('block', '==', 'acf/tabs') // ważne!
			/*--- TAB #1 ---*/
			->addTab('Treści', ['placement' => 'top'])
			->addGroup('g_tabs', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 4,
				'new_lines' => 'br',
			])
			->addLink('button', [
				'label' => 'Przycisk',
				'return_format' => 'array',
			])
			->endGroup()

			/*--- TAB #2 ---*/
			->addTab('Kafelki', ['placement' => 'top'])
			->addRepeater('r_tabs', [
				'label' => 'Kafelki',
				'layout' => 'table', // 'row', 'block', albo 'table'
				'min' => 1,
				'button_label' => 'Dodaj kafelek'
			])
			->addText('tab', [
				'label' => 'Nazwa zakładki',
				'instructions' => 'Wpisz nazwę zakładki, do której ma trafić ten element (np. "Budownictwo"). Elementy o tej samej nazwie zostaną zgrupowane.',
				'required' => 1,
			])
			->addImage('image', [
				'label' => 'Obraz',
				'return_format' => 'array', // lub 'url', lub 'id'
				'preview_size' => 'medium',
			])
			->addText('title', [
				'label' => 'Nagłówek',
			])
			->addTextarea('text', [
				'label' => 'Opis',
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

		return $tabs;
	}

	public function with()
	{
		return [
			'g_tabs' => get_field('g_tabs'),
			'r_tabs' => get_field('r_tabs'),
			'section_id' => get_field('section_id'),
			'section_class' => get_field('section_class'),
			'flip' => get_field('flip'),
			'wide' => get_field('wide'),
			'nomt' => get_field('nomt'),
			'gap' => get_field('gap'),
			'background' => get_field('background'),
		];
	}
}
