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
    'queued' => ['info', 'W kolejce'],
    'running' => ['info', 'W toku'],
    'pending_account' => ['info', 'Wybierz konto'],
    'reauth_required' => ['danger', 'Wymaga ponownej autoryzacji'],
    'disconnected' => ['neutral', 'Rozłączono'],
    'error' => ['danger', 'Błąd'],
    'critical' => ['danger', 'Krytyczny'],
    'warning' => ['warn', 'Ostrzeżenie'],
    'info' => ['info', 'Informacja'],
    'new' => ['info', 'Nowy'],
    'reviewed' => ['ok', 'Przejrzany'],
    'dismissed' => ['neutral', 'Pominięty'],
    'partial' => ['warn', 'Częściowy'],
  ];
  [$tone, $default] = $map[$status] ?? ['neutral', 'Brak danych'];
@endphp

<span {{ $attributes->merge(['class' => 'pp-badge pp-badge-' . $tone]) }}>{{ $label ?? $default }}</span>
