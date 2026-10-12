<?php

/**
 * Theme setup.
 */

namespace App;

use Illuminate\Support\Facades\Vite;


/**
 * Inject styles into the block editor.
 *
 * @return array
 */
add_filter('block_editor_settings_all', function ($settings) {
	$style = Vite::asset('resources/css/editor.css');

	$settings['styles'][] = [
		'css' => "@import url('{$style}')",
	];

	return $settings;
});

/*--- ACF BLOCK EXPANDED EDITOR ---*/

add_filter('acf/register_block_type_args', function ($block) {
	if (! str_starts_with($block['name'] ?? '', 'acf/')) {
		return $block;
	}

	$block['acf_block_version'] = 3;
	$block['api_version'] = 3;
	$block['expanded_editor_buttons'] = true;
	$block['hide_fields_in_sidebar'] = true;
	$block['auto_inline_editing'] = false;

	return $block;
});

/**
 * Inject scripts into the block editor.
 *
 * @return void
 */
add_filter('admin_head', function () {
	if (! get_current_screen()?->is_block_editor()) {
		return;
	}

	$dependencies = json_decode(Vite::content('editor.deps.json'));

	foreach ($dependencies as $dependency) {
		if (! wp_script_is($dependency)) {
			wp_enqueue_script($dependency);
		}
	}

	echo Vite::withEntryPoints([
		'resources/js/editor.js',
	])->toHtml();
});

/**
 * Use the generated theme.json file.
 *
 * @return string
 */
add_filter('theme_file_path', function ($path, $file) {
	return $file === 'theme.json'
		? public_path('build/assets/theme.json')
		: $path;
}, 10, 2);

/**
 * Register the initial theme setup.
 *
 * @return void
 */

add_action('after_setup_theme', function () {

	// Dodaj wsparcie dla WooCommerce
	add_theme_support('woocommerce');
	add_theme_support('wc-product-gallery-zoom');
	add_theme_support('wc-product-gallery-lightbox');
	add_theme_support('wc-product-gallery-slider');


	/**
	 * Disable full-site editing support.
	 *
	 * @link https://wptavern.com/gutenberg-10-5-embeds-pdfs-adds-verse-block-color-options-and-introduces-new-patterns
	 */
	remove_theme_support('block-templates');

	/**
	 * Register the navigation menus.
	 *
	 * @link https://developer.wordpress.org/reference/functions/register_nav_menus/
	 */
	register_nav_menus([
		'primary_navigation' => __('Primary Navigation', 'sage'),
	]);

	/**
	 * Disable the default block patterns.
	 *
	 * @link https://developer.wordpress.org/block-editor/developers/themes/theme-support/#disabling-the-default-block-patterns
	 */
	remove_theme_support('core-block-patterns');

	/**
	 * Enable plugins to manage the document title.
	 *
	 * @link https://developer.wordpress.org/reference/functions/add_theme_support/#title-tag
	 */
	add_theme_support('title-tag');

	/**
	 * Enable post thumbnail support.
	 *
	 * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
	 */
	add_theme_support('post-thumbnails');

	/**
	 * Enable responsive embed support.
	 *
	 * @link https://developer.wordpress.org/block-editor/how-to-guides/themes/theme-support/#responsive-embedded-content
	 */
	add_theme_support('responsive-embeds');

	/**
	 * Enable HTML5 markup support.
	 *
	 * @link https://developer.wordpress.org/reference/functions/add_theme_support/#html5
	 */
	add_theme_support('html5', [
		'caption',
		'comment-form',
		'comment-list',
		'gallery',
		'search-form',
		'script',
		'style',
	]);

	/**
	 * Enable selective refresh for widgets in customizer.
	 *
	 * @link https://developer.wordpress.org/reference/functions/add_theme_support/#customize-selective-refresh-widgets
	 */
	add_theme_support('customize-selective-refresh-widgets');
}, 20);

/*--- REMOVE GLOBAL STYLES ---*/

add_action('wp_enqueue_scripts', function () {
	wp_dequeue_style('wp-block-library');
	wp_dequeue_style('wp-block-library-theme');
	wp_dequeue_style('global-styles');
}, 100);

