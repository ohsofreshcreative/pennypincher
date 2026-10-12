<!--- about --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-about relative overflow-hidden -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])> 

	<img data-gsap-element="signet" class="absolute mix-blend-overlay opacity-20 left-0 -translate-x-1/4 bottom-0 translate-y-1/4 z-1 pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/decor.svg" alt="" />

	<div class="__wrapper c-main relative z-2 flex flex-col gap-8">
		<div class="__top grid grid-cols-1 lg:grid-cols-2 gap-8 items-center">
			<div class="__txt">
				@if(!empty($g_about['label']) || !empty($g_about['header']))
				<div class="flex flex-col gap-2">
					@if(!empty($g_about['label']))
					<p data-gsap-element="header" class="__title !text-primary-light">{{ $g_about['label'] }}</p>
					@endif

					@if(!empty($g_about['header']))
					<h2 data-gsap-element="header" class="m-header">{{ $g_about['header'] }}</h2>
					@endif
				</div>
				@endif

				@if(!empty($g_about['text']))
				<div data-gsap-element="txt" class="__content">
					{!! $g_about['text'] !!}
				</div>
				@endif

				@if(!empty($g_about['button']))
				<div class="inline-buttons m-btn">
					<x-button
						:href="$g_about['button']['url']"
						variant="primary"
						data-gsap-element="btn">
						{{ $g_about['button']['title'] }}
					</x-button>
				</div>
				@endif
			</div>

			@if(!empty($g_about['image']))
			<div data-gsap-element="img" class="__img">
				<figure class="m-0">
					<picture>
						<img
							src="{{ $g_about['image']['url'] }}"
							alt="{{ $g_about['image']['alt'] ?? '' }}"
							class="w-full h-full object-cover radius-img" />
					</picture>
				</figure>
			</div>
			@endif
		</div>

		@if(!empty($r_about))
		<div class="__cards grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
			@foreach($r_about as $card)
			<div data-gsap-element="stagger" class="__card bg-primary-50 radius flex flex-col gap-4 p-8">
				@if(!empty($card['icon']))
				<div class="__icon">
					{!! wp_get_attachment_image($card['icon']['ID'], 'full', false, ['alt' => $card['icon']['alt'] ?? '']) !!}
				</div>
				@endif

				<div class="__content flex flex-col gap-2">
					@if(!empty($card['title']))
					<p class="__title">{{ $card['title'] }}</p>
					@endif

					@if(!empty($card['text']))
					<p class="text-primary">{{ $card['text'] }}</p>
					@endif
				</div>
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>