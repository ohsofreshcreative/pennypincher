<?php

use Roots\Acorn\Application;

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our theme. We will simply require it into the script here so that we
| don't have to worry about manually loading any of our classes later on.
|
*/

if (! file_exists($composer = __DIR__ . '/vendor/autoload.php')) {
	wp_die(__('Error locating autoloader. Please run <code>composer install</code>.', 'sage'));
}

require $composer;

/*
|--------------------------------------------------------------------------
| Register The Bootloader
|--------------------------------------------------------------------------
|
| The first thing we will do is schedule a new Acorn application container
| to boot when WordPress is finished loading the theme. The application
| serves as the "glue" for all the components of Laravel and is
| the IoC container for the system binding all of the various parts.
|
*/

Application::configure()
	->withProviders([
		App\Providers\ThemeServiceProvider::class,
	])
	->boot();

/*
|--------------------------------------------------------------------------
| Register Sage Theme Files
|--------------------------------------------------------------------------
|
| Out of the box, Sage ships with categorically named theme files
| containing common functionality and setup to be bootstrapped with your
| theme. Simply add (or remove) files from the array below to change what
| is registered alongside Sage.
|
*/


/*--- HIDE ALL BLOCKS ---*/

function allow_only_selected_blocks($allowed_block_types, $editor_context)
{
	if (! empty($editor_context->post)) {
		// Pobierz wszystkie zarejestrowane bloki
		$all_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

		$allowed_blocks = [];

		foreach ($all_blocks as $block_name => $block) {
			// Dopuszczone kategorie
			if (isset($block->category) && in_array($block->category, ['formatting', '_media', '_con', '_tekst'], true)) {
				$allowed_blocks[] = $block_name;
			}

			// Dopuszczamy też wszystkie bloki ACF (prefix "acf/")
			if (strpos($block_name, 'acf/') === 0) {
				$allowed_blocks[] = $block_name;
			}
		}

		// Dodatkowo dopuszczamy akapit i nagłówek
		$allowed_blocks[] = 'core/paragraph';
		$allowed_blocks[] = 'core/heading';
		$allowed_blocks[] = 'core/list';

		return $allowed_blocks;
	}

	return [];
}

add_filter('allowed_block_types_all', 'allow_only_selected_blocks', 10, 2);


collect(['setup', 'filters', 'post-types'])
	->each(function ($file) {
		if (! locate_template($file = "app/{$file}.php", true, true)) {
			wp_die(
				/* translators: %s is replaced with the relative file path */
				sprintf(__('Error locating <code>%s</code> for inclusion.', 'sage'), $file)
			);
		}
	});


/*--- PROJECT BLOCKS ---*/

add_filter('sage/acf-composer/fields', fn() => [
	App\Blocks\ExampleBlock::class,
]);


add_filter( 'acf/settings/enable_block_bindings', '__return_false' );