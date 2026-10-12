<!--- everyone --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-everyone relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main flex flex-col gap-12">
		@if(!empty($g_everyone['header']) || !empty($g_everyone['text']))
		<div class="__txt w-full md:w-2/3 flex flex-col gap-6">
			@if(!empty($g_everyone['header']))
			<h3 data-gsap-element="header" class="">{{ $g_everyone['header'] }}</h3>
			@endif

			@if(!empty($g_everyone['text']))
			<div data-gsap-element="txt" class="text-primary-900">
				{!! $g_everyone['text'] !!}
			</div>
			@endif
		</div>
		@endif

		@if(!empty($r_everyone))
		<div class="__tabs flex flex-col gap-6" x-data="{ active: 0 }">
			<div class="__nav flex flex-wrap gap-6" role="tablist">
				@foreach($r_everyone as $item)
				<button
					type="button"
					role="tab"
					@click="active = {{ $loop->index }}"
					:aria-selected="active === {{ $loop->index }}"
					:class="active === {{ $loop->index }} ? 'bg-white border-white !text-main' : 'border-primary-200'"
					class="__tab radius border font-semibold px-6 py-4 cursor-pointer">
					{{ $item['tab'] }}
				</button>
				@endforeach
			</div>

			@foreach($r_everyone as $item)
			<div
				x-show="active === {{ $loop->index }}"
				x-cloak
				role="tabpanel"
				class="__content bg-white radius grid grid-cols-1 lg:grid-cols-2 gap-8 p-8">

				<div class="__col flex flex-col gap-11">
					<div class="__txt flex flex-col gap-6">
						@if(!empty($item['title']))
						<p class="text-h5">{{ $item['title'] }}</p>
						@endif

						@if(!empty($item['text']))
						<div class="text-primary-900">{!! $item['text'] !!}</div>
						@endif

						@if(!empty($item['button']))
						<div class="inline-buttons">
							<x-button :href="$item['button']['url']" variant="primary-small">
								{{ $item['button']['title'] }}
							</x-button>
						</div>
						@endif
					</div>

					@if(!empty($item['r_tags']))
					<div class="__btns flex flex-wrap gap-2">
						@foreach($item['r_tags'] as $tag)
						<span class="__btn radius border border-primary-200 px-4 py-1">{{ $tag['label'] }}</span>
						@endforeach
					</div>
					@endif
				</div>

				@if(!empty($item['image']))
				<div class="__img">
					<figure class="m-0 h-full">
						<picture class="h-full">
							<img
								src="{{ $item['image']['url'] }}"
								alt="{{ $item['image']['alt'] ?? '' }}"
								class="w-full h-full object-cover radius" />
						</picture>
					</figure>
				</div>
				@endif
			</div>
			@endforeach
		</div>
		@endif
	</div>
</section>