<!--- groups --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-groups relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		@if (!empty($g_groups['header']) || !empty($g_groups['text']))
		<div class="__top max-w-2xl mx-auto text-center">
			@if (!empty($g_groups['header']))
			<h2 data-gsap-element="header" class="text-h2 text-primary m-header">{{ $g_groups['header'] }}</h2>
			@endif
			@if (!empty($g_groups['text']))
			<div data-gsap-element="txt">{!! $g_groups['text'] !!}</div>
			@endif
		</div>
		@endif

		@if (!empty($r_tabs))
		<div class="__tabs flex flex-wrap justify-center gap-3 m-btn">
			@foreach ($r_tabs as $tab)
			<button
				type="button"
				class="js-groups-filter btn border-main! text-main! hover:bg-main! hover:text-white! aria-pressed:bg-main! aria-pressed:text-white!"
				aria-pressed="{{ $loop->first ? 'true' : 'false' }}"
				data-filter="{{ sanitize_title($tab['label']) }}">
				{{ $tab['label'] }}
			</button>
			@endforeach
		</div>
		@endif

		@if (!empty($r_cards))
		<div data-gsap-element="stagger" class="__cards grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mt-10">
			@foreach ($r_cards as $card)
			<div
				class="__card bg-white radius p-8"
				data-groups-tab="{{ sanitize_title($card['tab']) }}"
				@if (isset($r_tabs[0]) && sanitize_title($card['tab']) !== sanitize_title($r_tabs[0]['label'])) style="display:none;" @endif>
				@if (!empty($card['badge']))
				<span class="__badge inline-block bg-secondary-300 text-white radius px-4 py-1">{{ $card['badge'] }}</span>
				@endif
				@if (!empty($card['title']))
				<p class="__title text-h5 text-primary-400 mt-4">{{ $card['title'] }}</p>
				@endif
				@if (!empty($card['text']))
				<p class="mt-2">{{ $card['text'] }}</p>
				@endif

				@if (!empty($card['meta_level']) || !empty($card['meta_age']) || !empty($card['meta_duration']))
				<div class="__meta mt-4 pt-4 border-t border-third">
					@if (!empty($card['meta_level']))
					<p><strong>Poziom:</strong> {{ $card['meta_level'] }}</p>
					@endif
					@if (!empty($card['meta_age']))
					<p><strong>Wiek:</strong> {{ $card['meta_age'] }}</p>
					@endif
					@if (!empty($card['meta_duration']))
					<p><strong>Czas zajęć:</strong> {{ $card['meta_duration'] }}</p>
					@endif
				</div>
				@endif

				@if (!empty($card['link']))
				<x-button
					:href="$card['link']['url']"
					variant="underline"
					class="mt-6">
					{{ $card['link']['title'] }}
				</x-button>
				@endif
			</div>
			@endforeach
		</div>
		@endif
	</div>

	<script>
	(function() {
		var section = document.currentScript.closest('.b-groups');
		var btns = section.querySelectorAll('.js-groups-filter');
		var cards = section.querySelectorAll('.__card');

		function applyFilter(filter) {
			btns.forEach(function(b) {
				b.setAttribute('aria-pressed', b.dataset.filter === filter ? 'true' : 'false');
			});
			cards.forEach(function(card) {
				card.style.display = (card.dataset.groupsTab === filter) ? '' : 'none';
			});
		}

		btns.forEach(function(btn) {
			btn.addEventListener('click', function() {
				applyFilter(this.dataset.filter);
			});
		});
	})();
	</script>
</section>
