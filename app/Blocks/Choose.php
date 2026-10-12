<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Choose extends Block
{
	public $name = 'Porównanie kart';
	public $description = 'choose';
	public $slug = 'choose';
	public $category = 'formatting';
	public $icon = 'randomize';
	public $keywords = ['porownanie', 'kafelki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$choose = new FieldsBuilder('choose');

		$choose
			->setLocation('block', '==', 'acf/choose') // ważne!
			/*--- TAB #1 ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_choose', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->endGroup()

			/*--- TAB #2 ---*/
			->addTab('Karty', ['placement' => 'top'])
			->addRepeater('r_choose', [
				'label' => 'Karty',
				'layout' => 'block',
				'min' => 1,
				'button_label' => 'Dodaj kartę'
			])
			->addImage('image', [
				'label' => 'Zdjęcie',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('title', ['label' => 'Nagłówek'])
			->addTextarea('text', ['label' => 'Opis', 'rows' => 3])
			->addRepeater('r_features', [
				'label' => 'Lista cech',
				'layout' => 'table',
				'min' => 0,
				'button_label' => 'Dodaj pozycję'
			])
			->addText('title', ['label' => 'Tekst'])
			->endRepeater()
			->addLink('button', [
				'label' => 'Przycisk',
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

		return $choose;
	}

	public function with(): array
	{
		$fields = [
			'g_choose' => get_field('g_choose'),
			'r_choose' => get_field('r_choose') ?: [],

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
