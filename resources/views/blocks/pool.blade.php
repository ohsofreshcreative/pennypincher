<!--- pool --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-pool relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		<div class="__col grid grid-cols-1 lg:grid-cols-2 items-center gap-8 lg:gap-20">
			@if (!empty($g_pool['image']))
			<figure data-gsap-element="img" class="__img order1">
				<picture>
					<img class="radius-img w-full h-full object-cover aspect-square" src="{{ $g_pool['image']['url'] }}" alt="{{ $g_pool['image']['alt'] ?? '' }}">
				</picture>
			</figure>
			@endif

			<div class="__content order2">
				@if (!empty($g_pool['header']))
				<h2 data-gsap-element="header" class="text-h2 text-primary m-header">{{ $g_pool['header'] }}</h2>
				@endif
				@if (!empty($g_pool['text']))
				<div data-gsap-element="txt" class="__txt">
					{!! $g_pool['text'] !!}
				</div>
				@endif
			</div>
		</div>

		@if (!empty($r_pool))
		<div data-gsap-element="stagger" class="__stats grid grid-cols-2 md:grid-cols-5 gap-6 mt-14">
			@foreach ($r_pool as $stat)
			<div class="__stat flex flex-col gap-4 border-r border-dashed border-third pr-6 last:border-r-0">
				@if (!empty($stat['icon']))
				<div class="__icon radius w-16 h-16 bg-main flex items-center justify-center">
					{!! wp_get_attachment_image($stat['icon']['ID'], 'thumbnail', false, ['class' => 'w-8 h-8 object-contain']) !!}
				</div>
				@endif
				@if (!empty($stat['number']))
				<p class="text-h5 text-secondary-300">{{ $stat['number'] }}</p>
				@endif
				@if (!empty($stat['opis']))
				<p class="text-primary-900">{{ $stat['opis'] }}</p>
				@endif
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>
