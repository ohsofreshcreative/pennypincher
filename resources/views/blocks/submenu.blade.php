<!--- submenu --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-submenu relative bg-third z-30' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		<nav class="__menu flex items-center gap-8 overflow-x-auto">
			@foreach ($r_submenu as $item)
			@if (!empty($item['link']))
			<a
				href="{{ $item['link']['url'] }}"
				@if(!empty($item['link']['target'])) target="{{ $item['link']['target'] }}" @endif
				class="__btn text-primary whitespace-nowrap font-medium py-6">
				{{ $item['link']['title'] }}
			</a>
			@endif
			@endforeach
		</nav>
	</div>
</section>
