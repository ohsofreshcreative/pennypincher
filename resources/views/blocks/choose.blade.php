<!--- choose --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-choose relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		@if (!empty($g_choose['header']) || !empty($g_choose['text']))
		<div class="__top w-full md:w-1/2">
			@if (!empty($g_choose['header']))
			<h2 data-gsap-element="header" class="text-h2 text-primary m-header">{{ $g_choose['header'] }}</h2>
			@endif
			@if (!empty($g_choose['text']))
			<div data-gsap-element="txt">{!! $g_choose['text'] !!}</div>
			@endif
		</div>
		@endif

		@if (!empty($r_choose))
		<div data-gsap-element="stagger" class="__cards grid grid-cols-1 lg:grid-cols-2 gap-6 mt-10">
			@foreach ($r_choose as $item)
			<div class="__card bg-main-light radius overflow-hidden flex flex-col p-8">
				@if (!empty($item['image']))
				<figure class="rounded-xl overflow-hidden m-0">
					<img class="w-full h-64 object-cover" src="{{ $item['image']['url'] }}" alt="{{ $item['image']['alt'] ?? '' }}">
				</figure>
				@endif
				<div class="flex flex-col flex-1 mt-10">
					@if (!empty($item['title']))
					<p class="text-h5 !text-white">{{ $item['title'] }}</p>
					@endif
					@if (!empty($item['text']))
					<p class="!text-white mt-2">{{ $item['text'] }}</p>
					@endif

					@if (!empty($item['r_features']))
					<ul class="flex flex-col gap-2 mt-6 mb-6">
						@foreach ($item['r_features'] as $feature)
						@if (!empty($feature['title']))
						<li class="flex items-center gap-3 text-white">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
								<path d="M22.8476 6.86801C21.8682 4.79212 20.3171 3.04893 18.363 1.82589C15.6451 0.129558 12.4257 -0.409331 9.3048 0.312312C6.18387 1.02927 3.52686 2.92241 1.8305 5.64028C0.129447 8.35816 -0.409453 11.5728 0.312204 14.6983C1.03386 17.8192 2.92704 20.4761 5.64028 22.1725C7.55221 23.3674 9.7453 24 11.9899 24H12.1352C14.3705 23.9719 16.5448 23.3252 18.4286 22.135C18.9769 21.7882 19.1362 21.0666 18.7894 20.5183C18.4427 19.97 17.721 19.8107 17.1727 20.1575C15.6591 21.1181 13.9065 21.6383 12.1071 21.6617C10.2608 21.6851 8.45662 21.1743 6.8821 20.195C4.6937 18.8267 3.17072 16.6899 2.59433 14.1782C2.01794 11.6665 2.44906 9.0798 3.8174 6.89144C6.63842 2.37883 12.6085 1.00115 17.1212 3.82212C18.6957 4.80618 19.9422 6.20729 20.7295 7.8755C21.498 9.50154 21.7979 11.3056 21.5964 13.0863C21.5261 13.7283 21.9854 14.3094 22.632 14.3797C23.274 14.4499 23.8551 13.9907 23.9254 13.3441C24.1738 11.1276 23.7989 8.88768 22.8476 6.86801Z" fill="#3A6CA1" />
								<path d="M15.8747 7.93173L10.2233 13.583L8.1286 11.4884C7.66936 11.0292 6.92896 11.0292 6.46972 11.4884C6.01049 11.9476 6.01049 12.688 6.46972 13.1472L9.39384 16.0713C9.62346 16.3009 9.92337 16.4134 10.2233 16.4134C10.5232 16.4134 10.8231 16.3009 11.0527 16.0713L17.5289 9.59058C17.9881 9.13135 17.9881 8.39096 17.5289 7.93173C17.0696 7.47719 16.3292 7.47719 15.8747 7.93173Z" fill="#3A6CA1" />
							</svg>
							{{ $feature['title'] }}
						</li>
						@endif
						@endforeach
					</ul>
					@endif

					@if (!empty($item['button']))
					<x-button
						:href="$item['button']['url']"
						variant="white"
						class="mt-auto">
						{{ $item['button']['title'] }}
					</x-button>
					@endif
				</div>
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>