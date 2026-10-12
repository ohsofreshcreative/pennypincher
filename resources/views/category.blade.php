@extends('layouts.app')

@section('content')

@php
$term = get_queried_object();
$categories = get_categories();
$default_category = get_category((int) get_option('default_category'));

if ($default_category) {
$categories = array_values(array_filter($categories, static fn ($category) => (int) $category->term_id !== (int) $default_category->term_id));
array_unshift($categories, $default_category);
}

$category_header = get_field('category_header', $term);
$category_description = get_field('category_description', $term);
$category_image = get_field('category_image', $term);

$cta = get_field('g_octa', 'option');
$form = !empty($cta['shortcode']);

// Pobranie pól ACF dla sekcji 'bottom'
$section_id = $bottom['section_id'] ?? '';
$section_class = $bottom['section_class'] ?? '';
$flip = $bottom['flip'] ?? false;

// Przygotowanie klas CSS
$sectionClass = '';
$sectionClass .= $flip ? ' order-flip' : '';

// Wygenerowanie unikalnego ID dla SVG
$unique_id = 'clip_'.uniqid();
@endphp

<div class="hero category-header relative overflow-hidden">
	@if(!empty($category_image['url']))
	<figure class="absolute inset-0 m-0 z-0">
		<picture>
			<img src="{{ $category_image['url'] }}" alt="" class="w-full h-full object-cover object-center">
		</picture>
	</figure>
	@endif
	<div class="absolute inset-0 bg-gradient @if(!empty($category_image['url'])) opacity-80 @endif"></div>

	<img class="absolute top-0 left -translate-x-1/3 w-auto opacity-40 mix-blend-overlay h-[704px] pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/signet.svg" alt="">

	<div data-gsap-element="bread" class="__breadcrumb mb-4 relative z-10">
		@if (function_exists('yoast_breadcrumb'))
		{!! yoast_breadcrumb('<p id="breadcrumbs">','</p>') !!}
		@endif
	</div>
	<div class="__wrapper c-main relative z-1 pt-60 pb-26">
		<div class="__content w-full md:w-2/3">
			<h2 class="text-white m-header">
				{!! $category_header ?: get_the_archive_title() !!}
			</h2>
			@if ($category_description)
			<div class="text-white text-xl">
				{!! $category_description !!}
			</div>
			@endif
		</div>
	</div>
</div>

</div>

@if (!empty($categories))
<div class="__filters c-main relative flex flex-wrap gap-3 z-2 -mt-6!">
	@foreach ($categories as $cat)
	<a href="{{ get_category_link($cat->term_id) }}" @class(['radius bg-third px-6 py-3 text-primary hover:bg-third-hover transition-colors', '!bg-main-light border border-white border-dashed !text-white'=> is_category($cat->term_id)])>
		{{ $cat->name }}
	</a>
	@endforeach
</div>
@endif

@if (have_posts())
<div class="__posts c-main !mt-10 posts grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
	@while (have_posts())
		@php
			the_post();
		@endphp

	@includeFirst(['partials.content-' . get_post_type(), 'partials.content'])
	@endwhile
</div>

{{-- {!! get_the_posts_navigation() !!} --}}
{!! the_posts_pagination() !!}
@else
<div class="mt-20 mb-20">
	<div class="c-main">
		<h3 class="">Brak wpisów w tej kategorii.</h3>
		<a class="main-btn m-btn" href="/wszystkie-wpisy/">Sprawdź wszystkie wpisy</a>
	</div>
</div>
@endif

<!-- cta -->
@php
$g_octa = get_field('g_octa', 'option');
$form = false;
$sectionClass = '-smt';
$section_id = '';
$section_class = '';
$background = 'none';
@endphp
@include('blocks.cta')

@endsection