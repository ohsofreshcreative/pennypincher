<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Possibilities extends Block
{
	public $name = 'Możliwości obiektu';
	public $description = 'possibilities';
	public $slug = 'possibilities';
	public $category = 'formatting';
	public $icon = 'list-view';
	public $keywords = ['lista', 'zdjecie'];
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
		$possibilities = new FieldsBuilder('possibilities');

		$possibilities
			->setLocation('block', '==', 'acf/possibilities') // ważne!
			/*--- FIELDS ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_possibilities', ['label' => ''])
			->addImage('image', [
				'label' => 'Domyślne zdjęcie',
				'instructions' => 'Wyświetlane przy kafelkach bez własnego zdjęcia.',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])

			->addRepeater('r_possibilities', [
				'label' => 'Punkty',
				'layout' => 'table',
				'min' => 0,
				'max' => 8,
				'button_label' => 'Dodaj punkt',
			])
			->addImage('image', [
				'label' => 'Zdjęcie kafelka',
				'instructions' => 'Pokazywane po kliknięciu kafelka i podczas przewijania strony.',
				'return_format' => 'array',
				'preview_size' => 'thumbnail',
			])
			->addText('header', ['label' => 'Nagłówek'])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 3,
				'new_lines' => 'br',
			])
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

		return $possibilities;
	}

	public function with(): array
	{
		$group = get_field('g_possibilities') ?: [];
		$points = $group['r_possibilities'] ?? [];
		$defaultImage = $group['image'] ?? null;

		if (empty($defaultImage['url'])) {
			foreach ($points ?: [] as $point) {
				if (!empty($point['image']['url'])) {
					$defaultImage = $point['image'];
					break;
				}
			}
		}

		$points = array_map(static function (array $point) use ($defaultImage): array {
			$point['image'] = !empty($point['image']['url']) ? $point['image'] : $defaultImage;

			return $point;
		}, $points ?: []);

		$fields = [
			'g_possibilities' => $group,
			'points' => $points,
			'default_image' => $defaultImage,
			'instance_id' => wp_unique_id('possibilities-'),

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
