<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class Products extends Block
{
	public $name = 'Produkty';
	public $description = 'products';
	public $slug = 'products';
	public $category = 'formatting';
	public $icon = 'screenoptions';
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
		$products = new FieldsBuilder('products');

		$products
			->setLocation('block', '==', 'acf/products') // ważne!
			/*--- GROUP ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_products', ['label' => ''])
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

		return $products;
	}

	public function with(): array
	{
		$parent_id = get_the_ID();
		$children_query = new \WP_Query([
			'post_type'      => 'offer',
			'post_parent'    => $parent_id,
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'post_status'    => 'publish',
		]);

		$offer_children = [];
		$used_categories = [];

		foreach ($children_query->posts as $post) {
			$thumb_id = get_post_thumbnail_id($post->ID);
			$terms    = get_the_terms($post->ID, 'offer_category');
			$post_cats = [];

			if ($terms && !is_wp_error($terms)) {
				foreach ($terms as $term) {
					$post_cats[] = $term->slug;
					$used_categories[$term->slug] = $term->name;
				}
			}

			$offer_children[] = [
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'url'        => get_permalink($post->ID),
				'image_url'  => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : null,
				'image_alt'  => $thumb_id ? get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : '',
				'categories' => $post_cats,
			];
		}
		wp_reset_postdata();

		$fields = [
			'g_products'     => get_field('g_products'),
			'offer_children' => $offer_children,
			'categories'     => $used_categories,

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
