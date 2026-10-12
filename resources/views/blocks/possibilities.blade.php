<!--- possibilities --->

<section
  data-gsap-anim="section"
  @if(!empty($section_id)) id="{{ $section_id }}" @endif
  @class([ 'b-possibilities relative -smt lg:flex lg:flex-col lg:justify-center',
    $sectionClass => filled($sectionClass),
    $section_class => filled($section_class),
    $background => filled($background) && $background !== 'none',
  ])>

  <div class="absolute inset-0 overflow-hidden pointer-events-none" aria-hidden="true">
    <img class="absolute right-0 top-1/2 -translate-y-1/2 w-1/3 opacity-20" src="{{ get_template_directory_uri() }}/resources/images/signet.svg" alt="">
  </div>

  <div class="__wrapper c-main relative z-10">
    <div class="__scene flex flex-col lg:grid lg:grid-cols-2 gap-10 lg:gap-12 items-stretch">
      @if (!empty($default_image['url']))
      <figure class="__media order1 relative m-0 h-48 sm:h-72 lg:h-auto lg:min-h-64 radius-img overflow-hidden">
        @if (!empty($points))
          @foreach ($points as $point)
          <img
            id="{{ $instance_id }}-image-{{ $loop->index }}"
            data-possibilities-image
            @class(['absolute inset-0 w-full h-full object-cover', 'opacity-100' => $loop->first, 'opacity-0' => !$loop->first])
            src="{{ $point['image']['url'] }}"
            alt="{{ $point['image']['alt'] ?? '' }}"
            aria-hidden="{{ $loop->first ? 'false' : 'true' }}"
            decoding="async">
          @endforeach
        @else
        <img class="absolute inset-0 w-full h-full object-cover" src="{{ $default_image['url'] }}" alt="{{ $default_image['alt'] ?? '' }}">
        @endif
      </figure>
      @endif

      <div @class(['__content order2 flex flex-col py-0 sm:py-10', 'lg:col-span-2' => empty($default_image['url'])])>
        @if (!empty($g_possibilities['header']))
        <h2 class="text-h4 text-main">{{ $g_possibilities['header'] }}</h2>
        @endif

        @if (!empty($g_possibilities['text']))
        <div class="__txt mt-2">{!! $g_possibilities['text'] !!}</div>
        @endif

        @if (!empty($points))
        <div class="__list flex flex-col gap-4 mt-4">
          @foreach ($points as $point)
          <div
            class="__point group relative radius p-4 md:px-10 bg-third-100 text-main data-[active=true]:bg-main-light data-[active=true]:text-white motion-safe:transition-colors focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-main"
            data-active="{{ $loop->first ? 'true' : 'false' }}">
            @if (!empty($point['header']))
            <p class="text-xl font-header">{{ $point['header'] }}</p>
            @endif
            @if (!empty($point['text']))
            <div class="text-sm">{!! $point['text'] !!}</div>
            @endif

            <span @class([
              '__arrow pointer-events-none absolute top-1/2 -translate-y-1/2 hidden lg:flex items-center justify-center w-12 h-12 radius bg-white text-main opacity-0 group-data-[active=true]:opacity-100',
              'left-0 -translate-x-1/2' => !$flip,
              'right-0 translate-x-1/2 rotate-180' => $flip,
            ]) aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m14 6-6 6 6 6" />
              </svg>
            </span>

            <x-button
              type="button"
              variant="underline"
              class="__trigger absolute inset-0 w-full! h-full! p-0! after:hidden"
              aria-pressed="{{ $loop->first ? 'true' : 'false' }}"
              :aria-controls="!empty($point['image']['url']) ? $instance_id . '-image-' . $loop->index : null">
              <span class="sr-only">{{ __('Wybierz:', 'sage') }} {{ ($point['header'] ?? '') ?: sprintf(__('Możliwość %d', 'sage'), $loop->iteration) }}</span>
            </x-button>
          </div>
          @endforeach
        </div>
        @endif
      </div>
    </div>
  </div>
</section>
