@props(['period'])

@php
  use App\PennyPincher\Domain\Analysis\AnalysisPeriod;

  /** @var AnalysisPeriod $period */
@endphp

<form method="get" class="flex flex-wrap items-center gap-2" x-data="{ preset: @js($period->preset) }" aria-label="Okres analizy">
  @foreach (request()->except(['period', 'from', 'to', 'page']) as $key => $value)
    @if (is_scalar($value))
      <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endif
  @endforeach

  <label class="sr-only" for="pp-period">Okres analizy</label>
  <div class="relative">
    <x-pp.icon name="calendar" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-mute" />
    <select id="pp-period" name="period" class="pp-input pp-input-sm w-auto pl-9" x-model="preset" x-on:change="if (preset !== 'custom') $el.form.requestSubmit()">
      @foreach (AnalysisPeriod::PRESETS as $value => $label)
        <option value="{{ $value }}" @selected($period->preset === $value)>{{ $label }}</option>
      @endforeach
    </select>
  </div>

  <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto" x-show="preset === 'custom'" @if ($period->preset !== 'custom') x-cloak @endif>
    <label class="sr-only" for="pp-period-from">Od</label>
    <input id="pp-period-from" type="date" name="from" value="{{ $period->range->fromString() }}" class="pp-input pp-input-sm min-w-0 flex-1 sm:w-auto sm:flex-none">
    <span class="text-ink-mute" aria-hidden="true">–</span>
    <label class="sr-only" for="pp-period-to">Do</label>
    <input id="pp-period-to" type="date" name="to" value="{{ $period->range->toString() }}" class="pp-input pp-input-sm min-w-0 flex-1 sm:w-auto sm:flex-none">
    <x-pp.button variant="secondary" size="sm">Pokaż</x-pp.button>
  </div>

  <noscript><x-pp.button variant="secondary" size="sm">Pokaż</x-pp.button></noscript>
  <span class="pp-num hidden text-[13px] text-ink-mute lg:inline" x-show="preset !== 'custom'">{{ $period->range->label() }}</span>
</form>
