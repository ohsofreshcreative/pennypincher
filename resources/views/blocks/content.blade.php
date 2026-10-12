<!--- content -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-content relative -smt overflow-hidden' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	@if ($bgshape)
		<img class="__bg-shape absolute inset-y-0 right-0 w-auto pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/bg-shape.svg" alt="">
	@endif

	<div class="__wrapper c-main relative">

		<div class="__col grid grid-cols-1 lg:grid-cols-2 items-center gap-8 lg:gap-20">
			@if (!empty($g_content['image']))
			<figure data-gsap-element="img" class="__img h-full order1">
				<picture>
					<img class="radius-img h-[504px] max-h-[504px] w-full object-cover" src="{{ $g_content['image']['url'] }}" alt="{{ $g_content['image']['alt'] ?? '' }}">
				</picture>
			</figure>
			@endif

			<div class="__content order2">
				<h2 data-gsap-element="header" class="text-h4 m-header text-primary">{{ $g_content['header'] }}</h2>

				<div data-gsap-element="txt" class="__txt">
					{!! $g_content['text'] !!}
				</div>

				@if (!empty($g_content['button1']) || !empty($g_content['button2']))
				<div class="inline-buttons m-btn">
					@if (!empty($g_content['button1']))
					<x-button
						:href="$g_content['button1']['url']"
						variant="primary"
						class=""
						data-gsap-element="btn">
						{{ $g_content['button1']['title'] }}
					</x-button>
					@endif

					@if (!empty($g_content['button2']))
					<x-button
						:href="$g_content['button2']['url']"
						variant="outline"
						class=""
						data-gsap-element="btn">
						{{ $g_content['button2']['title'] }}
					</x-button>
					@endif
				</div>
				@endif

			</div>

		</div>
	</div>

</section>