@php
$categories = get_the_category();
$category = !empty($categories) ? $categories[0] : null;
@endphp

<article @php(post_class('__card'))>
	<a href="{{ get_permalink() }}" class="group relative radius overflow-hidden flex flex-col justify-end min-h-121 p-10">
		@if (has_post_thumbnail())
		<img
			src="{{ get_the_post_thumbnail_url(null, 'large') }}"
			alt="{{ get_the_title() }}"
			class="absolute inset-0 w-full h-full object-cover" />
		<div class="absolute inset-0 bg-linear-to-t from-main to-transparent"></div>
		@endif

		<div class="__txt relative flex flex-col gap-2 text-white">
			<div class="__info flex items-center gap-2">
				@if ($category)
				<span>{{ $category->name }}</span>
				@endif
				<span>{{ get_the_date() }}</span>
			</div>
			<p class="__title text-h6">{!! get_the_title() !!}</p>
		</div>

		<span class="__arrow absolute top-8 right-8 w-14 h-14 rounded-full bg-white group-hover:bg-third transition-all text-primary flex items-center justify-center -rotate-45 group-hover:rotate-0">
			<svg width="13" height="12" viewBox="0 0 13 12" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M12.7296 5.31498C12.7293 5.31469 12.7291 5.31435 12.7287 5.31406L7.91118 0.281803C7.55027 -0.0951806 6.96652 -0.0937777 6.60727 0.285093C6.24806 0.663916 6.24945 1.27664 6.61036 1.65367L9.84486 5.03226L0.921985 5.03226C0.412773 5.03226 0 5.46552 0 6C0 6.53448 0.412773 6.96774 0.921985 6.96774L9.84482 6.96774L6.6104 10.3463C6.24949 10.7234 6.24811 11.3361 6.60731 11.7149C6.96657 12.0938 7.55037 12.0951 7.91123 11.7182L12.7288 6.68594C12.7291 6.68565 12.7293 6.68531 12.7296 6.68502C13.0907 6.30673 13.0896 5.69202 12.7296 5.31498Z" fill="currentColor" />
			</svg>
		</span>
	</a>
</article>
