@php
$sectionClass = '';
$sectionClass .= $flip ? ' order-flip' : '';
$sectionClass .= $nolist ? ' no-list' : '';
$sectionClass .= $wide ? ' wide' : '';
$sectionClass .= $nomt ? ' !mt-0' : '';
$sectionClass .= $gap ? ' wider-gap' : '';

if (!empty($background) && $background !== 'none') {
$sectionClass .= ' ' . $background;
}
@endphp

<!-- gallery --->

<section data-gsap-anim="section" @if(!empty($section_id)) id="{{ $section_id }}" @endif class="b-gallery relative -smt {{ $sectionClass }} {{ $section_class }}">
	<div class="__wrapper c-main">
		<h2 data-gsap-element="header" class="m-header">{{ $g_gallery['header'] }}</h2>

		@if (!empty($g_gallery['gallery']))
		<div data-gsap-element="images" class="lightbox-gallery grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-10">
			@foreach ($g_gallery['gallery'] as $image)
		
            <a href="{{ $image['url'] }}">
                <img class="img-m w-full radius object-cover" src="{{ $image['sizes']['large'] ?? $image['url'] }}" alt="{{ $image['alt'] ?? '' }}">
            </a>
			@endforeach
		</div>
		@endif

	</div>
</section>