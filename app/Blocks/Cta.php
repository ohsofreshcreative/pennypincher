<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Cta extends Block
{
	public $name = 'Wezwanie do działania';
	public $description = 'cta';
	public $slug = 'cta';
	public $category = 'formatting';
	public $icon = 'button';
	public $keywords = ['cta'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$cta = new FieldsBuilder('cta');

		$cta
			->setLocation('block', '==', 'acf/cta') // ważne!
			/*--- FIELDS ---*/
			->addTab('Treść', ['placement' => 'top'])
			->addMessage('Edycja', 'Tę zawartość edytujemy klikając w menu panelu administratora „Wezwanie do działania”.')
			->addTrueFalse('form', [
				'label' => 'Pokaż formularz',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])
			->addTrueFalse('content', [
				'label' => 'Inna treść',
				'instructions' => 'Włącz, aby nadpisać treść z ustawień globalnych',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])
			->addText('header', [
				'label' => 'Nagłówek',
				'conditional_logic' => [[['field' => 'content', 'operator' => '==', 'value' => '1']]],
			])
			->addWysiwyg('txt', [
				'label' => 'Treść',
				'tabs' => 'visual',
				'toolbar' => 'basic',
				'media_upload' => false,
				'conditional_logic' => [[['field' => 'content', 'operator' => '==', 'value' => '1']]],
			])

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

		return $cta;
	}

	public function with(): array
	{
		$g_octa = get_field('g_octa', 'option');

		if ((bool) get_field('content')) {
			if ($header = get_field('header')) {
				$g_octa['header'] = $header;
			}
			if ($txt = get_field('txt')) {
				$g_octa['txt'] = $txt;
			}
		}

		$fields = [
			'g_octa' => $g_octa,
			'form' => (bool) get_field('form'),

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
