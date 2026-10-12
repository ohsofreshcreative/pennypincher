<!--- tiles --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-tiles relative -smt overflow-hidden' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main relative">
		<div class="__col grid grid-cols-1 lg:grid-cols-2 items-center gap-8 lg:gap-20">
			@if (!empty($g_tiles['image']))
			<figure data-gsap-element="img" class="__img h-full order1">
				<picture>
					<img class="radius-img h-[504px] max-h-[504px] w-full object-cover" src="{{ $g_tiles['image']['url'] }}" alt="{{ $g_tiles['image']['alt'] ?? '' }}">
				</picture>
			</figure>
			@endif

			<div class="__content order2">
				@if (!empty($g_tiles['header']))
				<h2 data-gsap-element="header" class="text-h2 m-header text-primary">{{ $g_tiles['header'] }}</h2>
				@endif

				@if (!empty($g_tiles['text']))
				<div data-gsap-element="txt" class="__txt">
					{!! $g_tiles['text'] !!}
				</div>
				@endif

				@if (!empty($r_tiles))
				<div data-gsap-element="stagger" class="__stats grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 m-btn">
					@foreach ($r_tiles as $stat)
					<div class="__stat bg-secondary-300 radius px-6 py-4">
						@if (!empty($stat['number']))
						<p class="text-h6 text-white">{{ $stat['number'] }}</p>
						@endif
						@if (!empty($stat['label']))
						<p class="text-white">{{ $stat['label'] }}</p>
						@endif
					</div>
					@endforeach
				</div>
				@endif
			</div>
		</div>
	</div>
</section>
