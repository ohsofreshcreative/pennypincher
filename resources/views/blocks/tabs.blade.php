@php
// --- Budowanie klas sekcji ---
$sectionClass = '';
$sectionClass .= $flip ? ' order-flip' : '';
$sectionClass .= $wide ? ' wide' : '';
$sectionClass .= $nomt ? ' !mt-0' : '';
$sectionClass .= $gap ? ' wider-gap' : '';
if (!empty($background) && $background !== 'none') {
$sectionClass .= ' ' . $background;
}

$grouped_tabs = [];
if (!empty($r_tabs)) {
foreach ($r_tabs as $item) {
$tabName = $item['tab'] ?: 'Inne';
if (!isset($grouped_tabs[$tabName])) {
$grouped_tabs[$tabName] = [];
}
$grouped_tabs[$tabName][] = $item;
}
}
@endphp

<!--- tabs --->

<section data-gsap-anim="section" @if(!empty($section_id)) id="{{ $section_id }}" @endif class="b-tabs relative -smt {{ $sectionClass }} {{ $section_class }}">
	<div class="__wrapper c-main relative">
		@if (!empty($g_tabs['header']))
		<div class="mb-10 text-center">
			<h2 data-gsap-element="header">{{ $g_tabs['header'] }}</h2>
			@if(!empty($g_tabs['text']))
			<div class="__txt mt-4 max-w-3xl mx-auto">
				{!! $g_tabs['text'] !!}
			</div>
			@endif
		</div>
		@endif

		@if(!empty($grouped_tabs))
		<div x-data="{ activeTab: 0 }" class="mt-12">
			<div class="flex justify-center flex-wrap gap-4 mb-10">
				@foreach ($grouped_tabs as $name => $items)
				<button
					@click="activeTab = {{ $loop->index }}"
					:class="{ 'bg-primary text-white': activeTab === {{ $loop->index }}, 'bg-gray-200 text-gray-700 hover:bg-gray-300': activeTab !== {{ $loop->index }} }"
					class="text-sm whitespace-nowrap py-3 px-6 rounded-full transition-colors duration-200 focus:outline-none">
					{{ $name }}
				</button>
				@endforeach
			</div>

			<div class="">
				@foreach ($grouped_tabs as $name => $items)
				<div x-show="activeTab === {{ $loop->index }}" x-cloak class="transition-opacity duration-300">
					@foreach ($items as $item)
					<div class="__card bg-white radius grid grid-cols-1 md:grid-cols-2 section-gap items-center p-10">
						@if(!empty($item['image']))
						<div class="relative overflow-hidden radius">
							<img class="w-full img-xl object-cover" src="{{ $item['image']['url'] }}" alt="{{ $item['image']['alt'] ?? '' }}" />
						</div>
						@endif
						<div class="__content relative ">
							@if (!empty($item['title']))
							<h6 class="text-body mb-4">{{ $item['title'] }}</h6>
							@endif
							@if (!empty($item['text']))
							<div class="text-sm">{{ $item['text'] }}</div>
							@endif
							<a href="#" class="main-btn mt-4">
								Dowiedz się więcej
							</a>
						</div>
					</div>
					@endforeach
				</div>
				@endforeach
			</div>
		</div>
		@endif

		@if (!empty($g_tabs['button']))
		<div class="mt-10 text-center">
			<a href="{{ $g_tabs['button']['url'] }}" class="main-btn m-btn" target="{{ $g_tabs['button']['target'] ?? '_self' }}">
				{{ $g_tabs['button']['title'] }}
			</a>
		</div>
		@endif
	</div>
</section>