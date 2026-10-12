<!--- prices --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-prices relative -smt',
	$sectionClass => filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main flex flex-col gap-12">
		@if (!empty($r_price_tabs))
		<div class="__table flex flex-col gap-6" x-data="{ active: 0 }">
			<div class="__tabs flex flex-wrap gap-3" role="tablist">
				@foreach ($r_price_tabs as $tab)
				@if (!empty($tab['tab']))
				<button
					type="button"
					role="tab"
					@click="active = {{ $loop->index }}"
					:aria-selected="active === {{ $loop->index }}"
					:class="active === {{ $loop->index }} ? 'bg-primary text-white' : 'bg-white text-primary'"
					class="__tab rounded-full px-6 py-4 cursor-pointer transition-colors">
					{{ $tab['tab'] }}
				</button>
				@endif
				@endforeach
			</div>

			<div class="__content bg-white radius p-10">
				@foreach ($r_price_tabs as $tab)
				<div x-show="active === {{ $loop->index }}" x-cloak class="__panel flex flex-col gap-12">
					@if (!empty($tab['r_tables']))
					@foreach ($tab['r_tables'] as $table)
					<div class="__price-table flex flex-col gap-4">
						@if (!empty($table['header']))
						<p class="text-h6 text-secondary border-b border-primary-100 pb-4">{{ $table['header'] }}</p>
						@endif
						@if (!empty($table['r_rows']))
						<div class="divide-y divide-primary-100">
							@foreach ($table['r_rows'] as $row)
							<div class="__row flex items-center justify-between py-3">
								@if (!empty($row['label']))
								<p>{{ $row['label'] }}</p>
								@endif
								@if (!empty($row['price']))
								<p>{{ $row['price'] }}</p>
								@endif
							</div>
							@endforeach
						</div>
						@endif
					</div>
					@endforeach
					@endif
				</div>
				@endforeach
			</div>
		</div>
		@endif

		@if (!empty($r_groups))
		<div class="__groups flex flex-col gap-6">
			@if (!empty($groups_header))
			<p class="text-h5 text-primary">{{ $groups_header }}</p>
			@endif

			<div class="__row grid grid-cols-1 lg:grid-cols-2 gap-6">
				@foreach ($r_groups as $group)
				<div class="__col bg-white radius p-8 flex flex-col gap-4">
					@if (!empty($group['header']))
					<p class="text-h6 text-secondary border-b border-primary-100 pb-4">{{ $group['header'] }}</p>
					@endif
					@if (!empty($group['r_rows']))
					<div class="divide-y divide-primary-100">
						@foreach ($group['r_rows'] as $row)
						<div class="__row flex items-center justify-between py-3">
							@if (!empty($row['label']))
							<p>{{ $row['label'] }}</p>
							@endif
							@if (!empty($row['price']))
							<p>{{ $row['price'] }}</p>
							@endif
						</div>
						@endforeach
					</div>
					@endif
					@if (!empty($group['note']))
					<p class="text-center">{{ $group['note'] }}</p>
					@endif
				</div>
				@endforeach
			</div>
		</div>
		@endif
	</div>
</section>
