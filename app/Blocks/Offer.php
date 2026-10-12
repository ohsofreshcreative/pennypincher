<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Offer extends Block
{
	public $name = 'Oferta - kafelki';
	public $description = 'offer';
	public $slug = 'offer';
	public $category = 'formatting';
	public $icon = 'slides';
	public $keywords = ['oferta', 'slider', 'kafelki'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$offer = new FieldsBuilder('offer');

		$offer
			->setLocation('block', '==', 'acf/offer') // ważne!
			/*--- FIELDS ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_offer', ['label' => ''])

			->addText('label', ['label' => 'Etykieta'])
			->addText('header', ['label' => 'Nagłówek'])

			->addMessage('Informacja', 'Kafelki pobierane są automatycznie z wpisów typu „Oferta” — tytuł, zajawka i obrazek wyróżniający. Kolejność ustawiasz atrybutem „Kolejność” w każdej ofercie.')

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

		return $offer;
	}

	public function with(): array
	{
		$query = new \WP_Query([
			'post_type' => 'offer',
			'post_parent' => 0,
			'posts_per_page' => -1,
			'orderby' => 'menu_order',
			'order' => 'ASC',
			'post_status' => 'publish',
		]);

		$items = [];

		foreach ($query->posts as $post) {
			$thumb_id = get_post_thumbnail_id($post->ID);

			$items[] = [
				'id' => $post->ID,
				'title' => $post->post_title,
				'excerpt' => get_the_excerpt($post),
				'url' => get_permalink($post->ID),
				'image_url' => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : null,
				'image_alt' => $thumb_id ? get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : '',
			];
		}

		wp_reset_postdata();

		$fields = [
			'g_offer' => get_field('g_offer'),
			'items' => $items,

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
