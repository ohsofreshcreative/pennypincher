@php

$hasImage1 = !empty($g_image['image']);
$hasImage2 = !empty($g_image['image2']);
$gridClass = ($hasImage1 && $hasImage2) ? 'grid-cols-1 md:grid-cols-2' : 'grid-cols-1';
$isPost = is_singular('post');
@endphp

<!--- image -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-image relative',
	'mt-10' => $isPost,
	'-smt' => !$isPost,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div @class(['__wrapper grid items-center gap-8', $gridClass, 'c-main' => !$isPost])>

		@if ($hasImage1)
		<figure class="__img order1 m-0 radius-img overflow-hidden">
			<picture>
				<img data-gsap-element="image" class="w-full h-auto" src="{{ $g_image['image']['url'] }}" alt="{{ $g_image['image']['alt'] ?? '' }}">
			</picture>
		</figure>
		@endif

		@if ($hasImage2)
		<figure class="__img order1 m-0 radius-img overflow-hidden">
			<picture>
				<img data-gsap-element="image" class="w-full h-auto" src="{{ $g_image['image2']['url'] }}" alt="{{ $g_image['image2']['alt'] ?? '' }}">
			</picture>
		</figure>
		@endif

	</div>

</section>