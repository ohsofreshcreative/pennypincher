<!--- checks -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-checks relative -smt overflow-hidden' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	@if ($bgshape)
	<img class="__bg-shape absolute inset-y-0 right-0 w-auto pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/bg-shape.svg" alt="">
	@endif

	<div class="__wrapper c-main relative">

		<div class="__col grid grid-cols-1 lg:grid-cols-2 items-center gap-8 lg:gap-20">
			<div>
				@if (!empty($g_checks['image']))
				<figure data-gsap-element="img" class="__img h-full order1">
					<picture>
						<img class="radius-img max-h-[280px] w-full object-cover m-img" src="{{ $g_checks['image']['url'] }}" alt="{{ $g_checks['image']['alt'] ?? '' }}">
					</picture>
				</figure>
				@endif

				<h2 data-gsap-element="header" class="text-h4 m-header text-primary">{{ $g_checks['header'] }}</h2>
				<div data-gsap-element="txt" class="__txt">
					{!! $g_checks['text'] !!}
				</div>
				@if (!empty($g_checks['button1']) || !empty($g_checks['button2']))
				<div class="inline-buttons m-btn">
					@if (!empty($g_checks['button1']))
					<x-button
						:href="$g_checks['button1']['url']"
						variant="primary"
						class=""
						data-gsap-element="btn">
						{{ $g_checks['button1']['title'] }}
					</x-button>
					@endif
					@if (!empty($g_checks['button2']))
					<x-button
						:href="$g_checks['button2']['url']"
						variant="secondary"
						class=""
						data-gsap-element="btn">
						{{ $g_checks['button2']['title'] }}
					</x-button>
					@endif
				</div>
				@endif
			</div>

			<div class="__checks h-full order2 flex flex-col justify-between gap-4">

				@foreach ($r_checks as $item)
				<div data-gsap-element="card" class="__card relative bg-white radius flex gap-6 p-8">
					<img class="" src="{{ get_template_directory_uri() }}/resources/images/check.svg" alt="" />
					@if (!empty($item['title']))
					<p class="text-h7">{{ $item['title'] }}</p>
					@endif
				</div>
				@endforeach

			</div>

		</div>
	</div>

</section>