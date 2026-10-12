@props(['range'])

@php
  use App\PennyPincher\Domain\Metrics\DateRange;
@endphp

<form method="get" class="flex flex-wrap items-center gap-2" x-data="{ preset: @js($range->preset) }" aria-label="Zakres dat">
  @foreach (request()->except(['range', 'from', 'to', 'page']) as $key => $value)
    @if (is_scalar($value))
      <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endif
  @endforeach

  <label class="sr-only" for="pp-range">Zakres dat</label>
  <div class="relative">
    <x-pp.icon name="calendar" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-mute" />
    <select id="pp-range" name="range" class="pp-input pp-input-sm w-auto pl-9" x-model="preset" x-on:change="if (preset !== 'custom') $el.form.requestSubmit()">
      @foreach (DateRange::PRESETS as $value => $label)
        <option value="{{ $value }}" @selected($range->preset === $value)>{{ $label }}</option>
      @endforeach
    </select>
  </div>

  <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto" x-show="preset === 'custom'" @if ($range->preset !== 'custom') x-cloak @endif>
    <label class="sr-only" for="pp-from">Od</label>
    <input id="pp-from" type="date" name="from" value="{{ $range->fromString() }}" class="pp-input pp-input-sm min-w-0 flex-1 sm:w-auto sm:flex-none">
    <span class="text-ink-mute" aria-hidden="true">–</span>
    <label class="sr-only" for="pp-to">Do</label>
    <input id="pp-to" type="date" name="to" value="{{ $range->toString() }}" class="pp-input pp-input-sm min-w-0 flex-1 sm:w-auto sm:flex-none">
    <x-pp.button variant="secondary" size="sm">Pokaż</x-pp.button>
  </div>

  <noscript><x-pp.button variant="secondary" size="sm">Pokaż</x-pp.button></noscript>
  <span class="pp-num hidden text-[13px] text-ink-mute lg:inline" x-show="preset !== 'custom'">{{ $range->label() }}</span>
</form>
