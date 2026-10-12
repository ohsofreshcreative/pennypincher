<!--- attractions --->

<section
  data-gsap-anim="section"
  @if(!empty($section_id)) id="{{ $section_id }}" @endif
  @class([ 'b-attractions relative -smt' ,
  $sectionClass => filled($sectionClass),
  $section_class => filled($section_class),
  $background => filled($background) && $background !== 'none',
  ])>

  <div class="__wrapper c-main">
    @if (!empty($g_attractions['header']))
    <h2 data-gsap-element="header" class="text-h2 text-primary m-header">{{ $g_attractions['header'] }}</h2>
    @endif

    @if (!empty($attractions))
    <div data-gsap-element="stagger" class="__cards grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mt-10">
      @foreach ($attractions as $item)
      <div data-gsap-element="card" class="__card relative radius overflow-hidden bg-white flex flex-col focus-within:outline-2 focus-within:-outline-offset-2 focus-within:outline-primary">
        @if (!empty($item['image']))
        <figure class="__img m-0">
          <img class="w-full h-60 object-cover" src="{{ $item['image']['url'] }}" alt="{{ $item['image']['alt'] ?? '' }}">
        </figure>
        @endif
        <div class="__content p-8 flex flex-col flex-1 justify-between gap-8">
          <div class="__txt flex flex-col gap-2">
            @if (!empty($item['title']))
            <p class="text-h7 text-primary">{{ $item['title'] }}</p>
            @endif
            @if (!empty($item['text']))
            <div class="text-primary-900">{!! $item['text'] !!}</div>
            @endif
          </div>
          <x-button
            type="button"
            variant="underline"
            class="__open self-start before:absolute before:inset-0 before:content-['']"
            aria-haspopup="dialog"
            aria-controls="{{ $item['popup_id'] }}"
            aria-label="{{ __('Więcej o atrakcji:', 'sage') }} {{ $item['title'] ?? __('Atrakcja', 'sage') }}">
            {{ __('Więcej', 'sage') }}
          </x-button>
        </div>
      </div>
      @endforeach
    </div>
    @endif
  </div>
</section>

@foreach ($attractions as $item)
<dialog
  id="{{ $item['popup_id'] }}"
  class="attractions-popup c-main !m-auto border-0 bg-transparent py-8 text-primary-900"
  aria-labelledby="{{ $item['popup_id'] }}-title">
  <div class="__panel relative radius bg-white b-shadow">
    <x-button
      type="button"
      variant="secondary"
      class="__close absolute right-4 top-0 -translate-y-1/2 z-10 !inline-flex items-center justify-center !h-12 !w-12 !p-0"
      aria-label="{{ __('Zamknij popup', 'sage') }}"
      autofocus>
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="m6 6 12 12M18 6 6 18" />
      </svg>
    </x-button>

    <div class="__body radius overflow-hidden grid grid-cols-1 lg:grid-cols-9">
      @if (!empty($item['popup_image']))
      <figure class="__img relative m-0 lg:col-span-5">
        <img
          class="w-full h-60 sm:h-80 lg:absolute lg:inset-0 lg:h-full object-cover"
          src="{{ $item['popup_image']['url'] }}"
          alt="{{ $item['popup_image']['alt'] ?? '' }}"
          loading="lazy">
      </figure>
      @endif

      <div @class([
        '__content p-6 md:p-10 lg:p-12 flex flex-col gap-8',
        'lg:col-span-4' => !empty($item['popup_image']),
        'lg:col-span-9' => empty($item['popup_image']),
      ])>
        <div class="__txt flex flex-col gap-2">
          <h2 id="{{ $item['popup_id'] }}-title" class="text-h7 text-primary">
            {{ ($item['title'] ?? '') ?: __('Atrakcja', 'sage') }}
          </h2>
          @if (!empty($item['popup_text']))
          <div>{!! $item['popup_text'] !!}</div>
          @endif
        </div>

        @if (!empty($item['popup_parameters']))
        <dl class="__parameters grid grid-cols-1 sm:grid-cols-2 gap-6">
          @foreach ($item['popup_parameters'] as $parameter)
          <div @class([
            '__parameter relative flex flex-col gap-2',
            'pl-11' => !empty($parameter['image']['url']),
          ])>
            <dt class="text-secondary-accent">
              @if (!empty($parameter['image']['url']))
              <img class="absolute left-0 top-0 w-8 h-8 object-contain" src="{{ $parameter['image']['url'] }}" alt="" loading="lazy">
              @endif
              {{ $parameter['header'] ?? '' }}
            </dt>
            <dd class="text-primary">{{ $parameter['value'] ?? '' }}</dd>
          </div>
          @endforeach
        </dl>
        @endif

        @if (!empty($item['link']['url']))
        <x-button
          :href="$item['link']['url']"
          :target="$item['link']['target'] ?? '_self'"
          :rel="($item['link']['target'] ?? '') === '_blank' ? 'noopener noreferrer' : null"
          variant="underline"
          class="self-start !whitespace-normal hidden!">
          {{ ($item['link']['title'] ?? '') ?: __('Więcej informacji', 'sage') }}
        </x-button>
        @endif
      </div>
    </div>
  </div>
</dialog>
@endforeach
