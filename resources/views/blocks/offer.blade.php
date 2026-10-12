<!--- offer --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-offer relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main flex flex-col gap-8">
		@if(!empty($g_offer['label']) || !empty($g_offer['header']))
		<div class="__top flex flex-col gap-2">
			@if(!empty($g_offer['label']))
			<p data-gsap-element="header" class="__title text-primary-light">{{ $g_offer['label'] }}</p>
			@endif

			@if(!empty($g_offer['header']))
			<h2 data-gsap-element="header" class="">{{ $g_offer['header'] }}</h2>
			@endif
		</div>
		@endif

		@if(!empty($items))
		<div class="swiper offer-swiper !overflow-visible relative">
			<div class="swiper-wrapper">
				@foreach($items as $item)
				<a
					href="{{ $item['url'] }}"
					data-gsap-element="stagger"
					class="__card group swiper-slide min-h-[464px] relative radius overflow-hidden flex flex-col justify-end px-10 pt-80 pb-14">

					@if(!empty($item['image_url']))
					<img
						src="{{ $item['image_url'] }}"
						alt="{{ $item['image_alt'] }}"
						class="absolute inset-0 w-full h-full object-cover" />
					<div class="absolute inset-0 bg-linear-to-t from-main to-transparent"></div>
					@endif

					<div class="__txt relative flex flex-col gap-2 text-white">
						<p class="__title text-h6">{{ $item['title'] }}</p>

						@if(!empty($item['excerpt']))
						<p>{{ $item['excerpt'] }}</p>
						@endif
					</div>

					<span class="__arrow absolute top-8 right-8 w-14 h-14 rounded-full bg-white group-hover:bg-third transition-all text-primary flex items-center justify-center -rotate-45 group-hover:rotate-0">
						<svg width="13" height="12" viewBox="0 0 13 12" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="currentColor" />
						</svg>
					</span>
				</a>
				@endforeach
			</div>
		</div>

		<div class="__arrows flex justify-start gap-4">
			<button type="button" class="__prev w-14 h-14 rounded-full bg-secondary-300 hover:bg-secondary-400 transition-all text-white flex items-center justify-center cursor-pointer" aria-label="Poprzedni">
				<svg width="13" height="12" viewBox="0 0 13 12" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M0.270429 5.31498C0.270706 5.31469 0.270937 5.31435 0.27126 5.31406L5.08882 0.281803C5.44973 -0.0951806 6.03348 -0.0937777 6.39273 0.285093C6.75194 0.663916 6.75055 1.27664 6.38964 1.65367L3.15514 5.03226L12.078 5.03226C12.5872 5.03226 13 5.46552 13 6C13 6.53448 12.5872 6.96774 12.078 6.96774L3.15518 6.96774L6.3896 10.3463C6.75051 10.7234 6.75189 11.3361 6.39269 11.7149C6.03344 12.0938 5.44963 12.0951 5.08877 11.7182L0.271213 6.68594C0.270936 6.68565 0.270706 6.68531 0.270383 6.68502C-0.0907122 6.30673 -0.08956 5.69202 0.270429 5.31498Z" fill="currentColor" />
				</svg>
			</button>

			<button type="button" class="__next w-14 h-14 rounded-full bg-secondary-300 hover:bg-secondary-400 transition-colors text-white flex items-center justify-center cursor-pointer" aria-label="Następny">
				<svg width="13" height="12" viewBox="0 0 13 12" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="currentColor" />
				</svg>
			</button>
		</div>
		@endif
	</div>
</section>
