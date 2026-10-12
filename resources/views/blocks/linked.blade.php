<!--- linked --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-linked relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<img data-gsap-element="signet" class="absolute mix-blend-overlay opacity-20 top-1/2 -translate-y-1/2 right-0 translate-x-1/3 w-[704px] z-2 pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/signet.svg" alt="" />

	<div class="__wrapper c-main relative z-10">
		<div class="__col grid grid-cols-1 lg:grid-cols-2 items-center gap-8 lg:gap-20">
			@if (!empty($g_linked['image']))
			<figure data-gsap-element="img" class="__img order1">
				<picture>
					<img class="radius-img w-full h-full min-h-[704px] object-cover" src="{{ $g_linked['image']['url'] }}" alt="{{ $g_linked['image']['alt'] ?? '' }}">
				</picture>
			</figure>
			@endif

			<div class="__content order2">
				@if (!empty($g_linked['header']))
				<h2 data-gsap-element="header" class="text-h2 text-white m-header">{{ $g_linked['header'] }}</h2>
				@endif
				@if (!empty($g_linked['text']))
				<div data-gsap-element="txt" class="text-white">
					{!! $g_linked['text'] !!}
				</div>
				@endif

				@if (!empty($r_linked))
				<div data-gsap-element="stagger" class="__cards flex flex-col gap-4 m-btn">
					@foreach ($r_linked as $item)
					<a
						href="{{ $item['link']['url'] ?? '#' }}"
						class="__card group bg-third radius flex items-center gap-6 p-6">
						@if (!empty($item['icon']))
						<img class="w-16 h-16 object-contain shrink-0" src="{{ $item['icon']['url'] }}" alt="{{ $item['icon']['alt'] ?? '' }}">
						@endif
						<div class="flex-1">
							@if (!empty($item['title']))
							<p class="text-h5 !text-main">{{ $item['title'] }}</p>
							@endif
							@if (!empty($item['text']))
							<p class="!text-main">{{ $item['text'] }}</p>
							@endif
						</div>
						<span class="__arrow shrink-0 w-10 h-10 rounded-full bg-primary group-hover:bg-primary-400 transition-all text-white flex items-center justify-center">
							<svg width="13" height="12" viewBox="0 0 13 12" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="currentColor" />
							</svg>
						</span>
					</a>
					@endforeach
				</div>
				@endif
			</div>
		</div>
	</div>
</section>
