<!--- offers -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-offers relative -smt overflow-hidden' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	@if ($bgshape)
	<img class="__bg-shape absolute inset-y-0 right-0 w-auto pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/bg-shape.svg" alt="">
	@endif

	<div class="__wrapper c-main relative">

		@if (!empty($offer_items))
		<div class="flex flex-col gap-20">
			@foreach ($offer_items as $item)
			<div data-gsap-element="item" class="__col grid grid-cols-1 lg:grid-cols-2 items-center gap-8 lg:gap-20 bg-white p-10 radius">

				@if (!empty($item['image_url']))
				<figure data-gsap-element="img" class="__img h-full">
					<picture>
						<img class="radius-img max-h-[504px] w-full object-cover" src="{{ $item['image_url'] }}" alt="{{ $item['image_alt'] }}">
					</picture>
				</figure>
				@endif

				<div class="__content">
					@if (!empty($item['icon_url']))
					<img data-gsap-element="icon" class="mb-4 w-16 h-16 object-contain" src="{{ $item['icon_url'] }}" alt="{{ $item['icon_alt'] }}">
					@endif

					<h2 data-gsap-element="header" class="text-h4 m-header text-primary">{{ $item['title'] }}</h2>

					@if (!empty($item['excerpt']))
					<div data-gsap-element="txt" class="__txt">
						<p>{{ $item['excerpt'] }}</p>
					</div>
					@endif

					<div class="m-btn">
						<x-button :href="$item['url']" variant="primary" data-gsap-element="btn">Zobacz</x-button>
					</div>
				</div>

			</div>

			@endforeach
		</div>
		@endif
	</div>

	</div>

</section>