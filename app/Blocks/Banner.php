<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Banner extends Block
{
	public $name = 'Hero - Podstrona';
	public $description = 'banner - hero dla podstron';
	public $slug = 'banner';
	public $category = 'formatting';
	public $icon = 'align-full-width';
	public $keywords = ['tresc', 'zdjecie', 'podstrona', 'hero'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$banner = new FieldsBuilder('banner');

		$banner
			->setLocation('block', '==', 'acf/banner') // ważne!
			/*--- TAB #1 ---*/
			->addTab('Treść', ['placement' => 'top'])
			->addGroup('g_banner', ['label' => 'banner'])
			->addText('title', ['label' => 'Tytuł'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'instructions' => 'Opcjonalny opis pod tytułem.',
				'tabs' => 'all', // 'visual', 'text', 'all'
				'toolbar' => 'full', // 'basic', 'full'
				'media_upload' => true,
			])
			->endGroup()

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
				'default_value' => 'section-gradient',
				'ui' => 0, // Ulepszony interfejs
				'allow_null' => 0,
			]);

		return $banner;
	}

	public function with(): array
	{
		$fields = [
			'g_banner' => get_field('g_banner'),

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