/*--- WOOCOMMERCE PHP FILES ---*/

array_map(function ($file) {
	require_once $file;
}, array_merge(
	glob(get_theme_file_path('app/Woo/*.php')) ?: [],
	glob(get_theme_file_path('app/Woo/*/*.php')) ?: []
));


/*--- WOOCOMMERCE SIDEBAR ---*/

add_action('widgets_init', function () {
	register_sidebar([
		'name'          => __('Sklep - Filtry', 'sage'),
		'id'            => 'sidebar-shop',
		'before_widget' => '<section class="widget %1$s %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<p class="font-header text-h5 widget-title font-bold mb-4">',
		'after_title'   => '</p>',
	]);
});

/**
 * Register the theme sidebars.
 *
 * @return void
 */
add_action('widgets_init', function () {
	$defaultConfig = [
		'before_widget' => '<section class="footer_widget widget %1$s %2$s">',
		'after_widget' => '</section>',
		'before_title' => '<p class="font-header text-h6 widget-title primary !mb-4 flex">',
		'after_title' => '</p>',
	];

	register_sidebar([
		'name' => __('Primary', 'sage'),
		'id' => 'sidebar-primary',
	] + $defaultConfig);

	register_sidebar([
		'name' => __('Footer 1', 'sage'),
		'id'   => 'sidebar-footer-1',
	] + $defaultConfig);

	register_sidebar([
		'name' => __('Footer 2', 'sage'),
		'id'   => 'sidebar-footer-2',
	] + $defaultConfig);

	register_sidebar([
		'name' => __('Footer 3', 'sage'),
		'id'   => 'sidebar-footer-3',
	] + $defaultConfig);

	register_sidebar([
		'name' => __('Footer 4', 'sage'),
		'id'   => 'sidebar-footer-4',
	] + $defaultConfig);
});

/*--- DISABLE COMMENTS ---*/

add_action('init', function () {
	remove_post_type_support('post', 'comments');
	remove_post_type_support('page', 'comments');

	add_filter('comments_open', '__return_false', 20, 2);
	add_filter('pings_open', '__return_false', 20, 2);

	add_filter('comments_array', '__return_empty_array', 10, 2);
});

add_action('admin_init', function () {
	remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
});

add_action('admin_menu', function () {
	remove_menu_page('edit-comments.php');
});

add_action('wp_before_admin_bar_render', function () {
	global $wp_admin_bar;
	$wp_admin_bar->remove_menu('comments');
});

/*--- CATEGORY IMAGE ---*/

/**
 * Register the ACF fields for Category taxonomy.
 */
add_action('acf/init', function () {
	if (function_exists('acf_add_local_field_group')) {
		acf_add_local_field_group([
			'key' => 'group_category_settings',
			'title' => 'Ustawienia Kategorii',
			'fields' => [
				[
					'key' => 'field_category_header',
					'label' => 'Nagłówek',
					'name' => 'category_header',
					'type' => 'text',
					'instructions' => 'Opcjonalny nagłówek, który może zastąpić domyślną nazwę kategorii.',
				],
				[
					'key' => 'field_category_description',
					'label' => 'Opis',
					'name' => 'category_description',
					'type' => 'textarea',
					'instructions' => 'Krótki opis wyświetlany na stronie kategorii.',
				],
				[
					'key' => 'field_category_image',
					'label' => 'Zdjęcie Kategorii',
					'name' => 'category_image',
					'type' => 'image',
					'instructions' => 'Dodaj obrazek, który będzie wyświetlany jako tło lub nagłówek dla tej kategorii.',
					'return_format' => 'array', // Zwraca tablicę z danymi obrazka (url, alt, etc.)
					'preview_size' => 'medium',
					'library' => 'all',
				],
			],
			'location' => [
				[
					[
						'param' => 'taxonomy',
						'operator' => '==',
						'value' => 'category', // Celujemy w taksonomię "category"
					],
				],
			],
			'menu_order' => 0,
			'position' => 'normal',
			'style' => 'default',
			'label_placement' => 'top',
			'instruction_placement' => 'label',
			'active' => true,
		]);
	}
});

