<!--- gastro --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-gastro relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<img data-gsap-element="signet" class="absolute mix-blend-overlay opacity-20 top-1/2 -translate-y-1/2 right-0 translate-x-1/3 z-1 pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/decor.svg" alt="" />

	<div class="__wrapper c-main relative z-2 flex flex-col gap-8">
		@if(!empty($g_gastro['header']) || !empty($g_gastro['text']))
		<div class="__top lg:w-2/3">
			<div class="__txt flex flex-col gap-6">
				@if(!empty($g_gastro['header']))
				<h2 data-gsap-element="header" class="">{{ $g_gastro['header'] }}</h2>
				@endif

				@if(!empty($g_gastro['text']))
				<div data-gsap-element="txt" class="text-primary-900">
					{!! $g_gastro['text'] !!}
				</div>
				@endif
			</div>
		</div>
		@endif

		@if(!empty($r_gastro))
		<div class="__cards grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
			@foreach($r_gastro as $card)
			<div
				data-gsap-element="stagger"
				class="__card relative h-[484px] radius overflow-hidden flex flex-col justify-end p-10 pb-14">

				@if(!empty($card['image']))
				<img
					src="{{ $card['image']['url'] }}"
					alt="{{ $card['image']['alt'] ?? '' }}"
					class="absolute inset-0 w-full h-full object-cover" />
				<div class="absolute inset-0 bg-linear-to-t from-main to-transparent"></div>
				@endif

				<div class="__txt relative flex flex-col gap-2 text-white">
					@if(!empty($card['title']))
					<p class="__title text-h6">{{ $card['title'] }}</p>
					@endif

					@if(!empty($card['text']))
					<div>{!! $card['text'] !!}</div>
					@endif
				</div>
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>
