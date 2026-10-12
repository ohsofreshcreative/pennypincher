@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Access\Ability;
  use App\PennyPincher\Domain\Access\Policy;

  $canImport = Policy::allows($project->role, Ability::ImportData);
@endphp

@section('content')
  <div class="grid gap-6 2xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
    <section class="pp-card" aria-labelledby="pp-upload">
      <div class="pp-card-header">
        <h2 id="pp-upload" class="pp-card-title">Nowy import</h2>
      </div>

      @if ($canImport)
        <form method="post" action="{{ AppUrl::project($project->id, 'imports') }}" enctype="multipart/form-data" class="space-y-5 p-5" data-busy>
          <x-pp.nonce />

          @if ($uploadError)
            <x-pp.alert type="error" title="Nie udało się odczytać pliku">{{ $uploadError }}</x-pp.alert>
          @endif

          <div>
            <label for="f-report" class="pp-label">Plik z Google Ads</label>
            <input id="f-report" type="file" name="report" accept=".csv,.tsv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
              class="block w-full text-sm text-ink-soft file:mr-4 file:min-h-10 file:cursor-pointer file:rounded-[var(--radius-control)] file:border file:border-line-strong file:bg-surface file:px-4 file:text-sm file:font-semibold file:text-ink hover:file:border-ink-mute">
            <p class="pp-help">CSV (także „Excel CSV” w UTF-16) lub XLSX, maksymalnie {{ size_format($maxBytes) }}. Nagłówki po polsku lub angielsku.</p>
          </div>

          <div>
            <label for="f-type" class="pp-label">Typ raportu</label>
            <select id="f-type" name="report_type" class="pp-input">
              <option value="">Rozpoznaj automatycznie</option>
              @foreach ($definitions as $type => $definition)
                <option value="{{ $type }}" @selected(($old['report_type'] ?? '') === $type)>{{ $definition->label() }}</option>
              @endforeach
            </select>
            <p class="pp-help">Po przesłaniu zobaczysz podgląd i mapowanie kolumn. Nic nie zostanie zapisane, dopóki nie zatwierdzisz importu.</p>
          </div>

          <x-pp.button icon="import" data-busy-label="Odczytywanie pliku…">Prześlij i pokaż podgląd</x-pp.button>
        </form>
      @else
        <p class="p-5 text-sm text-ink-soft">Twoja rola nie pozwala na importowanie danych.</p>
      @endif

      <div class="border-t border-line p-5">
        <h3 class="text-sm font-semibold text-ink">Obsługiwane raporty</h3>
        <ul class="mt-3 space-y-3 text-sm">
          @foreach ($definitions as $definition)
            <li>
              <p class="font-medium text-ink">{{ $definition->label() }}</p>
              <p class="text-ink-mute">{{ $definition->description() }}</p>
            </li>
          @endforeach
        </ul>
      </div>
    </section>

    <section class="pp-card min-w-0" aria-labelledby="pp-history">
      <div class="pp-card-header">
        <h2 id="pp-history" class="pp-card-title">Historia importów</h2>
      </div>

      @if ($imports->items === [])
        <x-pp.empty-state title="Jeszcze nic nie zaimportowano" icon="file">
          W Google Ads otwórz raport (np. Kampanie), ustaw zakres dat, dodaj segment „Dzień”, jeśli chcesz wykresy dzienne, i pobierz go jako CSV lub XLSX.
        </x-pp.empty-state>
      @else
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead>
              <tr>
                <th>Plik</th>
                <th>Typ</th>
                <th>Okres</th>
                <th class="pp-col-num">Wiersze</th>
                <th>Stan</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($imports->items as $import)
                <tr>
                  <td class="max-w-[240px]">
                    <a href="{{ AppUrl::project($project->id, 'imports/' . $import['id']) }}" class="block truncate font-medium text-ink hover:text-mint">{{ $import['original_filename'] }}</a>
                    <span class="pp-num text-[12px] text-ink-mute">#{{ $import['id'] }}, {{ Format::datetime($import['created_at']) }}{{ $import['user_name'] ? ', ' . $import['user_name'] : '' }}</span>
                  </td>
                  <td class="whitespace-nowrap text-ink-soft">{{ $definitions[$import['report_type']] ?? null ? $definitions[$import['report_type']]->label() : 'Nierozpoznany' }}</td>
                  <td class="pp-num whitespace-nowrap text-ink-soft">
                    {{ Format::period($import['date_from'], $import['date_to']) }}
                    @if ($import['granularity'])<span class="block text-[12px] text-ink-mute">{{ $import['granularity'] === 'day' ? 'dane dzienne' : 'suma za okres' }}</span>@endif
                  </td>
                  <td class="pp-col-num">{{ $import['status'] === 'completed' ? Format::number($import['rows_imported']) : Format::number($import['rows_total']) }}</td>
                  <td><x-pp.status-badge :status="$import['status']" /></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <x-pp.pagination :paginator="$imports" label="importów" />
      @endif
    </section>
  </div>
@endsection
