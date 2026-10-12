@php
$sectionClass = '';
$sectionClass .= $flip ? ' order-flip' : '';
$sectionClass .= $wide ? ' wide' : '';
$sectionClass .= $nomt ? ' !mt-0' : '';
$sectionClass .= $gap ? ' wider-gap' : '';
$sectionClass .= $lightbg ? ' section-light' : '';
$sectionClass .= $graybg ? ' section-gray' : '';
$sectionClass .= $whitebg ? ' section-white' : '';
$sectionClass .= $brandbg ? ' section-brand' : '';
@endphp

<div data-gsap-anim="section" @if(!empty($section_id)) id="{{ $section_id }}" @endif class="b-posts bg-[#f4f8fc] py-16 lg:py-24 -smt {{ $sectionClass }} {{ $section_class }}">
	<div class="c-main max-w-[1200px] mx-auto px-4">

		<div class="__content max-w-2xl  mb-12">
			<h2 data-gsap-element="title" class="text-primary  mb-6">{{ $posts_settings['title'] }}</h2>
			@if(!empty($posts_settings['text']))
			<div data-gsap-element="txt" class="text-gray-600 mb-8 prose mx-auto">
				{!! $posts_settings['text'] !!}
			</div>
			@endif

			@if (!empty($posts_settings['button']))
			<a data-gsap-element="btn" class="inline-flex items-center text-white rounded-full py-3 px-6 hover:bg-[#1e40af] transition-all duration-300" href="{{ $posts_settings['button']['url'] }}">
				{{ $posts_settings['button']['title'] }}
			</a>
			@endif
		</div>

		<div data-gsap-element="grid-layout" class="__posts-grid relative w-full">
			@if(!empty($posts))
			<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
				@foreach($posts as $post)
				<div class="group relative bg-white pl-8 py-8 radius shadow-sm hover:shadow-md transition-all duration-300 flex flex-col  text-left h-full">

					<div class="w-16 h-16 mb-5 flex items-center justify-center bg-primary rounded-full text-white ">
						@if($show_image && has_post_thumbnail($post->ID))
						<img src="{{ get_the_post_thumbnail_url($post->ID, 'thumbnail') }}"
							alt="{{ get_the_title($post->ID) }}"
							class="w-8 h-8 object-contain" />
						@else
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7">
							<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
						</svg>
						@endif
					</div>

					<h6 class="text-primary  mb-4 line-clamp-2">
						{{ get_the_title($post->ID) }}
					</h6>

					<a href="{{ get_permalink($post->ID) }}" class="text-primary  font-semibold inline-flex items-center gap-2.5 mt-auto  transition-colors after:absolute after:inset-0 after:z-10">
						<span>Dowiedz się więcej</span>
						<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 11 11" fill="none" class="shrink-0 transform group-hover:translate-x-1 transition-transform">
							<path d="M0.190696 1.10947C-0.0631106 0.85566 -0.0637325 0.444215 0.190005 0.190366C0.443846 -0.063475 0.855956 -0.0634751 1.1098 0.190366L9.45471 8.53527L9.45471 1.1949C9.45495 0.836202 9.74578 0.545237 10.1045 0.545107C10.4635 0.545112 10.755 0.836605 10.755 1.19559L10.755 10.1049C10.7549 10.4636 10.4639 10.7543 10.1052 10.7547L1.19523 10.7553C0.836249 10.7553 0.54475 10.4638 0.544746 10.1049C0.544748 9.74588 0.836248 9.45438 1.19523 9.45438L8.5356 9.45437L0.190696 1.10947Z" fill="#FF3437" />
						</svg>
					</a>

				</div>
				@endforeach
			</div>
			@else
			<div class="no-posts bg-white p-6 radius text-center text-gray-400 shadow-sm">
				Brak postów do wyświetlenia.
			</div>
			@endif
		</div>

	</div>
</div>