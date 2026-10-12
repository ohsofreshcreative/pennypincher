<!--- terms --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-terms relative -smt -smb' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		@if (!empty($g_terms['header']))
		<h3 data-gsap-element="header" class="text-h2 text-primary m-header">{{ $g_terms['header'] }}</h3>
		@endif

		@if (!empty($terms))
		<div data-gsap-element="stagger" class="__tabs flex flex-col gap-4 mt-4">
			@foreach ($terms as $item)
			<div class="__tab rounded-2xl bg-white b-shadow flex items-center justify-between gap-4 p-6">
				@if (!empty($item['title']))
				<p class="__title text-h7 text-primary">{{ $item['title'] }}</p>
				@endif
				<x-button
					:href="$item['file']['url'] ?? '#'"
					variant="primary-small"
					target="_blank"
					rel="noopener"
					data-gsap-element="btn">
					Zobacz
				</x-button>
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>
