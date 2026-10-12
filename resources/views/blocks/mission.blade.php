<!--- mission -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-mission relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	@if (!empty($g_mission['video']))
	<video class="absolute inset-0 w-full h-full object-cover z-0" autoplay loop muted playsinline>
		<source src="{{ $g_mission['video'] }}" type="video/mp4">
	</video>
	@elseif(!empty($g_mission['image']))
	<figure class="absolute inset-0 w-full h-full z-0 m-0">
		<picture class="w-full h-full">
			<img src="{{ $g_mission['image']['url'] }}" alt="{{ $g_mission['image']['alt'] }}" class="w-full h-full object-cover" />
		</picture>
	</figure>
	@endif

	@if (!empty($g_mission['video']) || !empty($g_mission['image']))
	<div class="absolute inset-0 z-1 pointer-events-none" style="background: linear-gradient(90deg, rgba(23, 28, 132, 0.90) 0%, rgba(23, 28, 132, 0.80) 100%);"></div>
	@endif

	<div class="__wrapper c-main relative z-10 grid grid-cols-1 md:grid-cols-[1fr_2fr] gap-6 py-30">
		@if (!empty($g_mission['header']))
		<h2 data-gsap-element="header" class="text-white">{{ $g_mission['header'] }}</h2>
		@endif

		<div>
			<div data-gsap-element="txt" class="__txt mt-4 text-white">
				{!! $g_mission['txt'] !!}
			</div>
			@if (!empty($g_mission['button']))
			<x-button
				:href="$g_mission['button']['url']"
				variant="primary"
				class="mt-6"
				data-gsap-element="btn">
				{{ $g_mission['button']['title'] }}
			</x-button>
			@endif
		</div>
	</div>

</section>