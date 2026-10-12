@props(['status', 'label' => null])

@php
  $map = [
    'enabled' => ['ok', 'Aktywna'],
    'active' => ['ok', 'Aktywny'],
    'completed' => ['ok', 'Zaimportowany'],
    'paused' => ['warn', 'Wstrzymana'],
    'uploaded' => ['info', 'Czeka na zatwierdzenie'],
    'removed' => ['neutral', 'Usunięta'],
    'ended' => ['neutral', 'Zakończona'],
    'archived' => ['neutral', 'Zarchiwizowany'],
    'cancelled' => ['neutral', 'Anulowany'],
    'failed' => ['danger', 'Błąd'],
    'pending' => ['info', 'Oczekująca'],
    'other' => ['neutral', 'Inny'],
  ];
  [$tone, $default] = $map[$status] ?? ['neutral', 'Brak danych'];
@endphp

<span {{ $attributes->merge(['class' => 'pp-badge pp-badge-' . $tone]) }}>{{ $label ?? $default }}</span>
