<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Offers extends Block
{
	public $name = 'Oferta';
	public $description = 'offers';
	public $slug = 'offers';
	public $category = 'formatting';
	public $icon = 'screenoptions';
	public $keywords = ['tresc', 'zdjecie', 'oferta'];
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
		$offers = new FieldsBuilder('offers');

		$offers
			->setLocation('block', '==', 'acf/offers') // ważne!
			/*--- GROUP ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_offers', ['label' => ''])
			->addMessage('Informacja', 'Ten blok automatycznie wyświetla podstrony oferty przypisane do bieżącej strony nadrzędnej. Aby zarządzać elementami, przejdź do sekcji „Oferta" w panelu administratora i dodaj lub edytuj podstrony podrzędne.')
			->endGroup()

			/*--- USTAWIENIA BLOKU ---*/

			->addTab('Ustawienia bloku', ['placement' => 'top'])
			->addText('section_id', [
				'label' => 'ID',
			])
			->addText('section_class', [
				'label' => 'Dodatkowe klasy CSS',
			])
			->addTrueFalse('bgshape', [
				'label' => 'Kształt w tle',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
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
				'choices' => SectionBackgrounds::choices(['section-gray']),
				'default_value' => 'none',
				'ui' => 0, // Ulepszony interfejs
				'allow_null' => 0,
			]);

		return $offers;
	}

	public function with(): array
	{
		$offers_query = new \WP_Query([
			'post_type'      => 'offer',
			'post_parent'    => 0,
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'post_status'    => 'publish',
		]);

		$offer_items = [];
		foreach ($offers_query->posts as $post) {
			$thumb_id   = get_post_thumbnail_id($post->ID);
			$icon       = get_field('offer_icon', $post->ID);
			$offer_items[] = [
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'excerpt'   => get_the_excerpt($post),
				'url'       => get_permalink($post->ID),
				'image_url' => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : null,
				'image_alt' => $thumb_id ? get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : '',
				'icon_url'  => $icon['url'] ?? null,
				'icon_alt'  => $icon['alt'] ?? '',
			];
		}
		wp_reset_postdata();

		$fields = [
			'g_offers'    => get_field('g_offers'),
			'offer_items' => $offer_items,

			'section_id'   => get_field('section_id'),
			'section_class' => get_field('section_class'),

			'bgshape' => (bool) get_field('bgshape'),
			'flip'    => (bool) get_field('flip'),
			'wide'    => (bool) get_field('wide'),
			'nomt'    => (bool) get_field('nomt'),
			'gap'     => (bool) get_field('gap'),
			'nolist'  => (bool) get_field('nolist'),

			'background' => get_field('background') ?: 'none',
		];

		$fields['sectionClass'] = SectionClasses::fromMap($fields, [
			'flip'   => 'order-flip',
			'wide'   => 'wide',
			'nomt'   => '!mt-0',
			'gap'    => 'wider-gap',
			'nolist' => 'no-list',
		]);

		return $fields;
	}
}
