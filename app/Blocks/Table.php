<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Table extends Block
{
	public $name = 'Tabela';
	public $description = 'table';
	public $slug = 'table';
	public $category = 'formatting';
	public $icon = 'editor-table';
	public $keywords = ['table', 'tabela', 'tresc', 'tekst', 'kolumny', 'wiersze'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$table = new FieldsBuilder('table');

		$table
			->setLocation('block', '==', 'acf/table') // ważne!
			/*--- TAB #1 ---*/
			->addTab('Treści', ['placement' => 'top'])
			->addGroup('g_table', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 4,
				'new_lines' => 'br',
			])
			->endGroup()

			/*--- TAB #2 ---*/
			->addTab('Nagłówki kolumn', ['placement' => 'top'])
			->addGroup('g_table_cols', ['label' => 'Nagłówki kolumn'])
			->addText('col1', ['label' => 'Kolumna 1', 'default_value' => 'Model'])
			->addText('col2', ['label' => 'Kolumna 2', 'default_value' => 'Wydajność'])
			->addText('col3', ['label' => 'Kolumna 3', 'default_value' => 'Napęd / silnik'])
			->addText('col4', ['label' => 'Kolumna 4', 'default_value' => 'Grubość wirnika'])
			->endGroup()

			/*--- TAB #3 ---*/
			->addTab('Wiersze', ['placement' => 'top'])
			->addRepeater('r_table', [
				'label' => 'Wiersze tabeli',
				'layout' => 'table',
				'min' => 1,
				'button_label' => 'Dodaj wiersz'
			])
			->addText('col1', ['label' => 'Kolumna 1'])
			->addText('col2', ['label' => 'Kolumna 2'])
			->addText('col3', ['label' => 'Kolumna 3'])
			->addText('col4', ['label' => 'Kolumna 4'])
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

		return $table;
	}

	public function with(): array
	{
		$fields = [
			'g_table' => get_field('g_table'),
			'g_table_cols' => get_field('g_table_cols'),
			'r_table' => get_field('r_table'),

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
