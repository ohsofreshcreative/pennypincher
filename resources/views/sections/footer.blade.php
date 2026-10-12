<footer @class([
	'footer bg-primary-bg overflow-hidden relative z-10',
	'-smt' => !get_field('no_footer_margin') && !is_singular('post') && !is_category(),
])>

	<img data-gsap-element="signet" class="absolute mix-blend-overlay opacity-40 z-2 pointer-events-none top-1/2 -translate-y-1/2 left-0 -translate-x-1/2 w-[704px]" src="{{ get_template_directory_uri() }}/resources/images/signet.svg" alt="" />

	<div class="c-main __wrapper relative z-10">
		<div class="__widgets grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-1 md:gap-6 footer-py *:border-l *:border-dashed *:border-primary-400 *:pl-6">

			<div class="flex flex-col gap-4 mb-10 md:mb-0">

				@if(!empty($footer_contact['address']))
				@if(!empty($logo_footer))
				<a href="{{ home_url('/') }}" class="block max-w-[180px]">
					<img src="{{ $logo_footer['url'] }}" alt="{{ $logo_footer['alt'] ?? get_bloginfo('name') }}" class="w-full h-auto object-contain" />
				</a>
				@endif
				<div class="__txt mt-2">
					{!! $footer_contact['address'] !!}
				</div>
				@endif
				<div class="flex flex-col gap-2">
					@if(!empty($footer_contact['phone']))
					<a href="tel:{{ str_replace(' ', '', $footer_contact['phone']) }}" class="font-medium inline-flex items-center gap-2 hover:!underline">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
							<path d="M0.658615 10.145C0.235477 9.00006 -0.0632085 7.8302 0.011463 6.58568C0.0612439 5.81407 0.35993 5.16692 0.932411 4.61933C1.52978 4.04685 2.12715 3.42459 2.72453 2.85211C3.49613 2.0805 4.49175 2.0805 5.26335 2.85211C5.73627 3.32503 6.23408 3.82284 6.707 4.29575C7.17992 4.76867 7.65284 5.2167 8.10087 5.68962C8.92225 6.51101 8.92225 7.48174 8.10087 8.30312C7.52839 8.90049 6.93101 9.47298 6.33364 10.0455C6.1843 10.1948 6.15941 10.3193 6.23408 10.5184C6.63233 11.4642 7.17992 12.2856 7.82707 13.0821C9.09649 14.6502 10.565 16.0441 12.2825 17.1392C12.6558 17.3633 13.0541 17.5375 13.4523 17.7366C13.6514 17.8362 13.7759 17.8113 13.9501 17.6371C14.5226 17.0397 15.12 16.4423 15.7174 15.8449C16.489 15.0733 17.4846 15.0733 18.2562 15.8449C19.202 16.7908 20.1727 17.7366 21.1186 18.7073C21.9151 19.5038 21.9151 20.4995 21.1186 21.296C20.571 21.8435 19.9985 22.3662 19.5007 22.9387C18.754 23.7601 17.833 24.0339 16.7628 23.9841C15.2195 23.9095 13.7759 23.3868 12.4069 22.7147C9.34539 21.2213 6.707 19.1554 4.51664 16.5419C2.87387 14.6253 1.52978 12.5096 0.658615 10.145ZM23.9943 10.6697C23.4262 5.06266 18.9772 0.598371 13.3771 0.00657793C12.7011 -0.0648657 12.1082 0.454497 12.1082 1.13432V1.15606C12.1082 1.7384 12.5497 2.2223 13.1288 2.28332C17.6421 2.75883 21.2546 6.35138 21.7361 10.88C21.7981 11.4637 22.2888 11.9068 22.8758 11.9003C23.5354 11.8931 24.0608 11.326 23.9943 10.6697ZM12.1082 5.43118V5.44707C12.1082 5.98978 12.4884 6.47213 13.0233 6.56376C14.1226 6.75204 15.1395 7.27728 15.9414 8.07911C16.7432 8.88094 17.2684 9.89789 17.4567 10.9972C17.5483 11.5321 18.0307 11.9122 18.5734 11.9122H18.5834C19.2834 11.9122 19.827 11.2827 19.7046 10.5935C19.1363 7.39322 16.6069 4.86316 13.4225 4.30926C12.7346 4.18962 12.1082 4.733 12.1082 5.43118Z" fill="#8FB7E2" />
						</svg>
						{{ $footer_contact['phone'] }}
					</a>
					@endif
					@if(!empty($footer_contact['email']))
					<a href="mailto:{{ $footer_contact['email'] }}" class="font-medium inline-flex items-center gap-2 hover:!underline">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="19" viewBox="0 0 24 19" fill="none">
							<path d="M10.6151 9.45064C11.0057 9.77047 11.4951 9.94523 12 9.94523C12.5049 9.94523 12.9943 9.77047 13.3849 9.45064L22.9677 1.57891C22.5625 1.08716 22.0539 0.690742 21.4781 0.417847C20.9024 0.144953 20.2735 0.00228815 19.6364 0H4.36364C3.72647 0.00228815 3.09763 0.144953 2.52185 0.417847C1.94607 0.690742 1.4375 1.08716 1.03232 1.57891L10.6151 9.45064Z" fill="#8FB7E2" />
							<path d="M14.7699 11.1371C13.9882 11.7761 13.0096 12.1252 12 12.1252C10.9904 12.1252 10.0118 11.7761 9.23012 11.1371L0.0756436 3.61703C0.0287807 3.86323 0.00347036 4.11304 0 4.36364V14.1818C0.00123305 15.3387 0.461368 16.4479 1.27944 17.266C2.09751 18.0841 3.20671 18.5442 4.36364 18.5455H19.6364C20.7933 18.5442 21.9025 18.0841 22.7206 17.266C23.5386 16.4479 23.9988 15.3387 24 14.1818V4.36364C23.9965 4.11304 23.9712 3.86323 23.9244 3.61703L14.7699 11.1371Z" fill="#8FB7E2" />
						</svg>
						{{ $footer_contact['email'] }}
					</a>
					@endif
				</div>
			</div>

			@for ($i = 1; $i <= 4; $i++)
				@if (is_active_sidebar('sidebar-footer-' . $i))
				<div>@php(dynamic_sidebar('sidebar-footer-' . $i))
		</div>
		@endif
		@endfor
	</div>

	</div>

	<div class=" py-10 footer-bottom text-white border-t border-primary-light/20">
		<div class="c-main flex flex-col items-center gap-10">
			<div class="flex flex-col md:flex-row justify-between gap-6">
				<p class="">Copyright ©{{ date('Y') }} {{ get_bloginfo('name') }}. All Rights Reserved</p>
				<p class="text-primary-light/20">|</p>
				<div>
					<a href="">Polityka prywatności</a> · <a href="">RODO</a> · <a href="">Deklaracja dostępności</a> · <a href="">BIP</a> · <a href="">Ustawienia cookies</a>
				</div>
			</div>
			<p class="flex gap-2">Designed &amp; Developed by
				<a target="_blank" rel="nofollow" href="https://www.ohsofresh.pl" title="OhSoFresh"><img class="oh" src="{{ get_template_directory_uri() }}/resources/images/ohsofresh.svg" alt="OhSoFresh"></a>
			</p>
		</div>
	</div>

</footer>