/**
 * Remove archive title prefix (e.g., "Category:", "Tag:").
 */
add_filter('get_the_archive_title', function ($title) {
	if (is_category()) {
		$title = single_cat_title('', false);
	} elseif (is_tag()) {
		$title = single_tag_title('', false);
	} elseif (is_author()) {
		$title = '<span class="vcard">' . get_the_author() . '</span>';
	} elseif (is_post_type_archive()) {
		$title = post_type_archive_title('', false);
	} elseif (is_tax()) {
		$title = single_term_title('', false);
	}

	return $title;
});

/*--- GSAP ---*/

add_action('wp_enqueue_scripts', function () {
	/**
	 * Rejestracja i ładowanie skryptów.
	 */

	// Ładuj GSAP i ScrollTrigger z CDN.
	// Trzeci argument (tablica []) oznacza brak zależności.
	// Piąty argument (true) umieszcza skrypty w stopce.
	wp_enqueue_script('gsap-cdn', 'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js', [], null, true);

	// Ustawiamy zależność 'gsap-st-cdn' od 'gsap-cdn', aby załadowały się w dobrej kolejności.
	wp_enqueue_script('gsap-st-cdn', 'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js', ['gsap-cdn'], null, true);
}, 1); // Ustawiamy priorytet na 1, aby wykonało się BARDZO wcześnie.


add_action('after_setup_theme', function () {
	remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
	remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
});




/**
 * Dynamically generate checkboxes for subsidies in Contact Form 7.
 */
add_action('after_setup_theme', function () {
	remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
	remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
});


/**
 * Register custom form tag for CF7 to display subsidies.
 */
add_action('wpcf7_init', function () {
	wpcf7_add_form_tag('subsidy_checkboxes', 'App\\custom_subsidy_checkboxes_handler');
});

/**
 * Handler for the [subsidy_checkboxes] form tag.
 *
 * @param WPCF7_FormTag $tag
 * @return string
 */
function custom_subsidy_checkboxes_handler($tag)
{
	$subsidies = get_field('subsidies', 'option');
	$output = '';

	if ($subsidies) {
		$output .= '<h2 class="text-2xl font-bold mb-4">Dofinansowania</h2>';
		$output .= '<div class="flex flex-col gap-2">';
		foreach ($subsidies as $subsidy) {
			if (!empty($subsidy['subsidy_name'])) {
				$name = esc_attr($subsidy['subsidy_name']);
				$output .= sprintf(
					'<label class="flex items-center gap-2"><input type="checkbox" name="subsidies[]" value="%s" /> <span>%s</span></label>',
					$name,
					esc_html($name)
				);
			}
		}
		$output .= '</div>';
	}

	return $output;
}


/**
 * Disable Contact Form 7 auto <p> tags.
 */
add_filter('wpcf7_autop_or_not', '__return_false');

/*--- WYSIWYG HEIGHT FIX ---*/

add_action('admin_footer', function () {
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || $screen->base !== 'post') return;
?>
	<script>
		(function() {
			const TARGET_HEIGHT = 140; // startowa wysokość

			function applyInitialHeight() {
				document.querySelectorAll('.acf-editor-wrap iframe[id^="acf-editor-"]').forEach((iframe) => {
					// Jeśli już ustawiliśmy startową wysokość, nie ruszaj więcej (żeby ręczny resize działał)
					if (iframe.dataset.acfInitialHeightApplied === '1') return;

					const current = parseInt(iframe.style.height || 0, 10);

					// Ustaw tylko jeśli jest puste albo większe od targetu (czyli "za wysokie")
					if (!current || current > TARGET_HEIGHT) {
						iframe.style.height = TARGET_HEIGHT + 'px';
					}

					iframe.dataset.acfInitialHeightApplied = '1';
				});
			}

			// Spróbuj kilka razy po załadowaniu (ACF/TinyMCE potrafią wstać później)
			let tries = 0;
			const timer = setInterval(() => {
				applyInitialHeight();
				tries++;
				if (tries >= 40) clearInterval(timer); // ~10s
			}, 250);

			// Obserwuj DOM tylko po to, żeby łapać NOWE edytory (np. po dodaniu bloku),
			// ale nie resetować tych, które użytkownik już rozciągnął.
			const obs = new MutationObserver(() => applyInitialHeight());
			obs.observe(document.body, {
				childList: true,
				subtree: true
			});

			window.addEventListener('load', () => setTimeout(applyInitialHeight, 500));
		})();
	</script>
<?php
});

