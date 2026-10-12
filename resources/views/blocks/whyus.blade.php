<!--- whyus -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-whyus relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main relative">
		<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
			<div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-5 gap-6 items-stretch">

				@if(!empty($tile_1))
				<div class="md:col-span-2 bg-lighter radius p-8 md:p-10 flex flex-col justify-between items-start min-h-[260px]">
					@if(!empty($tile_1['icon']['url']))
					<div class="w-16 h-16 flex items-center justify-start mb-6">
						<img src="{{ $tile_1['icon']['url'] }}" alt="{{ $tile_1['icon']['alt'] ?? '' }}" class="w-12 h-12 object-contain" />
					</div>
					@endif
					<h3 class="text-h5 text-primary font-header font-bold leading-tight select-none">
						{{ $tile_1['title'] ?? '' }}
					</h3>
				</div>
				@endif

				@if(!empty($tile_2))
				<div class="md:col-span-3 relative radius overflow-hidden min-h-[260px] p-8 md:p-10 flex flex-col justify-between">
					@if(!empty($tile_2['image']['url']))
					<img src="{{ $tile_2['image']['url'] }}" alt="{{ $tile_2['image']['alt'] ?? '' }}" class="absolute inset-0 w-full h-full object-cover z-0" />
					<div class="absolute inset-0 bg-black/60 z-[1]"></div>
					@endif
					<div class="relative z-10 flex flex-col justify-end h-full">
						<h3 class="text-h5 text-white font-header font-bold leading-none mb-2">
							{{ $tile_2['header'] ?? '' }}
						</h3>
						<p class="text-white">
							{{ $tile_2['text'] ?? '' }}
						</p>
					</div>
				</div>
				@endif

				@if(!empty($tile_3))
				<div class="md:col-span-3 bg-primary radius p-8 md:p-10 flex flex-col justify-between text-white min-h-[360px]">
					<div class="flex flex-col">
						<span class="text-h3 font-header">
							{{ $tile_3['stat'] ?? '' }}
						</span>
						<span class="text-h6 font-header mt-1">
							{{ $tile_3['label_top'] ?? '' }}
						</span>
					</div>
					<p class="text-h7">
						{{ $tile_3['label_bottom'] ?? '' }}
					</p>
				</div>
				@endif

				@if(!empty($tile_4))
				<div class="md:col-span-2 bg-lighter radius p-8 md:p-10 flex flex-col justify-between min-h-[504px]">
					<div>
						<div class=""><img src="/wp-content/uploads/2026/07/quote.svg" alt="" /></div>
						<p class="text-h7 mt-10">
							{{ $tile_4['quote'] ?? '' }}
						</p>
						<p class="text-h7 mt-10">{{ $tile_4['author_name'] ?? '' }}</p>
						<p class="text-lg">{{ $tile_4['author_role'] ?? '' }}</p>
					</div>
					<div class="mt-6">

						<div class="flex items-center gap-2 mt-4">
							<div class="flex text-yellow-500 text-sm">
								@for ($i = 0; $i < 5; $i++)
									<svg class="w-4 h-4 fill-current" viewBox="0 0 20 20">
									<path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z" /></svg>
									@endfor
							</div>
							<span class="text-xs font-bold text-primary">{{ $tile_4['rating'] ?? '5.0' }}</span>
						</div>
					</div>
				</div>
				@endif

			</div>

			@if(!empty($tile_5))
			<div class="lg:col-span-1 relative radius overflow-hidden min-h-[500px] lg:min-h-full p-6 flex flex-col justify-end">
				@if(!empty($tile_5['image']['url']))
				<img src="{{ $tile_5['image']['url'] }}" alt="{{ $tile_5['image']['alt'] ?? '' }}" class="absolute inset-0 w-full h-full object-cover z-0" />
				@endif

				<div class="relative z-10 bg-primary rounded-[18px] p-6 text-white border-2 border-white/15 backdrop-blur-sm shadow-xl">
					<h3 class="text-h6 text-white font-header leading-tight mb-0">
						{{ $tile_5['card_title'] ?? '' }}
					</h3>
				</div>
			</div>
			@endif

		</div>
	</div>

</section>