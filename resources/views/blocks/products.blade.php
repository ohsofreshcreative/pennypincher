<!--- products -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-products relative -smt overflow-hidden' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	@if ($bgshape)
	<img class="__bg-shape absolute inset-y-0 right-0 w-auto pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/bg-shape.svg" alt="">
	@endif

	<div class="__wrapper c-main relative">

		@if (!empty($categories) && count($categories) > 1)
		<div class="__filters mb-8">
			
			<select class="js-filter-select sm:hidden w-full border border-primary text-primary rounded px-4 py-2 bg-white">
				<option value="all">Wszystkie</option>
				@foreach ($categories as $slug => $name)
				<option value="{{ $slug }}">{{ $name }}</option>
				@endforeach
			</select>
			<div class="hidden sm:flex flex-wrap gap-3">
				<button class="btn btn-primary js-filter" data-filter="all">Wszystkie</button>
				@foreach ($categories as $slug => $name)
				<button class="btn btn-outline-primary js-filter" data-filter="{{ $slug }}">{{ $name }}</button>
				@endforeach
			</div>
		</div>
		@endif

		@if (!empty($offer_children))
		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
			@foreach ($offer_children as $child)
			<div data-gsap-element="card"
				 data-categories="{{ implode(' ', $child['categories']) }}"
				 class="__offer-card relative flex flex-col overflow-hidden radius bg-white shadow-sm">
				@if (!empty($child['image_url']))
				<figure class="m-0 overflow-hidden aspect-[4/3]">
					<img
						src="{{ $child['image_url'] }}"
						alt="{{ $child['image_alt'] }}"
						class="w-full h-full object-contain transition-transform duration-300 hover:scale-105 p-10">
				</figure>
				@endif
				<div class="flex flex-col flex-1 p-8">
					<h3 class="text-h5 text-primary mb-4 flex-1">
						<a href="{{ $child['url'] }}" class="after:absolute after:inset-0 after:content-['']">{{ $child['title'] }}</a>
					</h3>
					<div class="relative z-10 mt-auto">
						<x-button
							:href="$child['url']"
							variant="primary">
							Zobacz
						</x-button>
					</div>
				</div>
			</div>
			@endforeach
		</div>
		@endif

	</div>

	<script>
	(function() {
		var section = document.currentScript.closest('.b-products');
		var btns = section.querySelectorAll('.js-filter');
		var select = section.querySelector('.js-filter-select');
		var cards = section.querySelectorAll('.__offer-card');

		function applyFilter(filter) {
			btns.forEach(function(b) {
				b.classList.toggle('btn-primary', b.dataset.filter === filter);
				b.classList.toggle('btn-outline-primary', b.dataset.filter !== filter);
			});
			if (select) select.value = filter;
			cards.forEach(function(card) {
				var cats = card.dataset.categories ? card.dataset.categories.split(' ') : [];
				card.style.display = (filter === 'all' || cats.includes(filter)) ? '' : 'none';
			});
		}

		btns.forEach(function(btn) {
			btn.addEventListener('click', function() {
				applyFilter(this.dataset.filter);
			});
		});

		if (select) {
			select.addEventListener('change', function() {
				applyFilter(this.value);
			});
		}
	})();
	</script>

</section>