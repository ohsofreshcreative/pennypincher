<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;
use App\Support\SectionBackgrounds;
use App\Support\SectionClasses;

class News extends Block
{
	public $name = 'Aktualności';
	public $description = 'news';
	public $slug = 'news';
	public $category = 'formatting';
	public $icon = 'megaphone';
	public $keywords = ['aktualnosci', 'wpisy', 'blog'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$news = new FieldsBuilder('news');

		$news
			->setLocation('block', '==', 'acf/news') // ważne!
			/*--- FIELDS ---*/
			->addTab('Elementy', ['placement' => 'top'])
			->addGroup('g_news', ['label' => ''])
			->addText('header', ['label' => 'Nagłówek'])
			->addWysiwyg('text', [
				'label' => 'Treść',
				'tabs' => 'all',
				'toolbar' => 'full',
				'media_upload' => true,
			])
			->addLink('button', [
				'label' => 'Przycisk',
				'return_format' => 'array',
			])
			->addMessage('Informacja', 'Kafelki to trzy najnowsze wpisy z sekcji „Wpisy” — tytuł, zajawka, kategoria i obrazek wyróżniający pobierane są automatycznie.')
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

		return $news;
	}

	public function with(): array
	{
		$query = new \WP_Query([
			'post_type' => 'post',
			'posts_per_page' => 3,
			'orderby' => 'date',
			'order' => 'DESC',
			'post_status' => 'publish',
			'ignore_sticky_posts' => true,
		]);

		$items = [];

		foreach ($query->posts as $post) {
			$thumb_id = get_post_thumbnail_id($post->ID);
			$terms = get_the_terms($post->ID, 'category');

			$items[] = [
				'id' => $post->ID,
				'title' => $post->post_title,
				'excerpt' => get_the_excerpt($post),
				'url' => get_permalink($post->ID),
				'category' => (! is_wp_error($terms) && ! empty($terms)) ? $terms[0]->name : '',
				'image_url' => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : null,
				'image_alt' => $thumb_id ? get_post_meta($thumb_id, '_wp_attachment_image_alt', true) : '',
			];
		}

		wp_reset_postdata();

		$fields = [
			'g_news' => get_field('g_news'),
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
