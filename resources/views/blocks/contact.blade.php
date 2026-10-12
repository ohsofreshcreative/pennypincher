<!--- contact --->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-contact relative -spt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<img data-gsap-element="signet" class="absolute mix-blend-overlay opacity-20 h-[704px] top-1/2 -translate-y-1/2 right-0 translate-x-1/2 z-2 pointer-events-none" src="{{ get_template_directory_uri() }}/resources/images/signet.svg" alt="" />

	<div class="__wrapper c-main relative -spt">
		<div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-start">

			<div class="__content flex flex-col gap-6 w-full md:w-9/12">
				@if (!empty($g_contact['header']))
				<h2 data-gsap-element="header" class="">{{ $g_contact['header'] }}</h2>
				@endif

				@if (!empty($g_contact['subheader']))
				<p data-gsap-element="txt" class="text-h6">{{ $g_contact['subheader'] }}</p>
				@endif

				<div class="__links flex flex-col gap-4 mt-6">
					@if (!empty($g_contact['address']))
					<div data-gsap-element="txt" class="__link flex items-center gap-4 border-b border-dashed border-third/30 pb-4">
						<span class="__icon shrink-0">
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none">
								<path d="M23.3553 10.4388C23.3548 10.4383 23.3542 10.4377 23.3537 10.4372L13.5626 0.647461C13.1452 0.229981 12.5904 0 12.0002 0C11.4099 0 10.8551 0.229797 10.4376 0.647278L0.651581 10.4321C0.648284 10.4354 0.644988 10.4388 0.641692 10.4421C-0.215329 11.304 -0.213864 12.7024 0.645904 13.5621C1.0387 13.955 1.5575 14.1826 2.11218 14.2064C2.1347 14.2086 2.15741 14.2097 2.1803 14.2097H2.57054V21.4144C2.57054 22.84 3.73063 24 5.1568 24H8.98739C9.37562 24 9.69059 23.6852 9.69059 23.2969V17.6484C9.69059 16.9979 10.2198 16.4687 10.8705 16.4687H13.1298C13.7805 16.4687 14.3097 16.9979 14.3097 17.6484V23.2969C14.3097 23.6852 14.6245 24 15.0129 24H18.8435C20.2697 24 21.4298 22.84 21.4298 21.4144V14.2097H21.7916C22.3816 14.2097 22.9365 13.9799 23.3542 13.5624C24.2149 12.7013 24.2153 11.3005 23.3553 10.4388Z" fill="#8FB7E2" />
							</svg>
						</span>
						<p>{!! $g_contact['address'] !!}</p>
					</div>
					@endif

					<div class="flex flex-wrap justify-between gap-x-8 gap-y-4">
						<div data-gsap-element="txt" class="grid gap-4 border-b border-dashed border-third/30 pb-4">
							<!-- @if (!empty($g_contact['phone']))
							<a data-gsap-element="txt" class="__link flex items-center gap-4" href="tel:{{ str_replace(' ', '', $g_contact['phone']) }}">
								<span class="__icon shrink-0">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none">
										<path d="M0.658615 10.145C0.235477 9.00006 -0.0632085 7.8302 0.011463 6.58568C0.0612439 5.81407 0.35993 5.16692 0.932411 4.61933C1.52978 4.04685 2.12715 3.42459 2.72453 2.85211C3.49613 2.0805 4.49175 2.0805 5.26335 2.85211C5.73627 3.32503 6.23408 3.82284 6.707 4.29575C7.17992 4.76867 7.65284 5.2167 8.10087 5.68962C8.92225 6.51101 8.92225 7.48174 8.10087 8.30312C7.52839 8.90049 6.93101 9.47298 6.33364 10.0455C6.1843 10.1948 6.15941 10.3193 6.23408 10.5184C6.63233 11.4642 7.17992 12.2856 7.82707 13.0821C9.09649 14.6502 10.565 16.0441 12.2825 17.1392C12.6558 17.3633 13.0541 17.5375 13.4523 17.7366C13.6514 17.8362 13.7759 17.8113 13.9501 17.6371C14.5226 17.0397 15.12 16.4423 15.7174 15.8449C16.489 15.0733 17.4846 15.0733 18.2562 15.8449C19.202 16.7908 20.1727 17.7366 21.1186 18.7073C21.9151 19.5038 21.9151 20.4995 21.1186 21.296C20.571 21.8435 19.9985 22.3662 19.5007 22.9387C18.754 23.7601 17.833 24.0339 16.7628 23.9841C15.2195 23.9095 13.7759 23.3868 12.4069 22.7147C9.34539 21.2213 6.707 19.1554 4.51664 16.5419C2.87387 14.6253 1.52978 12.5096 0.658615 10.145ZM23.9943 10.6697C23.4262 5.06266 18.9772 0.598371 13.3771 0.00657793C12.7011 -0.0648657 12.1082 0.454497 12.1082 1.13432V1.15606C12.1082 1.7384 12.5497 2.2223 13.1288 2.28332C17.6421 2.75883 21.2546 6.35138 21.7361 10.88C21.7981 11.4637 22.2888 11.9068 22.8758 11.9003C23.5354 11.8931 24.0608 11.326 23.9943 10.6697ZM12.1082 5.43118V5.44707C12.1082 5.98978 12.4884 6.47213 13.0233 6.56376C14.1226 6.75204 15.1395 7.27728 15.9414 8.07911C16.7432 8.88094 17.2684 9.89789 17.4567 10.9972C17.5483 11.5321 18.0307 11.9122 18.5734 11.9122H18.5834C19.2834 11.9122 19.827 11.2827 19.7046 10.5935C19.1363 7.39322 16.6069 4.86316 13.4225 4.30926C12.7346 4.18962 12.1082 4.733 12.1082 5.43118Z" fill="#8FB7E2" />
									</svg>
								</span>
								{{ $g_contact['phone'] }}
							</a>
							@endif -->
							@if (!empty($g_contact['phone2']))
							<a class="__link flex items-center gap-4" href="tel:{{ str_replace(' ', '', $g_contact['phone2']) }}">
								<span class="__icon shrink-0">
									<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none">
										<path d="M0.658615 10.1501C0.235477 9.00518 -0.0632085 7.83533 0.011463 6.59081C0.0612439 5.8192 0.35993 5.17205 0.932411 4.62446C1.52978 4.05198 2.12715 3.42972 2.72452 2.85723C3.49613 2.08563 4.49175 2.08563 5.26335 2.85723C5.73627 3.33015 6.23408 3.82796 6.707 4.30088C7.17992 4.7738 7.65284 5.22183 8.10087 5.69475C8.92225 6.51614 8.92225 7.48686 8.10087 8.30825C7.52839 8.90562 6.93101 9.4781 6.33364 10.0506C6.1843 10.1999 6.15941 10.3244 6.23408 10.5235C6.63233 11.4693 7.17992 12.2907 7.82707 13.0872C9.09649 14.6553 10.565 16.0492 12.2825 17.1444C12.6558 17.3684 13.0541 17.5426 13.4523 17.7417C13.6514 17.8413 13.7759 17.8164 13.9501 17.6422C14.5226 17.0448 15.12 16.4474 15.7174 15.8501C16.489 15.0785 17.4846 15.0785 18.2562 15.8501C19.202 16.7959 20.1727 17.7417 21.1186 18.7125C21.9151 19.509 21.9151 20.5046 21.1186 21.3011C20.571 21.8487 19.9985 22.3714 19.5007 22.9439C18.754 23.7652 17.833 24.039 16.7628 23.9893C15.2195 23.9146 13.7759 23.3919 12.4069 22.7198C9.34539 21.2264 6.707 19.1605 4.51664 16.547C2.87387 14.6304 1.52978 12.5147 0.658615 10.1501ZM23.9943 10.6749C23.4262 5.06779 18.9772 0.603498 13.3771 0.0117049C12.7011 -0.0597387 12.1082 0.459624 12.1082 1.13945V1.16119C12.1082 1.74352 12.5497 2.22743 13.1288 2.28845C17.6421 2.76396 21.2546 6.35651 21.7361 10.8851C21.7981 11.4688 22.2888 11.9119 22.8758 11.9054C23.5354 11.8982 24.0608 11.3311 23.9943 10.6749ZM12.1082 5.4363V5.4522C12.1082 5.99491 12.4884 6.47726 13.0233 6.56888C14.1226 6.75717 15.1395 7.28241 15.9414 8.08424C16.7432 8.88606 17.2684 9.90302 17.4567 11.0023C17.5483 11.5372 18.0307 11.9174 18.5734 11.9174H18.5834C19.2834 11.9174 19.827 11.2879 19.7046 10.5986C19.1363 7.39835 16.6069 4.86829 13.4225 4.31439C12.7346 4.19475 12.1082 4.73812 12.1082 5.4363Z" fill="#8FB7E2" />
									</svg>
								</span>
								{{ $g_contact['phone2'] }}
							</a>
							@endif
						</div>

						@if (!empty($g_contact['mail']))
						<a data-gsap-element="txt" class="__link flex items-center gap-4 border-b border-dashed border-third/30 pb-4" href="mailto:{{ $g_contact['mail'] }}">
							<span class="__icon shrink-0">
								<svg xmlns="http://www.w3.org/2000/svg" width="20" height="17" viewBox="0 0 24 24" fill="none">
									<path d="M10.6151 12.1779C11.0057 12.4978 11.4951 12.6725 12 12.6725C12.5049 12.6725 12.9943 12.4978 13.3849 12.1779L22.9677 4.3062C22.5625 3.81445 22.0539 3.41804 21.4781 3.14514C20.9024 2.87225 20.2735 2.72958 19.6364 2.72729H4.36364C3.72647 2.72958 3.09763 2.87225 2.52185 3.14514C1.94607 3.41804 1.4375 3.81445 1.03232 4.3062L10.6151 12.1779Z" fill="#8FB7E2" />
									<path d="M14.7699 13.8644C13.9882 14.5034 13.0096 14.8525 12 14.8525C10.9904 14.8525 10.0118 14.5034 9.23012 13.8644L0.0756436 6.34432C0.0287807 6.59052 0.00347036 6.84034 0 7.09093V16.9091C0.00123305 18.066 0.461368 19.1752 1.27944 19.9933C2.09751 20.8114 3.20671 21.2715 4.36364 21.2727H19.6364C20.7933 21.2715 21.9025 20.8114 22.7206 19.9933C23.5386 19.1752 23.9988 18.066 24 16.9091V7.09093C23.9965 6.84034 23.9712 6.59052 23.9244 6.34432L14.7699 13.8644Z" fill="#8FB7E2" />
								</svg>
							</span>
							{{ $g_contact['mail'] }}
						</a>
						@endif
					</div>

					@if (!empty($g_contact['hours']))
					<div data-gsap-element="txt" class="__hours flex flex-col">
						<p>Godziny otwarcia</p>
						<p>{{ $g_contact['hours'] }}</p>
					</div>
					@endif

					@if (!empty($g_contact['button']))
					<x-button
						:href="$g_contact['button']['url']"
						variant="primary"
						class="w-max flex! inline-flex items-center gap-4 mt-6"
						data-gsap-element="btn">
						<svg class="shrink-0" width="20" height="24" viewBox="0 0 20 24" fill="none" aria-hidden="true">
							<path d="M10 0C4.477 0 0 4.477 0 10c0 7.5 10 14 10 14s10-6.5 10-14C20 4.477 15.523 0 10 0Zm0 14a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z" fill="currentColor" />
						</svg>
						<span>{{ $g_contact['button']['title'] }}</span>
					</x-button>
					@endif
				</div>
			</div>

			<div class="__col flex flex-col gap-10">
				@if (!empty($g_contact['image']))
				<div class="__img radius-img overflow-hidden img-s" data-gsap-element="img">
					<img src="{{ $g_contact['image']['url'] }}" alt="{{ $g_contact['image']['alt'] }}" class="w-full h-full object-cover" />
				</div>
				@endif

				@if (!empty($departments))
				<div class="__departments grid grid-cols-1 sm:grid-cols-2 gap-8">
					@foreach ($departments as $department)
					<div data-gsap-element="stagger" class="__department flex flex-col gap-2">
						@if (!empty($department['header']))
						<span class="__badge radius bg-third text-primary inline-flex items-center gap-2 px-4 py-2 w-max">
							<span class="w-3 h-3 rounded-full bg-secondary-300 flex items-center justify-center shrink-0">
								<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 12 12" fill="none">
									<path d="M2 5.99664C2 5.62846 2.29848 5.32998 2.66667 5.32998H9.33333C9.70153 5.32998 10 5.62846 10 5.99664C10 6.36484 9.70153 6.66331 9.33333 6.66331H2.66667C2.29848 6.66331 2 6.36484 2 5.99664Z" fill="white" />
									<path d="M6.33817 3.00141C6.59851 2.74106 7.02063 2.74106 7.28097 3.00141L9.802 5.52242C10.0623 5.78278 10.0623 6.20488 9.802 6.46524C9.54167 6.72558 9.11953 6.72558 8.8592 6.46524L6.33817 3.94422C6.07781 3.68388 6.07781 3.26176 6.33817 3.00141Z" fill="white" />
									<path d="M9.802 5.53478C9.5416 5.27443 9.11953 5.27443 8.85913 5.53478L6.33815 8.05579C6.0778 8.31614 6.0778 8.73825 6.33815 8.9986C6.5985 9.25894 7.02061 9.25894 7.28096 8.9986L9.802 6.47759C10.0623 6.21724 10.0623 5.79513 9.802 5.53478Z" fill="white" />
								</svg>
							</span>
							{{ $department['header'] }}
						</span>
						@endif
						@if (!empty($department['phone']))
						<a href="tel:{{ str_replace(' ', '', $department['phone']) }}" class="block text-lg">{{ $department['phone'] }}</a>
						@endif
						@if (!empty($department['mail']))
						<a href="mailto:{{ $department['mail'] }}" class="block">{{ $department['mail'] }}</a>
						@endif
					</div>
					@endforeach
				</div>
				@endif
			</div>

		</div>
	</div>

</section>