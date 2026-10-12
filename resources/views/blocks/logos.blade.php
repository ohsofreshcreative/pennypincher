<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-logos relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper flex flex-col gap-8">
		@if(!empty($g_logos['header']))
		<div class="c-main">
			<h2 data-gsap-element="header" class="__header text-h2">{{ $g_logos['header'] }}</h2>
		</div>
		@endif

		@if(!empty($g_logos['gallery']))
		<div class="__logos relative w-full overflow-hidden">
			<div class="__track flex w-max items-center gap-6 animate-infinite-scroll">
				@for ($copy = 0; $copy < 4; $copy++)
					@foreach($g_logos['gallery'] as $image)
					<div class="__logo radius bg-white flex items-center justify-center px-12 py-6 shrink-0" @if($copy > 0) aria-hidden="true" @endif>
						<img
							src="{{ $image['url'] }}"
							alt="{{ $image['alt'] ?? '' }}"
							class="w-auto h-10 object-contain" />
					</div>
					@endforeach
				@endfor
			</div>
		</div>
		@endif
	</div>
</section>