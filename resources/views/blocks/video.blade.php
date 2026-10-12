<!--- video -->

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-video relative -smt' ,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main">
		<h2 data-gsap-element="header" class="mb-10 text-center">{{ $g_video['header'] }}</h2>

		@if (!empty($g_video['video']))
		<div class="video-wrapper relative">
			<video
				id="customVideo"
				class="w-full">
				<source src="{{ $g_video['video'] }}" type="video/mp4">
				Twoja przeglądarka nie obsługuje odtwarzania wideo.
			</video>

			<button
				id="customPlayBtn"
				class="absolute inset-0 flex items-center justify-center bg-black/40 hover:bg-black/60 transition"
				aria-label="Odtwórz wideo">
				<img src="http://windes.local/wp-content/uploads/2025/06/play.svg" alt="Play" class="w-20 h-20">
			</button>
		</div>
		@endif


	</div>

</section>

<script>
	document.addEventListener('DOMContentLoaded', function () {
  const video = document.getElementById('customVideo');
  const playBtn = document.getElementById('customPlayBtn');

  if (video && playBtn) {
    playBtn.addEventListener('click', () => {
      playBtn.style.display = 'none';

      // Dodaj controls i odtwórz
      video.setAttribute('controls', 'controls');
      video.play();
    });
  }
});

</script>