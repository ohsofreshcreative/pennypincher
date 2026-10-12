@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $meta = $import['meta'] ?? [];
  $declared = $meta['detected_period'] ?? null;
  $targets = [
    'campaigns' => [AppUrl::project($project->id, 'campaigns'), 'Zobacz kampanie'],
    'search_terms' => [AppUrl::project($project->id, 'search-terms'), 'Zobacz wyszukiwane hasła'],
    'keywords' => [AppUrl::project($project->id, 'campaigns'), 'Zobacz kampanie'],
    'conversion_actions' => [AppUrl::project($project->id, '', ['range' => 'all']), 'Zobacz konwersje na przeglądzie'],
  ];
@endphp

@section('content')
  <a href="{{ AppUrl::project($project->id, 'imports') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-mint hover:underline">
    <x-pp.icon name="chevron-left" :size="16" />Importy
  </a>

  <section class="pp-card" aria-labelledby="pp-summary">
    <div class="pp-card-header">
      <h2 id="pp-summary" class="pp-card-title">{{ $import['original_filename'] }}</h2>
      <x-pp.status-badge :status="$import['status']" />
    </div>

    <dl class="grid gap-x-6 gap-y-4 p-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
      <div><dt class="text-ink-mute">Typ raportu</dt><dd class="font-medium">{{ $definition?->label() ?? 'Nierozpoznany' }}</dd></div>
      <div><dt class="text-ink-mute">Okres danych</dt><dd class="pp-num font-medium">{{ Format::period($import['date_from'], $import['date_to']) }}</dd></div>
      <div><dt class="text-ink-mute">Zakres w nagłówku eksportu</dt><dd class="pp-num font-medium">{{ $declared ? Format::period($declared[0], $declared[1]) : 'Brak w pliku' }}</dd></div>
      <div><dt class="text-ink-mute">Ziarnistość</dt><dd class="font-medium">{{ $import['granularity'] === 'day' ? 'Dane dzienne' : ($import['granularity'] === 'period' ? 'Suma za okres (bez podziału na dni)' : Format::EMPTY) }}</dd></div>
      <div><dt class="text-ink-mute">Waluta</dt><dd class="font-medium">{{ $import['currency'] ?: Format::EMPTY }}</dd></div>
      <div><dt class="text-ink-mute">Wiersze w pliku</dt><dd class="pp-num font-medium">{{ Format::number($import['rows_total']) }}</dd></div>
      <div><dt class="text-ink-mute">Zaimportowane wiersze</dt><dd class="pp-num font-medium">{{ Format::number($import['rows_imported']) }}</dd></div>
      <div><dt class="text-ink-mute">Pominięte (puste, podsumowania)</dt><dd class="pp-num font-medium">{{ Format::number($import['rows_skipped']) }}</dd></div>
      <div><dt class="text-ink-mute">Zapisane rekordy metryk</dt><dd class="pp-num font-medium">{{ Format::number($meta['metric_rows'] ?? null) }}</dd></div>
      <div><dt class="text-ink-mute">Zaimportowano</dt><dd class="pp-num font-medium">{{ Format::datetime($import['completed_at']) }}</dd></div>
    </dl>

    @if ($import['granularity'] === 'day' && $declared && ($declared[0] !== $import['date_from'] || $declared[1] !== $import['date_to']))
      <div class="px-5 pb-5">
        <x-pp.alert type="info">
          Eksport deklaruje zakres {{ Format::period($declared[0], $declared[1]) }}, a wiersze dzienne obejmują {{ Format::period($import['date_from'], $import['date_to']) }}.
          Dni bez wierszy w pliku nie są zapisywane jako zera – w raportach są widoczne jako brak danych.
        </x-pp.alert>
      </div>
    @endif

    @if ($import['granularity'] === 'period' && $declared && ($declared[0] !== $import['date_from'] || $declared[1] !== $import['date_to']))
      <div class="px-5 pb-5">
        <x-pp.alert type="warning">Zakres zapisany przy imporcie ({{ Format::period($import['date_from'], $import['date_to']) }}) różni się od zakresu w nagłówku eksportu ({{ Format::period($declared[0], $declared[1]) }}).</x-pp.alert>
      </div>
    @endif

    @if (($meta['merged_rows'] ?? 0) > 0)
      <div class="px-5 pb-5">
        <x-pp.alert type="info">{{ $meta['merged_rows'] }} wierszy miało ten sam klucz (np. segmentacja po urządzeniu) i zostało zsumowanych.</x-pp.alert>
      </div>
    @endif

    @if (! empty($import['errors']))
      <div class="px-5 pb-5">
        <x-pp.alert type="error" title="Błędy">
          <ul class="list-disc pl-5">@foreach ($import['errors'] as $message)<li>{{ $message }}</li>@endforeach</ul>
        </x-pp.alert>
      </div>
    @endif
  </section>

  @if ($import['status'] === 'completed' && isset($targets[$import['report_type']]))
    <div class="flex flex-wrap gap-3">
      <x-pp.button :href="$targets[$import['report_type']][0]">{{ $targets[$import['report_type']][1] }}</x-pp.button>
      <x-pp.button :href="AppUrl::project($project->id, 'imports')" variant="secondary" icon="import">Importuj kolejny raport</x-pp.button>
    </div>
  @endif
@endsection
