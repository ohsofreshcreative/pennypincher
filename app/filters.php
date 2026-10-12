<?php

/**
 * Theme filters.
 */

namespace App;

/**
 * Add "… Continued" to the excerpt.
 *
 * @return string
 */
add_filter('excerpt_more', function () {
    return sprintf(' &hellip; <a href="%s">%s</a>', get_permalink(), __('Continued', 'sage'));
});


add_action('pre_get_posts', function ($q) {
  if (is_admin() || !$q->is_main_query()) {
    return;
  }
  if ($q->is_search()) {
    if (!empty($_GET['post_type']) && $_GET['post_type'] === 'produkty') {
      $q->set('post_type', 'produkty');
    }
  }
});


/*--- BREACRUMB SEPARATOR ---*/
add_filter( 'woocommerce_breadcrumb_defaults', function ( $defaults ) {
    // Opakowujemy separator w element <span> z własną klasą CSS.
    $defaults['delimiter'] = '<span class="__separator">•</span>';
    return $defaults;
} );



/**
 * Override WooCommerce Coming Soon template
 */
add_filter('woocommerce_coming_soon_template', function ($template) {
    $custom_template = get_theme_file_path('resources/views/patterns/coming-soon.php');
    
    if (file_exists($custom_template)) {
        return $custom_template;
    }
    
    return $template;
});

/*--- Wyłączenie domyślnego canonical z rdzenia WP — własny, szerszy canonical jest w layouts/app.blade.php ---*/
remove_action('wp_head', 'rel_canonical');

/*--- ROBOTS.TXT: jawne dopuszczenie botów AI (sitemap dodaje już rdzeń WP) ---*/
add_filter('robots_txt', function ($output) {
    $output .= "\nUser-agent: GPTBot\nAllow: /\n";
    $output .= "\nUser-agent: ClaudeBot\nAllow: /\n";
    $output .= "\nUser-agent: PerplexityBot\nAllow: /\n";

    return $output;
});

/*--- CHANGE EDIT SECTION ---*/


add_filter('gettext', function ($translated, $text, $domain) {
    if (
        is_admin() &&
        $text === 'Open expanded editor'
    ) {
        return 'Edytuj sekcję';
    }

    return $translated;
}, 10, 3);


add_filter('acf/blocks/default_expanded_editor_button_text', function () {
    return 'Edytuj sekcję';
});