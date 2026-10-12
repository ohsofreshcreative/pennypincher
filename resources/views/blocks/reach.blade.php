<!--- reach --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-reach relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start lg:items-stretch">

			<div class="__form bg-lighter radius p-10">
				@if (!empty($g_reach['header']))
				<p data-gsap-element="header" class="text-h4 text-primary">{{ $g_reach['header'] }}</p>
				@endif

				@if (!empty($g_reach['text']))
				<div data-gsap-element="txt" class="__txt text-lg [&_p]:font-semibold! mt-3">
					{!! $g_reach['text'] !!}
				</div>
				@endif

				@if (!empty($g_reach['shortcode']))
				<div data-gsap-element="form" class="__shortcode mt-6">
					{!! do_shortcode($g_reach['shortcode']) !!}
				</div>
				@endif
			</div>

			@if (!empty($g_reach['map']))
			<div data-gsap-element="img" class="__map radius-img overflow-hidden lg:h-full [&_iframe]:block [&_iframe]:h-full [&_iframe]:w-full">
				{!! $g_reach['map'] !!}
			</div>
			@endif

		</div>
	</div>

</section>
