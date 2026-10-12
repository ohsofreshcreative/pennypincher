<!--- overlap --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-overlap relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main relative z-10">
		<div class="__content order2">
			<div class="__txt w-full grid grid-cols-1 md:grid-cols-2 gap-10">
				<h2 data-gsap-element="header" class="m-header">{{ $g_overlap['header'] }}</h2>

				<div data-gsap-element="header" class="">
					{!! $g_overlap['text'] !!}
				</div>
			</div>

			<div data-gsap-element="cards" class="__stack grid grid-cols-1 auto-rows-fr">
				@foreach ($r_overlap as $item)
				<div class="gsap__cards __cards sticky top-20 pt-20 flex flex-col">
					<div class="gsap__card __card relative flex flex-1 items-end p-8 rounded-4xl overflow-hidden">
						@if (!empty($item['image']['url']))
						<figure class="__img absolute inset-0 m-0">
							<img
								src="{{ $item['image']['url'] }}"
								alt="{{ $item['image']['alt'] ?? '' }}"
								class="w-full h-full object-cover">
						</figure>
						@endif

						<div class="absolute inset-0 bg-linear-to-t from-main to-transparent"></div>
						<div class="__box relative z-10 w-full md:w-1/2 p-6 md:p-10 mt-80 mb-0 md:mb-10 mx-0 md:mx-20">
							<p class="text-h5 text-white">{{ $item['header'] }}</p>
							<div class="text-white">{!! $item['text'] !!}</div>
							@if (!empty($item['button']))
							<x-button
								:href="$item['button']['url']"
								variant="secondary-small"
								class="mt-6">
								{{ $item['button']['title'] }}
							</x-button>
							@endif
						</div>
					</div>
				</div>
				@endforeach
			</div>

		</div>
	</div>
</section>