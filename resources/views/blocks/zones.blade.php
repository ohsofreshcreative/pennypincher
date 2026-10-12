<!--- zones --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-zones relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<img data-gsap-element="signet" class="absolute mix-blend-overlay opacity-20 top-2/3 -translate-y-1/2 left-0 -translate-x-1/6 z-1 pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/decor.svg" alt="" />

	<div class="__wrapper c-main grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-20 items-center">
		<div class="__col">
			@if (!empty($g_zones['label']))
			<p data-gsap-element="header" class="__title text-secondary-accent!">{{ $g_zones['label'] }}</p>
			@endif
			@if (!empty($g_zones['header']))
			<h2 data-gsap-element="header" class="text-h2 text-white m-header">{{ $g_zones['header'] }}</h2>
			@endif
			@if (!empty($g_zones['text']))
			<div data-gsap-element="txt" class="text-white">
				{!! $g_zones['text'] !!}
			</div>
			@endif

			@if (!empty($features))
			<ul data-gsap-element="list" class="__list flex flex-col gap-2 m-btn">
				@foreach ($features as $item)
				@if (!empty($item['title']))
				<li class="flex items-center gap-3 text-white">
					<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" fill="none">
						<path d="M30.4635 9.15735C29.1576 6.38949 27.0895 4.06524 24.484 2.43452C20.8601 0.172744 16.5677 -0.545775 12.4064 0.416416C8.24516 1.37236 4.70248 3.89655 2.44066 7.52038C0.172596 11.1442 -0.545938 15.4303 0.416272 19.5977C1.37848 23.7589 3.90272 27.3015 7.52038 29.5633C10.0696 31.1565 12.9937 32 15.9866 32H16.1803C19.1606 31.9625 22.0597 31.1003 24.5715 29.5133C25.3025 29.0509 25.515 28.0888 25.0526 27.3577C24.5902 26.6267 23.628 26.4143 22.897 26.8766C20.8789 28.1575 18.5421 28.851 16.1428 28.8823C13.681 28.9135 11.2755 28.2325 9.17613 26.9266C6.25826 25.1022 4.22762 22.2531 3.4591 18.9042C2.69059 15.5553 3.26541 12.1064 5.08986 9.18859C8.85123 3.17178 16.8113 1.33487 22.8283 5.09616C24.9276 6.40824 26.5896 8.27639 27.6393 10.5007C28.664 12.6687 29.0639 15.0742 28.7952 17.4484C28.7015 18.3044 29.3138 19.0792 30.176 19.1729C31.032 19.2666 31.8068 18.6543 31.9005 17.7921C32.2317 14.8368 31.7318 11.8502 30.4635 9.15735Z" fill="#946961" />
						<path d="M21.1663 10.5756L13.631 18.1107L10.8381 15.3179C10.2258 14.7056 9.23861 14.7056 8.6263 15.3179C8.01398 15.9302 8.01398 16.9174 8.6263 17.5297L12.5251 21.4284C12.8313 21.7346 13.2312 21.8845 13.631 21.8845C14.0309 21.8845 14.4308 21.7346 14.737 21.4284L23.3718 12.7874C23.9842 12.1751 23.9842 11.1879 23.3718 10.5756C22.7595 9.96959 21.7723 9.96959 21.1663 10.5756Z" fill="#946961" />
					</svg>
					{{ $item['title'] }}
				</li>
				@endif
				@endforeach
			</ul>
			@endif
		</div>

		@if (!empty($r_zones))
		<div data-gsap-element="stagger" class="__cards flex flex-col gap-4">
			@foreach ($r_zones as $item)
			<div class="__card bg-white radius flex gap-6 p-8">
				@if (!empty($item['icon']))
				<img class="__icon w-16 h-16 object-contain shrink-0" src="{{ $item['icon']['url'] }}" alt="{{ $item['icon']['alt'] ?? '' }}">
				@endif
				<div>
					@if (!empty($item['title']))
					<p class="text-h6 text-primary">{{ $item['title'] }}</p>
					@endif
					@if (!empty($item['text']))
					<p class="mt-2">{{ $item['text'] }}</p>
					@endif
				</div>
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>