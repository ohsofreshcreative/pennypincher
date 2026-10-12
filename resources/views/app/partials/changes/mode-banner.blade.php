{{-- Tryb pracy z Google Ads: serwer + zgoda dla konta. Zmienne: $mode, $serverMode, opcjonalnie $connection. --}}
@php
  $tone = $mode->canWrite() ? 'warning' : 'info';
@endphp
<x-pp.alert :type="$tone" :title="'Tryb Google Ads: ' . $mode->label()">
  <p>{{ $mode->description() }}</p>
  @if (! $mode->canWrite())
    <p class="mt-1 text-[13px] text-ink-soft">
      Serwer: <strong>{{ $serverMode->label() }}</strong>@if (! empty($connection) && $serverMode->canWrite()) · zgoda dla konta {{ $connection['customer_id'] }}: <strong>{{ (int) ($connection['write_enabled'] ?? 0) === 1 ? 'włączona' : 'wyłączona' }}</strong>@endif.
      Status „Zatwierdzony” oznacza akceptację propozycji, nie wdrożenie.
    </p>
  @endif
</x-pp.alert>
