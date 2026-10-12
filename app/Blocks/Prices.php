<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Prices extends Block
{
	public $name = 'Cennik';
	public $description = 'prices';
	public $slug = 'prices';
	public $category = 'formatting';
	public $icon = 'money-alt';
	public $keywords = ['cennik', 'ceny', 'zakladki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$prices = new FieldsBuilder('prices');

		$prices
			->setLocation('block', '==', 'acf/prices') // ważne!
			/*--- FIELDS ---*/
			->addTab('Treści', ['placement' => 'top'])
			->addMessage('Edycja', 'Tę zawartość edytujemy klikając w menu panelu administratora "Cennik".')
			->addCheckbox('visible_tabs', [
				'label' => 'Widoczne zakładki',
				'instructions' => 'Odznacz zakładki, które nie mają się pojawiać w tym bloku (np. "Basen" na podstronie siłowni).',
				'layout' => 'vertical',
			])

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

		// Lista zakładek musi być czytana z r_price_tabs dopiero przy wyświetlaniu pola w adminie —
		// podczas rejestracji fields() strona opcji "Cennik" może jeszcze nie być zarejestrowana.
		add_filter('acf/load_field/name=visible_tabs', function ($field) {
			$choices = self::tabChoices();
			$field['choices'] = $choices;
			$field['default_value'] = array_keys($choices);

			return $field;
		});

		return $prices;
	}

	/**
	 * Zakładki do wyboru pobrane ze strony opcji "Cennik" — indeks pozycji w r_price_tabs.
	 */
	private static function tabChoices(): array
	{
		$tabs = get_field('r_price_tabs', 'option') ?: [];

		$choices = [];
		foreach ($tabs as $index => $tab) {
			$choices[$index] = $tab['tab'] ?: sprintf('Zakładka %d', $index + 1);
		}

		return $choices ?: [0 => 'Uzupełnij zakładki w Cennik → Ustawienia'];
	}

	public function with(): array
	{
		$tabs = get_field('r_price_tabs', 'option') ?: [];
		$visibleTabs = get_field('visible_tabs');

		if (is_array($visibleTabs)) {
			$tabs = array_values(array_filter(
				$tabs,
				fn ($tab, $index) => in_array((string) $index, $visibleTabs, true),
				ARRAY_FILTER_USE_BOTH
			));
		}

		$fields = [
			'r_price_tabs' => $tabs,
			'groups_header' => get_field('groups_header', 'option'),
			'r_groups' => get_field('r_groups', 'option') ?: [],

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

	public function enqueue()
	{
		// Pozostaw tę metodę pustą.
	}
}
