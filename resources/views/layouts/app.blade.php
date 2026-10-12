<!doctype html>
<html @php(language_attributes())>

<head>

	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">

	<?php
	$metaDescription = is_singular() && has_excerpt()
		? wp_strip_all_tags(get_the_excerpt())
		: (get_bloginfo('description') ?: sprintf(__('Oficjalna strona %s.', 'sage'), $siteName));
	$canonicalUrl = wp_get_canonical_url() ?: home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? '/'));
	?>

	<meta name="description" content="{{ $metaDescription }}">
	<link rel="canonical" href="{{ $canonicalUrl }}">

	<meta property="og:site_name" content="{{ $siteName }}">
	<meta property="og:title" content="{{ wp_get_document_title() }}">
	<meta property="og:description" content="{{ $metaDescription }}">
	<meta property="og:url" content="{{ $canonicalUrl }}">
	<meta property="og:type" content="{{ is_singular() ? 'article' : 'website' }}">
	@if ($logo)
	<meta property="og:image" content="{{ $logo['url'] }}">
	@endif
	<meta name="twitter:card" content="summary_large_image">

	<?php
	$organizationSchema = array_filter([
		'@type' => 'Organization',
		'name' => $siteName,
		'url' => home_url('/'),
		'logo' => $logo['url'] ?? null,
		'telephone' => $footer_contact['phone'] ?? null,
		'email' => $footer_contact['email'] ?? null,
	]);
	?>
	<script type="application/ld+json">
	{!! wp_json_encode([
		'@context' => 'https://schema.org',
		'@graph' => [
			$organizationSchema,
			[
				'@type' => 'WebSite',
				'name' => $siteName,
				'url' => home_url('/'),
			],
		],
	]) !!}
	</script>

	@php(do_action('get_header'))
	@php(wp_head())

	{{-- Fonts --}}
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Zalando+Sans+SemiExpanded:ital,wght@0,200..900;1,200..900&family=Zalando+Sans:ital,wght@0,200..900;1,200..900&display=swap" rel="stylesheet">

	{{-- Styles --}}
	@vite(['resources/css/app.css', 'resources/js/app.js'])
	<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
		integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
	<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
		integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>

<body @php(body_class())>
	<!-- Google Tag Manager (noscript) -->
	<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-M5TV295L"
			height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
	<!-- End Google Tag Manager (noscript) -->
	@php(wp_body_open())

	<div id="app">

		@include('sections.header')

		@if (function_exists('is_woocommerce') && (is_shop() || is_product_category() || is_product_tag()))

		@yield('content')

		@elseif (function_exists('is_product') && is_product())

		<main id="main" class="main -menu-mt">
			@yield('content')
		</main>

		@else

		<main id="main" class="main">
			@yield('content')
		</main>

		@endif

		@include('sections.footer')
	</div>

	@php(do_action('get_footer'))
	@php(wp_footer())

</body>

</html>