<!--- classes --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-classes relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		@if (!empty($r_classes))
		<div data-gsap-element="stagger" class="__cards grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
			@foreach ($r_classes as $item)
			<div class="__card relative min-h-[464px] radius overflow-hidden flex flex-col justify-end px-10 pt-80 pb-14">
				@if (!empty($item['image']))
				<img
					src="{{ $item['image']['url'] }}"
					alt="{{ $item['image']['alt'] ?? '' }}"
					class="absolute inset-0 w-full h-full object-cover" />
				<div class="absolute inset-0 bg-linear-to-t from-main to-transparent"></div>
				@endif

				<div class="__txt relative flex flex-col gap-2 text-white">
					@if (!empty($item['title']))
					<p class="__title text-h5">{{ $item['title'] }}</p>
					@endif
					@if (!empty($item['text']))
					<p>{{ $item['text'] }}</p>
					@endif
				</div>
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>
