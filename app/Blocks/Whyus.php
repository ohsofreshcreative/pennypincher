<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Whyus extends Block
{
	public $name = 'Dlaczego my?';
	public $description = 'whyus';
	public $slug = 'whyus';
	public $category = 'formatting';
	public $icon = 'align-pull-left';
	public $keywords = ['tresc', 'zdjecie'];
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
		$whyus = new FieldsBuilder('whyus');

		$whyus
			->setLocation('block', '==', 'acf/whyus') // ważne!
			/*--- TAB #1: Kafelek 1 (Ikona + Tytuł) ---*/
			->addTab('Kafelek 1 (Ikona)', ['placement' => 'top'])
			->addGroup('tile_1', ['label' => 'Pierwszy Kafelek (Jasnoniebieski)'])
			->addImage('icon', [
				'label' => 'Ikona',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('title', ['label' => 'Tytuł'])
			->endGroup()

			/*--- TAB #2: Kafelek 2 (Zdjęcie w tle + Tekst) ---*/
			->addTab('Kafelek 2 (Tło foto)', ['placement' => 'top'])
			->addGroup('tile_2', ['label' => 'Drugi Kafelek (Zdjęcie w tle)'])
			->addImage('image', [
				'label' => 'Zdjęcie w tle',
				'return_format' => 'array',
			])
			->addText('header', ['label' => 'Duży nagłówek (np. 19 lat)'])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 2,
			])
			->endGroup()

			/*--- TAB #3: Kafelek 3 (Duża statystyka) ---*/
			->addTab('Kafelek 3 (Statystyka)', ['placement' => 'top'])
			->addGroup('tile_3', ['label' => 'Trzeci Kafelek (Ciemnoniebieska Statystyka)'])
			->addText('stat', ['label' => 'Główna wartość (np. 40+)'])
			->addText('label_top', ['label' => 'Rozwinięcie na górze (np. dostaw miesięcznie)'])
			->addText('label_bottom', ['label' => 'Podpis na dole (np. realizowanych dla różnych...)'])
			->endGroup()

			/*--- TAB #4: Kafelek 4 (Opinia / Testimonial) ---*/
			->addTab('Kafelek 4 (Opinia)', ['placement' => 'top'])
			->addGroup('tile_4', ['label' => 'Czwarty Kafelek (Opinia Klienta)'])
			->addTextarea('quote', [
				'label' => 'Treść opinii',
				'rows' => 3,
			])
			->addText('author_name', ['label' => 'Imię i nazwisko autora'])
			->addText('author_role', ['label' => 'Stanowisko / Firma'])
			->addSelect('rating', [
				'label' => 'Ocena (Gwiazdki)',
				'choices' => [
					'5.0' => '5.0',
					'4.5' => '4.5',
					'4.0' => '4.0',
				],
				'default_value' => '5.0',
			])
			->endGroup()

			/*--- TAB #5: Kafelek 5 (Wysokie foto + karta nakładana) ---*/
			->addTab('Kafelek 5 (Wysokie foto)', ['placement' => 'top'])
			->addGroup('tile_5', ['label' => 'Piąty Kafelek (Wysoki z ciężarówką)'])
			->addImage('image', [
				'label' => 'Główne wysokie zdjęcie',
				'return_format' => 'array',
			])
			->addText('card_title', ['label' => 'Tytuł karty nakładanej (np. Produkty premium...)'])
			->endGroup()

			/*--- USTAWIENIA BLOKU ---*/

			->addTab('Ustawienia bloku', ['placement' => 'top'])
			->addText('section_id', [
				'label' => 'ID',
			])
			->addText('section_class', [
				'label' => 'Dodatkowe klasy CSS',
			])
			->addTrueFalse('nolist', [
				'label' => 'Brak punktatorów',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
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

		return $whyus;
	}

	public function with(): array
	{
		$fields = [
			'g_whyus' => get_field('g_whyus'), 
            'tile_1'      => get_field('tile_1'),
            'tile_2'      => get_field('tile_2'),
            'tile_3'      => get_field('tile_3'),
            'tile_4'      => get_field('tile_4'),
            'tile_5'      => get_field('tile_5'),

			'section_id' => get_field('section_id'),
			'section_class' => get_field('section_class'),

			'flip' => (bool) get_field('flip'),
			'wide' => (bool) get_field('wide'),
			'nomt' => (bool) get_field('nomt'),
			'gap' => (bool) get_field('gap'),
			'nolist' => (bool) get_field('nolist'),

			'background' => get_field('background') ?: 'none',
		];

		$fields['sectionClass'] = SectionClasses::fromMap($fields, [
			'flip' => 'order-flip',
			'wide' => 'wide',
			'nomt' => '!mt-0',
			'gap' => 'wider-gap',
			'nolist' => 'no-list',
		]);

		return $fields;
	}
}
