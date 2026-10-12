<!--- proces --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-proces relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<img class="absolute mix-blend-overlay opacity-10 top-1/2 -translate-y-7/12 right-0 translate-x-1/3 z-1 pointer-events-none w-[1004px]" src="{{ get_template_directory_uri() }}/resources/images/signet.svg" alt="" />

	<div class="__wrapper c-main">
		@if (!empty($g_proces['header']) || !empty($g_proces['txt']))
		<div class="__top max-w-2xl">
			@if (!empty($g_proces['header']))
			<h2 data-gsap-element="header" class="text-h2 text-primary m-header">{{ strip_tags($g_proces['header']) }}</h2>
			@endif
			@if (!empty($g_proces['txt']))
			<div data-gsap-element="txt">{!! $g_proces['txt'] !!}</div>
			@endif
		</div>
		@endif

		@if (!empty($r_proces))
		<div class="swiper proces-swiper !overflow-visible relative mt-10">
			<div class="swiper-wrapper">
				@foreach ($r_proces as $item)
				<div data-gsap-element="stagger" class="__card swiper-slide relative flex flex-col bg-primary-50 radius p-6 overflow-hidden">
					<div class="relative z-10">
						@if (!empty($item['number']))
						<p class="text-h2 text-primary">{{ $item['number'] }}</p>
						@endif
						@if (!empty($item['title']))
						<p class="text-h6 text-primary mt-4">{{ $item['title'] }}</p>
						@endif
						@if (!empty($item['txt']))
						<div class="mt-2">{!! $item['txt'] !!}</div>
						@endif
					</div>
					@if (!empty($item['image']))
					<img class="__icon absolute right-6 bottom-6 w-24 h-24 object-contain z-0" src="{{ $item['image']['url'] }}" alt="{{ $item['image']['alt'] ?? '' }}" />
					@endif
				</div>
				@endforeach
			</div>
		</div>

		<div class="__arrows flex justify-start gap-4 mt-8">
			<button type="button" class="__prev w-14 h-14 rounded-full bg-primary hover:bg-primary-400 transition-all text-white flex items-center justify-center cursor-pointer" aria-label="Poprzedni">
				<svg width="13" height="12" viewBox="0 0 13 12" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M0.270429 5.31498C0.270706 5.31469 0.270937 5.31435 0.27126 5.31406L5.08882 0.281803C5.44973 -0.0951806 6.03348 -0.0937777 6.39273 0.285093C6.75194 0.663916 6.75055 1.27664 6.38964 1.65367L3.15514 5.03226L12.078 5.03226C12.5872 5.03226 13 5.46552 13 6C13 6.53448 12.5872 6.96774 12.078 6.96774L3.15518 6.96774L6.3896 10.3463C6.75051 10.7234 6.75189 11.3361 6.39269 11.7149C6.03344 12.0938 5.44963 12.0951 5.08877 11.7182L0.271213 6.68594C0.270936 6.68565 0.270706 6.68531 0.270383 6.68502C-0.0907122 6.30673 -0.08956 5.69202 0.270429 5.31498Z" fill="currentColor" />
				</svg>
			</button>

			<button type="button" class="__next w-14 h-14 rounded-full bg-primary hover:bg-primary-400 transition-colors text-white flex items-center justify-center cursor-pointer" aria-label="Następny">
				<svg width="13" height="12" viewBox="0 0 13 12" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="currentColor" />
				</svg>
			</button>
		</div>
		@endif
	</div>

</section>
