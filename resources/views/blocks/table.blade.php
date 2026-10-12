<!--- table --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-table relative -smt overflow-hidden' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<img class="absolute max-w-none left-1/2 -translate-x-1/2 lg:w-full lg:max-w-full lg:left-0 lg:translate-x-0" src="{{ get_template_directory_uri() }}/resources/images/line.svg" alt="" />

	<div class="__wrapper c-main !mt-40">
		@if(!empty($g_table['header']) || !empty($g_table['text']))
		<div class="__top mb-8">
			@if(!empty($g_table['header']))
			<h4 data-gsap-element="header" class="m-header text-primary">{{ $g_table['header'] }}</h4>
			@endif
			@if(!empty($g_table['text']))
			<p data-gsap-element="text">{{ $g_table['text'] }}</p>
			@endif
		</div>
		@endif

		@if (!empty($r_table))
		@php
		$cols = [
		$g_table_cols['col1'] ?? 'Model',
		$g_table_cols['col2'] ?? 'Wydajność',
		$g_table_cols['col3'] ?? 'Napęd / silnik',
		$g_table_cols['col4'] ?? 'Grubość wirnika',
		];
		@endphp
		<div data-gsap-element="table" class="overflow-x-auto">
			<table class="w-full text-left border-collapse">
				<thead>
					<tr>
						@foreach($cols as $col)
						<th class="px-4 py-3 font-semibold border-b-2 border-primary bg-primary text-white whitespace-nowrap">{{ $col }}</th>
						@endforeach
					</tr>
				</thead>
				<tbody>
					@foreach($r_table as $i => $row)
					<tr class="{{ $i % 2 === 0 ? 'bg-background' : 'bg-secondary-lighter' }}">
						<td class="px-4 py-3 border-b border-gray-200">{{ $row['col1'] ?? '' }}</td>
						<td class="px-4 py-3 border-b border-gray-200">{{ $row['col2'] ?? '' }}</td>
						<td class="px-4 py-3 border-b border-gray-200">{{ $row['col3'] ?? '' }}</td>
						<td class="px-4 py-3 border-b border-gray-200">{{ $row['col4'] ?? '' }}</td>
					</tr>
					@endforeach
				</tbody>
			</table>
		</div>
		@endif
	</div>

</section>