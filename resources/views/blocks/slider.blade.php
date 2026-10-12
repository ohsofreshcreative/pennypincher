<!--- slider --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-slider relative bg-secondary-lighter -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	@if ($bgshape)
	<img class="absolute max-h-32 top-0 right-0 z-10" src="{{ get_template_directory_uri() }}/resources/images/top-shape.svg" alt="" />
	@endif

	<div class="c-main section-py">
		@if (!empty($slider_title))
		<div class="__wrapper relative z-20">
			<h2 class="text-primary">{{ $slider_title }}</h2>
		</div>
		@endif
		<div class="swiper slider-standard relative !overflow-visible z-20 mt-14">
			<div class="swiper-wrapper">
				@foreach ($slides as $slide)
				<div class="swiper-slide">
					<div class="grid grid-cols-1 lg:grid-cols-2 items-center gap-6 h-full">
						@if (!empty($slide['image_url']))
						<figure class="relative m-0 h-full min-h-52 overflow-hidden">
							<img
								src="{{ $slide['image_url'] }}"
								alt="{{ $slide['image_alt'] }}"
								class="w-full h-full min-h-52 object-cover radius-img">
							<span class="absolute top-0 left-0 w-14 h-14 bg-secondary text-white text-lg font-header !font-medium flex items-center justify-center rounded-2xl z-10">
								{{ str_pad($loop->index + 1, 2, '0', STR_PAD_LEFT) }}
							</span>
							<div class="absolute top-0 left-0 w-18 h-18 bg-secondary-lighter rounded-br-3xl"></div>
						</figure>
						@endif
						<div class="__content ">
							@if (!empty($slide['icon_url']))
							<img class="mb-4 w-14 h-14 object-contain" src="{{ $slide['icon_url'] }}" alt="{{ $slide['icon_alt'] }}">
							@endif
							<p class="text-h5 text-primary mb-4">{{ $slide['title'] }}</p>
							@if (!empty($slide['excerpt']))
							<p class="mb-6">{{ $slide['excerpt'] }}</p>
							@endif
							<x-button class="m-btn" :href="$slide['url']" variant="underline">Więcej</x-button>
						</div>
					</div>
				</div>
				@endforeach
			</div>

			<div data-gsap-element="arrows" class="w-full z-10 flex flex-col md:flex-row items-center pointer-events-none gap-4 mt-16">
				<div class="flex items-center pointer-events-none gap-4 order-2 md:order-1">
					<div class="__prev rounded-full bg-secondary h-14 w-14 flex items-center justify-center pointer-events-auto cursor-pointer transition-all duration-400 shrink-0">
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="12" viewBox="0 0 13 12" fill="none">
							<path d="M0.270429 5.31498C0.270706 5.31469 0.270937 5.31435 0.27126 5.31406L5.08882 0.281803C5.44973 -0.0951806 6.03348 -0.0937777 6.39273 0.285093C6.75194 0.663916 6.75055 1.27664 6.38964 1.65367L3.15514 5.03226L12.078 5.03226C12.5872 5.03226 13 5.46552 13 6C13 6.53448 12.5872 6.96774 12.078 6.96774L3.15518 6.96774L6.3896 10.3463C6.75051 10.7234 6.75189 11.3361 6.39269 11.7149C6.03344 12.0938 5.44963 12.0951 5.08877 11.7182L0.271213 6.68594C0.270936 6.68565 0.270706 6.68531 0.270383 6.68502C-0.0907122 6.30673 -0.08956 5.69202 0.270429 5.31498Z" fill="#FFF" />
						</svg>
					</div>
					<div class="__next rounded-full bg-secondary h-14 w-14 flex items-center justify-center pointer-events-auto cursor-pointer transition-all duration-300 shrink-0">
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="12" viewBox="0 0 13 12" fill="none">
							<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="#FFF" />
						</svg>
					</div>
				</div>

				{{-- Pasek postępu --}}
				<div class="__progress w-full md:flex-1 h-1 bg-white rounded-full overflow-hidden md:ml-10 order-1 md:order-2">
					<div class="__progress-fill h-full bg-secondary rounded-full transition-[width] duration-300" style="width: 0%"></div>
				</div
					</div>
			</div>
</section>