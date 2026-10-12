<!--- news --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-news relative -spt -spb' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main flex flex-col gap-12">
		@if(!empty($g_news['header']) || !empty($g_news['text']) || !empty($g_news['button']))
		<div class="__top flex flex-col lg:flex-row lg:items-end gap-12">
			<div class="__txt flex flex-col gap-6 lg:w-2/3">
				@if(!empty($g_news['header']))
				<h2 data-gsap-element="header" class="">{{ $g_news['header'] }}</h2>
				@endif

				@if(!empty($g_news['text']))
				<div data-gsap-element="txt" class="text-primary-900">
					{!! $g_news['text'] !!}
				</div>
				@endif
			</div>

			@if(!empty($g_news['button']))
			<div class="inline-buttons lg:ml-auto">
				<x-button :href="$g_news['button']['url']" variant="primary" data-gsap-element="btn">
					{{ $g_news['button']['title'] }}
				</x-button>
			</div>
			@endif
		</div>
		@endif

		@if(!empty($items))
		<div class="__cards flex flex-col gap-4" x-data="{ active: 0 }">
			@foreach($items as $item)
			<a
				href="{{ $item['url'] }}"
				data-gsap-element="stagger"
				@mouseenter="active = {{ $loop->index }}"
				@focus="active = {{ $loop->index }}"
				:class="{ 'is-active': active === {{ $loop->index }} }"
				@class([
					'__card group block radius border border-primary-light p-10',
					'is-active' => $loop->first,
				])>

				<div class="grid grid-cols-1 lg:grid-cols-[auto_1fr_1fr] gap-8 lg:gap-20 items-start">
					@if(!empty($item['category']))
					<span class="__chip radius bg-third text-sm font-semibold! px-4 py-2 w-max">{{ $item['category'] }}</span>
					@endif

					<div class="__content flex flex-col gap-8">
						<p class="__title text-h6">{{ $item['title'] }}</p>

						@if(!empty($item['image_url']))
						<div class="__expand">
							<div class="overflow-hidden">
								<img
									src="{{ $item['image_url'] }}"
									alt="{{ $item['image_alt'] }}"
									class="w-full h-60 object-cover radius" />
							</div>
						</div>
						@endif
					</div>

					<div class="__col flex flex-col gap-8 self-stretch">
						@if(!empty($item['excerpt']))
						<div class="text-primary-400 !font-semibold">{{ $item['excerpt'] }}</div>
						@endif

						<div class="__expand mt-auto self-end">
							<div>
								<span class="__arrow w-14 h-14 rounded-full bg-background-navy text-white flex items-center justify-center -rotate-45 group-hover:rotate-0 group-hover:bg-third-600 transition-all">
									<svg width="13" height="12" viewBox="0 0 13 12" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="currentColor" />
									</svg>
								</span>
							</div>
						</div>
					</div>
				</div>
			</a>
			@endforeach
		</div>
		@endif
	</div>
</section>
