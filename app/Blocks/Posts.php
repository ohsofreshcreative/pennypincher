<?php

namespace App\Blocks;

use Log1x\AcfComposer\Block;
use StoutLogic\AcfBuilder\FieldsBuilder;

class Posts extends Block
{
	public $name = 'Baza wiedzy - Ostatnie wpisy';
	public $description = 'posts';
	public $slug = 'posts';
	public $category = 'formatting';
	public $icon = 'admin-post';
	public $keywords = ['posts', 'category', 'wpisy', 'kategoria'];
	public $mode = 'edit';
	public $supports = [
		'align' => false,
		'mode' => true,
		'jsx' => true,
	];

	public function fields()
	{
		$posts = new FieldsBuilder('posts');

		$posts
			->setLocation('block', '==', 'acf/posts') // ważne!
			/*--- FIELDS ---*/
			->addTab('Treści', ['placement' => 'top'])
			->addGroup('posts_settings', ['label' => ''])

			->addText('title', ['label' => 'Tytuł'])
			->addTextarea('text', [
				'label' => 'Opis',
				'rows' => 2,
				'new_lines' => 'br',
			])

			->addLink('button', [
				'label' => 'Przycisk',
				'return_format' => 'array',
			])

			->addTrueFalse('show_image', [
				'label' => 'Pokaż obrazek',
				'default_value' => 1,
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])

			->addTrueFalse('show_excerpt', [
				'label' => 'Pokaż fragment treści',
				'default_value' => 0,
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
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
			->addTrueFalse('lightbg', [
				'label' => 'Jasne tło',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])
			->addTrueFalse('graybg', [
				'label' => 'Szare tło',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])
			->addTrueFalse('whitebg', [
				'label' => 'Białe tło',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			])
			->addTrueFalse('brandbg', [
				'label' => 'Tło marki',
				'ui' => 1,
				'ui_on_text' => 'Tak',
				'ui_off_text' => 'Nie',
			]);

		return $posts;
	}

	public function with()
	{
		$posts_settings = get_field('posts_settings');
		$show_image = $posts_settings['show_image'] ?? true;
		$show_excerpt = $posts_settings['show_excerpt'] ?? false;

		// Get posts from the selected category
		$args = [
			'post_type' => 'post',
			'posts_per_page' => 6,
			'post_status' => 'publish',
			'orderby' => 'date',
			'order' => 'DESC',
		];

		$query = new \WP_Query($args);
		$posts = $query->posts;

		return [
			'posts_settings' => get_field('posts_settings'),
			'posts' => $posts,
			'show_image' => $show_image,
			'show_excerpt' => $show_excerpt,
			'section_id' => get_field('section_id'),
			'section_class' => get_field('section_class'),
			'flip' => get_field('flip'),
			'wide' => get_field('wide'),
			'nomt' => get_field('nomt'),
			'gap' => get_field('gap'),
			'lightbg' => get_field('lightbg'),
			'graybg' => get_field('graybg'),
			'whitebg' => get_field('whitebg'),
			'brandbg' => get_field('brandbg'),
		];
	}
}
