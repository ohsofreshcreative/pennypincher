<!--- map -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-map relative -spt -spb' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main relative">
		@if (!empty($g_map['header']))
		<h2 data-gsap-element="header" class="text-primary">{{ $g_map['header'] }}</h2>
		@endif

		<div>
			<div data-gsap-element="txt" class="__txt radius-img overflow-hidden mt-8">
				{!! $g_map['map'] !!}
			</div>
			@if (!empty($g_map['button']))
			<x-button
				:href="$g_map['button']['url']"
				variant="primary"
				class="mt-6"
				data-gsap-element="btn">
				{{ $g_map['button']['title'] }}
			</x-button>
			@endif
		</div>
	</div>

</section>