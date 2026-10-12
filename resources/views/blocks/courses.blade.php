<!--- courses --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-courses relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main relative z-10">
		@if(!empty($g_courses['header']) || !empty($g_courses['text']))
		<div class="__top grid grid-cols-1 lg:grid-cols-2 items-end gap-12">
			@if(!empty($g_courses['header']))
			<h3 data-gsap-element="header" class="text-main">{{ $g_courses['header'] }}</h3>
			@endif

			@if(!empty($g_courses['text']))
			<div data-gsap-element="txt" class="__txt h-max">
				{!! $g_courses['text'] !!}
			</div>
			@endif
		</div>
		@endif

		@if(!empty($r_courses))
		<div class="__stack grid grid-cols-1 auto-rows-fr">
			@foreach($r_courses as $item)
			<div class="gsap__cards __cards sticky top-20 pt-20 flex flex-col">
				<div class="gsap__card __card relative flex flex-1 items-end overflow-hidden rounded-4xl p-8 md:p-16">
					@if (!empty($item['image']['url']))
					<figure class="__img absolute inset-0 m-0">
						<img
							src="{{ $item['image']['url'] }}"
							alt="{{ $item['image']['alt'] ?? '' }}"
							class="w-full h-full object-cover">
					</figure>
					@endif

					<div class="absolute inset-0 bg-linear-to-t from-main to-transparent"></div>

					<div class="__content relative z-10 w-full md:w-1/2 ml-auto flex flex-col gap-8 mt-80">
						<div class="__txt flex flex-col gap-2 text-white">
							@if(!empty($item['header']))
							<p class="text-h5">{{ $item['header'] }}</p>
							@endif

							@if(!empty($item['text']))
							<div>{!! $item['text'] !!}</div>
							@endif
						</div>

						@if(!empty($item['button']))
						<div class="inline-buttons">
							<x-button :href="$item['button']['url']" variant="primary">
								{{ $item['button']['title'] }}
							</x-button>
						</div>
						@endif
					</div>
				</div>
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>
