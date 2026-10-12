<!--- wysiwyg -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-wysiwyg relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main relative">
		@if (!empty($g_wysiwyg['header']))
		<h4 data-gsap-element="header" class="text-primary">{{ $g_wysiwyg['header'] }}</h4>
		@endif

		<div>
			<div data-gsap-element="txt" class="__txt mt-4">
				{!! $g_wysiwyg['txt'] !!}
			</div>
			@if (!empty($g_wysiwyg['button']))
			<x-button
				:href="$g_wysiwyg['button']['url']"
				variant="primary"
				class="mt-6"
				data-gsap-element="btn">
				{{ $g_wysiwyg['button']['title'] }}
			</x-button>
			@endif
		</div>
	</div>

</section>