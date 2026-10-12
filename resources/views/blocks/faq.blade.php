<!--- faq --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-faq relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main grid grid-cols-1 {{ !empty($g_faq['image']) ? 'md:grid-cols-2 gap-0 md:gap-20' : '' }}">

		@if (!empty($g_faq['header']) || !empty($g_faq['image']))
		<div class="__content">
			<h3 data-gsap-element="header" class="text-primary m-header">{{ $g_faq['header'] }}</h3>
			@if (!empty($g_faq['image']))
			<div data-gsap-element="img" class="__img order1 mt-10">
				<img class="__img object-cover" src="{{ $g_faq['image']['url'] }}" alt="{{ $g_faq['image']['alt'] ?? '' }}">
			</div>
			@endif
		</div>
		@endif
		<div data-gsap-element="tabs" class="tabs-wrapper flex flex-col mt-4">
			@foreach ($r_faq as $item)
			<div class="tabs rounded-2xl bg-white b-shadow h-max">
				<input class="tab-check" type="checkbox" name="radio-a" id="check{{ $loop->index }}">
				<label class="tabs-label flex items-center justify-between" for="check{{ $loop->index }}">
					<div class="flex items-center gap-4">
						<p class="!text-lg font-header">{{ $item['title'] }}</p>
					</div>
					<span class="__arrow shrink-0 w-6 h-6 rounded-full bg-secondary-300 flex items-center justify-center">
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="0" y="6" width="16" height="4" rx="2" fill="white" />
							<rect x="6" y="0" width="4" height="16" rx="2" fill="white" />
						</svg>
					</span>
				</label>
				<div class="tabs-content">
					{!! $item['txt'] !!}
				</div>
			</div>
			@endforeach
		</div>

	</div>

</section>