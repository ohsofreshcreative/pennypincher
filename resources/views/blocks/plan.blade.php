<!--- plan --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-plan relative overflow-hidden -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<img class="absolute mix-blend-overlay opacity-20 top-1/2 -translate-y-7/12 right-0 translate-x-1/3 z-1 pointer-events-none w-[1504px]" src="{{ get_template_directory_uri() }}/resources/images/signet.svg" alt="" />

	<div class="__wrapper c-main relative z-2 flex flex-col section-row">
		@if(!empty($g_plan['label']) || !empty($g_plan['header']))
		<div class="__top flex flex-col gap-2">
			@if(!empty($g_plan['label']))
			<p data-gsap-element="header" class="__title text-white">{{ $g_plan['label'] }}</p>
			@endif

			@if(!empty($g_plan['header']))
			<h2 data-gsap-element="header" class="">{{ $g_plan['header'] }}</h2>
			@endif
		</div>
		@endif

		@if(!empty($plan))
		<div class="swiper plan-swiper w-full overflow-visible!">
			<div class="__cards swiper-wrapper md:grid! md:grid-cols-3 lg:grid-cols-5 md:gap-6">
				@foreach($plan as $card)
				@php($url = $card['link']['url'] ?? null)

				<div class="swiper-slide h-auto! sm:w-[70%]! md:w-full!">
					<div data-gsap-element="stagger" class="flex h-full">
						<{{ $url ? 'a' : 'div' }}
							@if($url) href="{{ $url }}" @endif
							@if(!empty($card['link']['target'])) target="{{ $card['link']['target'] }}" @endif
							class="__card group w-full bg-primary-lighter hover:bg-primary-lighter/50 transition-all! duration-300! b-shadow radius p-6 flex flex-col justify-between gap-6">

							<div class="__content flex flex-col items-center gap-6">
								@if(!empty($card['icon']))
								<div class="__icon">
									{!! wp_get_attachment_image($card['icon']['ID'], 'full', false, ['alt' => $card['icon']['alt'] ?? '']) !!}
								</div>
								@endif

								@if(!empty($card['title']))
								<p class="__title text-h7 text-primary text-center">{{ $card['title'] }}</p>
								@endif
							</div>

							<span class="__arrow text-primary group-hover:text-white transition-colors text-center mx-auto mt-6">
								<svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M0 20C0 8.95431 8.95431 0 20 0V0C31.0457 0 40 8.95431 40 20V20C40 31.0457 31.0457 40 20 40V40C8.95431 40 0 31.0457 0 20V20Z" class="fill-white group-hover:fill-main transition-colors duration-300" />
									<path d="M20.9939 15.3417C20.8952 15.2498 20.8163 15.1389 20.7617 15.0155C20.7071 14.8922 20.678 14.7589 20.6761 14.6236C20.6742 14.4884 20.6996 14.3539 20.7507 14.2283C20.8019 14.1026 20.8777 13.9884 20.9738 13.8924C21.0698 13.7964 21.184 13.7205 21.3096 13.6694C21.4353 13.6182 21.5697 13.5929 21.705 13.5947C21.8402 13.5966 21.9735 13.6257 22.0969 13.6803C22.2203 13.7349 22.3312 13.8139 22.423 13.9125L27.7756 19.2651C27.9636 19.4533 28.0685 19.7089 28.0676 19.9759C28.0665 20.2428 27.9596 20.4993 27.7703 20.6889L22.3772 26.082C22.2846 26.1813 22.1731 26.2611 22.0493 26.3166C21.9255 26.3721 21.792 26.4022 21.6568 26.4051C21.5215 26.408 21.3872 26.3837 21.2619 26.3334C21.1367 26.2833 21.023 26.2083 20.9277 26.113C20.8324 26.0177 20.7575 25.904 20.7072 25.7788C20.657 25.6535 20.6327 25.5193 20.6356 25.384C20.6385 25.2487 20.6686 25.1152 20.7241 24.9915C20.7796 24.8676 20.8594 24.7561 20.9588 24.6635L24.6261 20.9963L12.936 20.9764C12.6689 20.9774 12.413 20.8722 12.2248 20.684C12.0366 20.4958 11.9314 20.2399 11.9324 19.9727C11.9334 19.7056 12.0405 19.4489 12.2302 19.2593C12.4198 19.0697 12.6764 18.9626 12.9436 18.9616L24.6336 18.9814L20.9939 15.3417Z" fill="currentColor" />
								</svg>
							</span>
						</{{ $url ? 'a' : 'div' }}>
					</div>
				</div>
				@endforeach
			</div>

			<div class="__arrows flex flex-col items-center gap-4 mt-16 md:hidden">
				<div class="flex items-center gap-4 order-2">
					<button type="button" class="__prev rounded-full bg-secondary text-white h-14 w-14 flex items-center justify-center cursor-pointer shrink-0" aria-label="Poprzedni slajd">
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="12" viewBox="0 0 13 12" fill="none">
						<path d="M0.270429 5.31498C0.270706 5.31469 0.270937 5.31435 0.27126 5.31406L5.08882 0.281803C5.44973 -0.0951806 6.03348 -0.0937777 6.39273 0.285093C6.75194 0.663916 6.75055 1.27664 6.38964 1.65367L3.15514 5.03226L12.078 5.03226C12.5872 5.03226 13 5.46552 13 6C13 6.53448 12.5872 6.96774 12.078 6.96774L3.15518 6.96774L6.3896 10.3463C6.75051 10.7234 6.75189 11.3361 6.39269 11.7149C6.03344 12.0938 5.44963 12.0951 5.08877 11.7182L0.271213 6.68594C0.270936 6.68565 0.270706 6.68531 0.270383 6.68502C-0.0907122 6.30673 -0.08956 5.69202 0.270429 5.31498Z" fill="currentColor" />
						</svg>
					</button>
					<button type="button" class="__next rounded-full bg-secondary text-white h-14 w-14 flex items-center justify-center cursor-pointer shrink-0" aria-label="Następny slajd">
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="12" viewBox="0 0 13 12" fill="none">
						<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="currentColor" />
						</svg>
					</button>
				</div>

				<div class="__progress w-full h-1 bg-white rounded-full overflow-hidden order-1">
					<div class="__progress-fill h-full bg-secondary rounded-full transition-[width] duration-300" style="width: 0%"></div>
				</div>
			</div>
		</div>
		@endif
	</div>
</section>
