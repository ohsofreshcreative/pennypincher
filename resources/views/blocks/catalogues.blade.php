@php

$groups = [];
if (!empty($r_catalogues)) {
foreach ($r_catalogues as $i => $group) {
$slug = sanitize_title($group['group_title'] ?? 'group') . '-' . $i;
$items = [];
if (!empty($group['group_items'])) {
foreach ($group['group_items'] as $item) {
$thumbnail_url = !empty($item['file']['ID']) ? \App\get_pdf_thumbnail_url($item['file']['ID']) : '';
$items[] = [
'title' => $item['title'] ?? '',
'file_url' => $item['file']['url'] ?? '',
'thumbnail' => $thumbnail_url,
];
}
}
$groups[] = [
'title' => $group['group_title'] ?? '',
'slug' => $slug,
'items' => $items,
];
}
}
@endphp

<!--- CATALOGUES --->

<section
    data-gsap-anim="section"
    @if(!empty($section_id)) id="{{ $section_id }}" @endif
    @class([ 'b-catalogues relative -smt' ,
    $sectionClass=> filled($sectionClass),
    $section_class => filled($section_class),
    $background => filled($background) && $background !== 'none',
    ])>
	<div class="__wrapper c-main relative">

		<!-- <div class="relative grid grid-cols-1 lg:grid-cols-[1fr_2fr] items-center pt-30">
			<div class="__content">
				<h2 data-gsap-element="header" class="m-header">{{ strip_tags($g_catalogues['header'] ?? '') }}</h2>
				<p data-gsap-element="txt">{!! $g_catalogues['text'] ?? '' !!}</p>
			</div>
		</div> -->

		@if (!empty($groups))

		<div class="__group-nav flex flex-wrap gap-4 pb-4">
			@foreach ($groups as $group)
			<x-button
				href="#{{ $group['slug'] }}"
				variant="secondary"
				class=""
				data-gsap-element="btn">
				{{ $group['title'] }}
			</x-button>
			@endforeach
		</div>

		@foreach ($groups as $group)
		<div class="__group pt-10" id="{{ $group['slug'] }}">
			<h3 class="mb-10">{{ $group['title'] }}</h3>

			@if (!empty($group['items']))
			<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
				@foreach ($group['items'] as $item)
				<a href="{{ $item['file_url'] }}" target="_blank" class="catalogue-item">
					<div class="__card relative bg-white p-10 h-full flex flex-col">
						@if ($item['thumbnail'])
						<img class="__thumb object-cover border border-primary/10 h-80 w-auto m-auto mb-4" src="{{ $item['thumbnail'] }}" alt="{{ $item['title'] }}" loading="lazy">
						@endif
						<p class="text-xl text-center">{{ $item['title'] }}</p>
						<img class="__btn m-auto mt-4" src="{{ get_template_directory_uri() }}/resources/images/download.svg" alt="Download" />
					</div>
				</a>
				@endforeach
			</div>
			@endif
		</div>
		@endforeach

		@endif

	</div>
</section>