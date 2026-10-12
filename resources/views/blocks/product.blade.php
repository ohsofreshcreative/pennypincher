<!--- product -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-product bg-primary relative' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main relative pt-46 pb-30">
		<div class="__col grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-20">
			@if (!empty($g_product['gallery']))
			@php $galleryJson = json_encode(array_map(fn($img) => ['url' => $img['url'], 'alt' => $img['alt'] ?? '', 'thumb' => $img['sizes']['thumbnail'] ?? $img['url']], $g_product['gallery'])) @endphp
			<div data-gsap-element="img" class="__img order1"
				x-data="{
					active: 0,
					next: 0,
					fading: false,
					images: {{ $galleryJson }},
					goTo(index) {
						if (index === this.active || this.fading) return;
						this.next = index;
						this.fading = true;
					},
					prev() { this.goTo((this.active - 1 + this.images.length) % this.images.length); },
					nextt() { this.goTo((this.active + 1) % this.images.length); }
				}">

				<div class="relative overflow-hidden radius aspect-[4/4]">
					<img
						:src="images[active].url"
						:alt="images[active].alt"
						class="absolute inset-0 w-full h-full object-cover transition-opacity duration-300"
						:class="fading ? 'opacity-0' : 'opacity-100'"
						@transitionend="if (fading) { active = next; fading = false; }">

					@if(count($g_product['gallery']) > 1)
					<button type="button" @click="prev()"
						class="absolute left-3 top-1/2 -translate-y-1/2 z-10 w-14 h-14 flex items-center justify-center rounded-full bg-secondary hover:opacity-80 shadow transition-all duration-300">
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="12" viewBox="0 0 13 12" fill="none">
							<path d="M0.270429 5.31498C0.270706 5.31469 0.270937 5.31435 0.27126 5.31406L5.08882 0.281803C5.44973 -0.0951806 6.03348 -0.0937777 6.39273 0.285093C6.75194 0.663916 6.75055 1.27664 6.38964 1.65367L3.15514 5.03226L12.078 5.03226C12.5872 5.03226 13 5.46552 13 6C13 6.53448 12.5872 6.96774 12.078 6.96774L3.15518 6.96774L6.3896 10.3463C6.75051 10.7234 6.75189 11.3361 6.39269 11.7149C6.03344 12.0938 5.44963 12.0951 5.08877 11.7182L0.271213 6.68594C0.270936 6.68565 0.270706 6.68531 0.270383 6.68502C-0.0907122 6.30673 -0.08956 5.69202 0.270429 5.31498Z" fill="#FFF" />
						</svg>
					</button>
					<button type="button" @click="nextt()"
						class="absolute right-3 top-1/2 -translate-y-1/2 z-10 w-14 h-14 flex items-center justify-center rounded-full bg-secondary hover:opacity-80 shadow transition-all duration-300">
						<svg xmlns="http://www.w3.org/2000/svg" width="13" height="12" viewBox="0 0 13 12" fill="none">
							<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="#FFF" />
						</svg>
					</button>
					@endif
				</div>

				@if(count($g_product['gallery']) > 1)
				<div class="flex gap-2 mt-3">
					@foreach($g_product['gallery'] as $index => $thumb)
					<button type="button"
						@click="goTo({{ $index }})"
						:class="active === {{ $index }} ? 'ring-2 ring-primary opacity-100' : 'opacity-50 hover:opacity-80'"
						class="flex-1 min-w-0 aspect-square rounded overflow-hidden transition-all">
						<img src="{{ $thumb['sizes']['thumbnail'] ?? $thumb['url'] }}" alt="{{ $thumb['alt'] ?? '' }}" class="w-full h-full object-cover">
					</button>
					@endforeach
				</div>
				@endif

			</div>
			@endif

			<div class="__content order2">
				<h4 data-gsap-element="header" class="text-white m-header">{{ $g_product['header'] }}</h4>
				<div data-gsap-element="txt" class="__txt text-white">
					{!! $g_product['text'] !!}
				</div>
				<div class="__list mt-8">
					<p data-gsap-element="header" class="block text-h6 text-white m-header">{{ $g_product['title'] }}</p>
					<div data-gsap-element="txt" class="[&_*]:!text-white mt-4">
						{!! $g_product['list'] !!}
					</div>
				</div>

				<div class="inline-buttons m-btn">
					@if (!empty($g_product['button1']))
					<x-button
						:href="$g_product['button1']['url']"
						variant="secondary"
						class=""
						data-gsap-element="btn">
						{{ $g_product['button1']['title'] }}
					</x-button>
					@endif

					@if (!empty($g_product['button2']))
					<x-button
						:href="$g_product['button2']['url']"
						variant="white"
						class=""
						data-gsap-element="btn">
						{{ $g_product['button2']['title'] }}
					</x-button>
					@endif
				</div>

				@if (!empty($g_product['file']))
				<a data-gsap-element="link" href="{{ $g_product['file']['url'] }}" download class="inline-flex items-center gap-2 !text-white mt-6">
					<img src="{{ get_template_directory_uri() }}/resources/images/file.svg" alt="" class="w-5 h-5">
					Pobierz kartę specyfikacji
				</a>
				@endif

			</div>

		</div>
	</div>

</section>