<?php

/*--- CPT - Produkty ---*/

add_action('init', function () {
	register_post_type('offer', [
		'label'         => 'Oferta',
		'labels'        => [
			'name'               => 'Oferta',
			'singular_name'      => 'Oferta',
			'menu_name'          => 'Oferta',
			'all_items'          => 'Wszystkie oferty',
			'add_new'            => 'Dodaj nową',
			'add_new_item'       => 'Dodaj nową ofertę',
			'edit_item'          => 'Edytuj ofertę',
			'new_item'           => 'Nowa oferta',
			'view_item'          => 'Zobacz ofertę',
			'view_items'         => 'Zobacz oferty',
			'search_items'       => 'Szukaj ofert',
			'not_found'          => 'Nie znaleziono ofert',
			'not_found_in_trash' => 'Brak ofert w koszu',
			'parent_item_colon'  => 'Oferta nadrzędna:',
		],
		'public'        => true,
		'hierarchical'  => true,
		'has_archive'   => true,
		'menu_icon'     => 'dashicons-cart',
		'menu_position' => 20,
		'supports'      => ['title', 'editor', 'thumbnail', 'excerpt', 'page-attributes'],
		'show_in_rest'  => true,
		'rewrite'       => ['slug' => 'oferta', 'with_front' => false],
	]);
});

add_action('init', function () {
	register_taxonomy('offer_category', ['offer'], [
		'label'        => 'Kategorie ofert',
		'labels'       => [
			'name'              => 'Kategorie ofert',
			'singular_name'     => 'Kategoria oferty',
			'search_items'      => 'Szukaj kategorii',
			'all_items'         => 'Wszystkie kategorie',
			'parent_item'       => 'Kategoria nadrzędna',
			'parent_item_colon' => 'Kategoria nadrzędna:',
			'edit_item'         => 'Edytuj kategorię',
			'update_item'       => 'Aktualizuj kategorię',
			'add_new_item'      => 'Dodaj nową kategorię',
			'new_item_name'     => 'Nazwa nowej kategorii',
			'menu_name'         => 'Kategorie',
		],
		'hierarchical' => true,
		'public'       => true,
		'show_in_rest' => true,
		'rewrite'      => ['slug' => 'kategoria-oferty', 'with_front' => false],
	]);
});

// Dodaj kolumnę "Kategoria" na liście ofert w adminie
add_filter('manage_offer_posts_columns', function ($columns) {
	return [
		'cb'             => $columns['cb'],
		'title'          => $columns['title'],
		'offer_category' => 'Kategoria',
		'date'           => $columns['date'],
	];
});

add_action('manage_offer_posts_custom_column', function ($column, $post_id) {
	if ($column === 'offer_category') {
		$terms = get_the_terms($post_id, 'offer_category');
		if (!empty($terms) && !is_wp_error($terms)) {
			$links = array_map(function ($term) {
				$url = add_query_arg([
					'post_type'      => 'offer',
					'offer_category' => $term->slug,
				], admin_url('edit.php'));
				return '<a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
			}, $terms);
			echo implode(', ', $links);
		} else {
			echo '—';
		}
	}
}, 10, 2);
