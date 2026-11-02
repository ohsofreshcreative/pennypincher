{{-- Źródło liczb na ekranie: Google Ads API, import albo dane demonstracyjne. Bez źródła (brak danych) – nic. --}}
@props(['source' => null, 'demo' => false])

@php
  $label = match (true) {
    $demo => ['demo', 'Dane demonstracyjne'],
    $source === 'api' => ['info', 'Google Ads API'],
    $source === 'api_mixed' => ['info', 'Google Ads API + import'],
    in_array($source, ['day', 'period', 'mixed'], true) => ['neutral', 'Import'],
    default => null,
  };
@endphp

@if ($label)
  <span {{ $attributes->merge(['class' => 'pp-badge pp-badge-' . $label[0]]) }} title="Źródło danych">{{ $label[1] }}</span>
@endif
