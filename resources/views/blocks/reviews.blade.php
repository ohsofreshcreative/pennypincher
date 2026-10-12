<!--- reviews -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-reviews relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>
	<div class="__wrapper c-main">
		<div class="__content">
			@if(!empty($header))
			<div data-gsap-element="header" class="__wrapper text-primary block w-full md:w-1/2 pb-10">
				<h3 class="text-">{{ $header }}</h3>
			</div>
			@endif

			<div class="swiper reviews-swiper !overflow-visible">
				<div data-gsap-element="swiper" class="swiper-wrapper">
					@foreach($r_reviews as $card)
					<div class="swiper-slide">
						<div class="__card relative bg-white radius h-full">

							<div class="relative z-10 flex flex-col gap-4 h-full pt-16 p-8 mt-6">
								@if(!empty($card['txt']))
								<div class="review-content-wrapper">
									<div class=""><img src="/wp-content/uploads/2026/07/quote.svg" alt="" /></div>
									<p class="text-h5 mt-14 block">{!! $card['header'] !!}</p>
									<div class="__txt mt-4 line-clamp-6">{!! $card['txt'] !!}</div>
									<button class="btn-more hidden underline text-primary font-bold mt-2 cursor-pointer">Zobacz całość</button>
								</div>
								@endif

								<div class="flex flex-col lg:flex-row items-start lg:items-center gap-2 mt-auto">
									<img src="{{ $card['image']['url'] }}" alt="{{ $card['image']['alt'] ?? '' }}" class="border border-gray-200 rounded-lg w-36 h-16 object-contain p-4" />
									<div class="__who">
										<b class="font-header text-xl">{{ $card['name'] }}</b>
										<p class="text-sm text-gray-500">{{ $card['position'] }}</p>
									</div>
								</div>
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
					</div>
				</div>

			</div>

			<!-- <div class="mt-10">
				<img src="/wp-content/uploads/2025/12/google-1.svg" />
				<a class="!underline">Sprawdź wszystkie opinie</a>
			</div> -->
		</div>
	</div>
	<div id="review-popup" class="review-popup fixed inset-0 bg-black/50 bg-opacity-70 z-[999] flex items-center justify-center p-4 hidden">
		<div class="review-popup__content bg-white rounded-lg shadow-xl p-8 md:p-12 max-w-3xl w-full relative">
			<button class="review-popup__close absolute top-4 right-4 text-gray-500 hover:text-gray-800 transition-colors">
				<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
			<div id="review-popup-text" class="prose max-w-none mb-4">
			</div>
			<div class="flex items-center gap-4">
				<img src="/wp-content/uploads/2026/01/stars.svg" class="h-5" alt="" />
				<b id="review-popup-author" class="font-header text-xl">
				</b>
			</div>
		</div>
	</div>
</section>