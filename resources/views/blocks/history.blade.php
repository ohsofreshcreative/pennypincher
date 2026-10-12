<!--- history -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-history relative -smt overflow-hidden' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	@if ($bgshape)
	<img class="__bg-shape absolute inset-y-0 h-full right-0 w-auto pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/bg-light-shape.svg" alt="">
	@endif

	<div class="__wrapper c-main relative">

		<div class="__col grid grid-cols-1 lg:grid-cols-2 items-center gap-30 lg:gap-20">
			@if (!empty($g_history['image']) || !empty($r_history))
			<div class="relative order1">
				@if (!empty($g_history['image']))
				<figure data-gsap-element="img" class="__img h-full">
					<picture>
						<img class="radius-img max-h-[504px] w-full object-cover" src="{{ $g_history['image']['url'] }}" alt="{{ $g_history['image']['alt'] ?? '' }}">
					</picture>
				</figure>
				@endif

				@if (!empty($r_history))
				<div class="absolute bottom-0 left-0 right-0 translate-y-1/2 grid grid-cols-3 gap-4 px-6">
					@foreach ($r_history as $item)
					<div data-gsap-element="card" class="__card bg-white p-6 shadow-md radius">
						@if (!empty($item['title']))
						<p class="text-h6 text-primary">{{ $item['title'] }}</p>
						@endif
						@if (!empty($item['text']))
						<p>{{ $item['text'] }}</p>
						@endif
					</div>
					@endforeach
				</div>
				@endif
			</div>
			@endif

			<div class="__content order2">
				<h2 data-gsap-element="header" class="text-h4 m-header text-primary">{{ $g_history['header'] }}</h2>

				<div data-gsap-element="txt" class="__txt">
					{!! $g_history['text'] !!}
				</div>

				@if (!empty($g_history['button1']) || !empty($g_history['button2']))
				<div class="inline-buttons m-btn">
					@if (!empty($g_history['button1']))
					<x-button
						:href="$g_history['button1']['url']"
						variant="primary"
						class=""
						data-gsap-element="btn">
						{{ $g_history['button1']['title'] }}
					</x-button>
					@endif

					@if (!empty($g_history['button2']))
					<x-button
						:href="$g_history['button2']['url']"
						variant="secondary"
						class=""
						data-gsap-element="btn">
						{{ $g_history['button2']['title'] }}
					</x-button>
					@endif
				</div>
				@endif

			</div>

		</div>
	</div>

</section>