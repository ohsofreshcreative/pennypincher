<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Catalogues extends Block
{
	public $name = 'Katalogi';
	public $description = 'catalogues';
	public $slug = 'catalogues';
	public $category = 'formatting';
	public $icon = 'open-folder';
	public $keywords = ['catalogues', 'kafelki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$catalogues = new FieldsBuilder('catalogues');

		$catalogues
			->setLocation('block', '==', 'acf/catalogues')

			/*--- TAB #2 ---*/
			->addTab('Grupy katalogów', ['placement' => 'top'])
			->addRepeater('r_catalogues', [
				'label' => 'Grupy katalogów',
				'layout' => 'block',
				'min' => 1,
				'button_label' => 'Dodaj grupę',
			])
			->addText('group_title', [
				'label' => 'Nagłówek grupy',
				'required' => 1,
				'instructions' => 'Np. "Katalogi producentów", "Instrukcje techniczne"',
			])
			->addRepeater('group_items', [
				'label' => 'Katalogi',
				'layout' => 'row',
				'min' => 1,
				'button_label' => 'Dodaj katalog',
			])
			->addFile('file', [
				'label' => 'Plik PDF',
				'return_format' => 'array',
				'mime_types' => 'pdf',
			])
			->addText('title', [
				'label' => 'Nazwa katalogu',
				'required' => 1,
			])
			->endRepeater()
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
			->addTrueFalse('mb', [
				'label' => 'Dodanie marginesu dolnego',
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

		return $catalogues;
	}

	 public function with(): array
    {
        $fields = [
            'g_catalogues' => get_field('g_catalogues'),
            'catalogues_header' => get_field('catalogues_header'),
            'r_catalogues' => get_field('r_catalogues'),
            'enable_filters' => get_field('enable_filters'),

            'section_id' => get_field('section_id'),
            'section_class' => get_field('section_class'),

            'flip' => (bool) get_field('flip'),
            'wide' => (bool) get_field('wide'),
            'nomt' => (bool) get_field('nomt'),
            'mb' => (bool) get_field('mb'),
            'gap' => (bool) get_field('gap'),
            'nolist' => (bool) get_field('nolist'),

            'background' => get_field('background') ?: 'none',
        ];

        // Ta linijka załatwia wygenerowanie wszystkich klas (w tym -smb dla mb) automatycznie!
        $fields['sectionClass'] = \App\Support\SectionClasses::fromMap($fields, [
            'flip' => 'order-flip',
            'wide' => 'wide',
            'nomt' => '!mt-0',
            'mb' => '-smb',
            'gap' => 'wider-gap',
            'nolist' => 'no-list',
        ]);

        return $fields;
    }
}