add_action('template_redirect', function () {
	// Sprawdź, czy jesteśmy na stronie archiwum JAKIEJKOLWIEK taksonomii
	if (!is_tax() && !is_category() && !is_tag()) {
		return; // Jeśli nie, zakończ działanie
	}

	// Pobierz obiekt aktualnej taksonomii (kategorii, tagu itp.)
	$term = get_queried_object();

	// Upewnij się, że obiekt istnieje i jest terminem taksonomii
	if ($term instanceof \WP_Term) {
		// Pobierz wartość pola 'linked_page' dla tego konkretnego terminu
		$redirect_url = get_field('linked_page', $term);

		// Jeśli wybrano stronę, przekieruj
		if ($redirect_url) {
			wp_safe_redirect($redirect_url, 301);
			exit;
		}
	}
});


/*--- PAGINATION ---*/


add_filter('the_posts_pagination_args', function ($args) {
	$args['prev_text'] = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>';
	$args['next_text'] = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>';
	$args['screen_reader_text'] = __('Nawigacja po wpisach', 'sage');
	return $args;
});

add_filter('navigation_markup_template', function ($template, $class) {
	return '
    <nav class="navigation %1$s mt-10 mb-10" aria-label="%4$s">
        <h2 class="screen-reader-text">%2$s</h2>
        <div class="nav-links flex items-center justify-center gap-4">%3$s</div>
    </nav>';
}, 10, 2);

add_filter('paginate_links_output', function ($output) {
	$output = str_replace('page-numbers', 'page-numbers inline-flex items-center justify-center w-10 h-10 rounded-full', $output);
	$output = str_replace('current', 'current bg-primary text-white', $output);
	$output = str_replace('prev', 'prev', $output);
	$output = str_replace('next', 'next', $output);
	return $output;
});

/*--- PDF THUMBNAIL ---*/

/**
 * Generates a thumbnail from the first page of a PDF file and returns its URL.
 *
 * @param int $pdf_attachment_id The attachment ID of the PDF file.
 * @return string The URL to the generated thumbnail or the original PDF URL on failure.
 */
function get_pdf_thumbnail_url($pdf_attachment_id)
{
	// Check if Imagick extension is available
	if (!extension_loaded('imagick')) {
		error_log('Imagick extension is not loaded. PDF thumbnail generation is disabled.');
		return wp_get_attachment_url($pdf_attachment_id);
	}

	$pdf_path = get_attached_file($pdf_attachment_id);
	if (!$pdf_path || !file_exists($pdf_path)) {
		return wp_get_attachment_url($pdf_attachment_id);
	}

	$upload_dir = wp_upload_dir();
	$thumbnail_filename = pathinfo($pdf_path, PATHINFO_FILENAME) . '-pdf-thumb.jpg';
	$thumbnail_path = "{$upload_dir['path']}/{$thumbnail_filename}";
	$thumbnail_url = "{$upload_dir['url']}/{$thumbnail_filename}";

	// If thumbnail already exists, return its URL
	if (file_exists($thumbnail_path)) {
		return $thumbnail_url;
	}

	try {
		$imagick = new \Imagick();
		$imagick->setResolution(150, 150);
		$imagick->readImage("{$pdf_path}[0]");
		// Konwertuj CMYK → sRGB jeśli potrzeba
		if ($imagick->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
			$imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
		}
		$imagick->setImageFormat('jpeg');
		$imagick->setImageBackgroundColor('white');
		$imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
		$imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
		$imagick->writeImage($thumbnail_path);
		$imagick->clear();
		$imagick->destroy();

		return $thumbnail_url;
	} catch (\Exception $e) {
		error_log('PDF to Image conversion failed: ' . $e->getMessage());
		return wp_get_attachment_url($pdf_attachment_id);
	}
}
