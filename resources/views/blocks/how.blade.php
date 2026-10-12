<!--- how --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-how relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		@if (!empty($g_how['header']) || !empty($g_how['text']))
		<div class="__top max-w-2xl">
			@if (!empty($g_how['header']))
			<h2 data-gsap-element="header" class="text-h2 text-primary m-header">{{ $g_how['header'] }}</h2>
			@endif
			@if (!empty($g_how['text']))
			<div data-gsap-element="txt">{!! $g_how['text'] !!}</div>
			@endif
		</div>
		@endif

		@if (!empty($steps))
		<div data-gsap-element="stagger" class="__cards grid grid-cols-1 lg:grid-cols-2 gap-6 mt-10">
			@foreach ($steps as $step)
			<div class="__card relative radius overflow-hidden flex flex-col justify-end pt-32 pb-8 px-8">
				@if (!empty($step['image']))
				<img src="{{ $step['image']['url'] }}" alt="{{ $step['image']['alt'] ?? '' }}" class="absolute inset-0 w-full h-full object-cover" />
				<div class="absolute inset-0 bg-linear-to-t from-main to-transparent"></div>
				@endif

				<div class="relative flex flex-col gap-2 text-white">
					@if (!empty($step['number']))
					<p class="text-h3">{{ $step['number'] }}</p>
					@endif
					@if (!empty($step['title']))
					<p class="__title text-h5">{{ $step['title'] }}</p>
					@endif
					@if (!empty($step['text']))
					<p>{{ $step['text'] }}</p>
					@endif
				</div>
			</div>
			@endforeach
		</div>
		@endif
	</div>

</section>
