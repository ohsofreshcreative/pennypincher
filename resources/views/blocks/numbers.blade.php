@php
$itemCount = count($r_numbers ?? []);
$gridClass = 'grid-cols-1'; // Default for mobile

if ($itemCount > 1) {
$gridClass .= ' md:grid-cols-' . min($itemCount, 5); // Handles 2, 3, 4, 5 items
}
@endphp

<!--- numbers -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-numbers relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		@if (!empty($header))
		<h2 data-gsap-element="header" class="">{{ $header }}</h2>
		@endif
		@if (!empty($r_numbers))
		<div class="grid {{ $gridClass }} gap-8 mt-14">
			@foreach ($r_numbers as $item)
			<div data-gsap-element="card" class="__card relative bg-white radius p-6">
				@if (!empty($item['title']))
				<p class="text-h2">{{ $item['title'] }}</p>
				@endif
				@if (!empty($item['txt']))
				<p class="">{{ $item['txt'] }}</p>
				@endif
			</div>
			@endforeach
		</div>
		@endif
	</div>

</section>