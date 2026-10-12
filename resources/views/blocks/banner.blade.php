<!--- banner --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-banner relative -spt overflow-visible' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<img data-gsap-element="signet" class="absolute mix-blend-overlay opacity-20 z-2 pointer-events-none h-[704px] left-0 -translate-x-1/2 top-1/2 -translate-y-1/2" src="{{ get_template_directory_uri() }}/resources/images/signet.svg" alt="" />

	<div class="__wrapper c-main relative z-10 -spt">
		<div class="__content flex flex-col gap-6 max-w-3xl">
			<h1 data-gsap-element="header" class="text-h2">
				{{ $g_banner['title'] }}
			</h1>
			@if (!empty($g_banner['text']))
			<div data-gsap-element="text" class="__txt">
				{!! $g_banner['text'] !!}
			</div>
			@endif
		</div>
	</div>
</section>