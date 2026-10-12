<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Contact extends Block

{
	public $name = 'Kontakt';
	public $description = 'Contact';
	public $slug = 'contact';
	public $category = 'formatting';
	public $icon = 'email';
	public $keywords = ['formularz', 'kontakt'];
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
		$contact = new FieldsBuilder('contact');

		$contact
			->setLocation('block', '==', 'acf/contact') // ważne!
			/*--- FIELDS ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_contact', ['label' => ''])
			->addImage('image', [
				'label' => 'Zdjęcie',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('header', ['label' => 'Nagłówek'])
			->addText('subheader', ['label' => 'Podtytuł'])
			->addTextarea('address', [
				'label' => 'Adres',
				'rows' => 2,
				'new_lines' => 'br',
			])
			->addText('phone', [
				'label' => 'Telefon komórkowy',
			])
			->addText('phone2', [
				'label' => 'Telefon stacjonarny',
			])
			->addText('mail', [
				'label' => 'Adres e-mail',
			])
			->addText('hours', [
				'label' => 'Godziny otwarcia',
			])
			->addLink('button', [
				'label' => 'Przycisk',
				'return_format' => 'array',
			])

			->addRepeater('r_contact', [
				'label' => 'Działy',
				'layout' => 'table',
				'min' => 0,
				'max' => 6,
				'button_label' => 'Dodaj dział',
			])
			->addText('header', ['label' => 'Nazwa działu'])
			->addText('phone', ['label' => 'Telefon'])
			->addText('mail', ['label' => 'Adres e-mail'])
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
				'ui' => 0,
				'allow_null' => 0,
			]);


		return $contact;
	}

	public function with(): array
	{
		$fields = [
			'g_contact' => get_field('g_contact'),
			'departments' => get_field('g_contact')['r_contact'] ?? [],

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
