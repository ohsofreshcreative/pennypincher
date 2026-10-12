@props(['series', 'metric', 'title', 'total', 'format', 'color' => 'var(--color-ink)'])

@php
  $chart = \App\PennyPincher\Support\LineChart::build($series, $metric, $format);
  $chartId = 'chart-' . $metric . '-' . substr(md5($title), 0, 6);
@endphp

<figure {{ $attributes->merge(['class' => 'pp-card flex min-w-0 flex-col']) }}>
  <figcaption class="flex items-baseline justify-between gap-3 px-5 pt-4">
    <span class="text-sm font-semibold text-ink">{{ $title }}</span>
    <span class="pp-num text-sm font-semibold" style="color: {{ $color }}">{{ $total }}</span>
  </figcaption>

  @if ($chart['empty'])
    <p class="px-5 pt-3 pb-5 text-sm text-ink-mute">Brak danych dziennych w tym zakresie.</p>
  @else
    <svg viewBox="0 0 {{ \App\PennyPincher\Support\LineChart::WIDTH }} {{ \App\PennyPincher\Support\LineChart::HEIGHT }}" class="mt-2 h-auto w-full" role="img" aria-labelledby="{{ $chartId }}-desc">
      <desc id="{{ $chartId }}-desc">{{ $title }} – wykres dzienny. Maksimum: {{ $chart['maxLabel'] }}.</desc>
      <line x1="0" x2="{{ \App\PennyPincher\Support\LineChart::WIDTH }}" y1="{{ $chart['baseline'] }}" y2="{{ $chart['baseline'] }}" stroke="var(--color-line)" />
      <line x1="0" x2="{{ \App\PennyPincher\Support\LineChart::WIDTH }}" y1="{{ $chart['top'] + 0.5 }}" y2="{{ $chart['top'] + 0.5 }}" stroke="var(--color-line)" stroke-dasharray="3 4" />
      @foreach ($chart['area'] as $area)
        <path d="{{ $area }}" fill="{{ $color }}" fill-opacity="0.08" />
      @endforeach
      @foreach ($chart['segments'] as $segment)
        <path d="{{ $segment }}" fill="none" stroke="{{ $color }}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
      @endforeach
      @foreach ($chart['points'] as $point)
        <rect class="pp-chart-hit" x="{{ $point['x'] - $chart['slot'] / 2 }}" y="0" width="{{ $chart['slot'] }}" height="{{ $chart['baseline'] }}" fill="transparent"><title>{{ $point['label'] }}</title></rect>
        @if ($point['y'] !== null)
          <circle class="pp-chart-dot" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.5" fill="{{ $color }}" pointer-events="none" />
        @endif
      @endforeach
    </svg>
    <div class="relative mx-0 h-4 text-[11px] text-ink-mute" aria-hidden="true">
      @foreach ($chart['ticks'] as $tick)
        @php $left = $tick['x'] / \App\PennyPincher\Support\LineChart::WIDTH * 100; @endphp
        <span class="pp-num absolute top-0 -translate-x-1/2 whitespace-nowrap" style="left: {{ max(6, min(94, $left)) }}%">{{ $tick['label'] }}</span>
      @endforeach
    </div>
    <p class="px-5 pt-1 pb-3 text-[12px] text-ink-mute">Najwyżej: <span class="pp-num">{{ $chart['maxLabel'] }}</span></p>
  @endif
</figure>
