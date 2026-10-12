@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $postedMapping = $old['mapping'] ?? null;
  $currentMapping = $postedMapping !== null ? $postedMapping : $mapping;
  $mappedColumns = array_flip(array_map('intval', array_filter($currentMapping, fn ($v) => $v !== '' && $v !== null)));
  $periodFrom = $old['date_from'] ?? ($period[0] ?? '');
  $periodTo = $old['date_to'] ?? ($period[1] ?? '');
  $fieldErrors = array_filter($errors, fn ($key) => str_starts_with($key, 'mapping.'), ARRAY_FILTER_USE_KEY);
  $missing = array_values(array_filter($errors, fn ($key) => str_starts_with($key, 'missing.'), ARRAY_FILTER_USE_KEY));
@endphp

@section('content')
  <a href="{{ AppUrl::project($project->id, 'imports') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-mint hover:underline">
    <x-pp.icon name="chevron-left" :size="16" />Importy
  </a>

  <section class="pp-card" aria-labelledby="pp-file">
    <div class="pp-card-header">
      <h2 id="pp-file" class="pp-card-title">{{ $import['original_filename'] }}</h2>
      <x-pp.status-badge status="uploaded" />
    </div>
    <dl class="grid gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-2 lg:grid-cols-5">
      <div><dt class="text-ink-mute">Format</dt><dd class="font-medium uppercase">{{ $import['file_format'] }}</dd></div>
      <div><dt class="text-ink-mute">Kodowanie / separator</dt><dd class="font-medium">{{ $import['encoding'] }}{{ $import['delimiter'] ? ' / ' . ($import['delimiter'] === 'tab' ? 'tabulator' : $import['delimiter']) : '' }}</dd></div>
      <div><dt class="text-ink-mute">Wiersz nagłówków</dt><dd class="pp-num font-medium">{{ $import['header_row'] }}</dd></div>
      <div><dt class="text-ink-mute">Wiersze danych</dt><dd class="pp-num font-medium">{{ Format::number($import['rows_total']) }}</dd></div>
      <div><dt class="text-ink-mute">Zapis liczb</dt><dd class="font-medium">{{ $import['number_format'] === 'comma' ? '1 234,56' : '1,234.56' }}</dd></div>
    </dl>
  </section>

  @if ($duplicate)
    <x-pp.alert type="warning" title="Ten plik był już importowany">
      Identyczny plik zaimportowano {{ Format::datetime($duplicate['completed_at']) }} (import #{{ $duplicate['id'] }}). Ponowny import nadpisze te same dane, nie podwoi ich.
    </x-pp.alert>
  @endif

  @if (! empty($import['errors']))
    <x-pp.alert type="error" title="Import nie został zapisany – żadne dane z pliku nie trafiły do bazy">
      <ul class="mt-1 list-disc space-y-0.5 pl-5">
        @foreach ($import['errors'] as $message)
          <li>{{ $message }}</li>
        @endforeach
      </ul>
    </x-pp.alert>
  @endif

  @if ($missing !== [] || isset($errors['period']) || isset($errors['report_type']))
    <x-pp.alert type="error" title="Popraw mapowanie przed importem">
      <ul class="mt-1 list-disc space-y-0.5 pl-5">
        @foreach ([...$missing, $errors['period'] ?? null, $errors['report_type'] ?? null] as $message)
          @if ($message)<li>{{ $message }}</li>@endif
        @endforeach
      </ul>
    </x-pp.alert>
  @endif

  {{-- Typ raportu --}}
  <section class="pp-card" aria-labelledby="pp-type">
    <form method="post" action="{{ AppUrl::project($project->id, 'imports/' . $import['id']) }}" class="flex flex-wrap items-end gap-3 p-5">
      <x-pp.nonce />
      <input type="hidden" name="action" value="retype">
      <div class="w-full sm:w-80">
        <label for="f-retype" id="pp-type" class="pp-label">Typ raportu</label>
        <select id="f-retype" name="report_type" class="pp-input" data-autosubmit>
          @unless ($definition)<option value="">Nie rozpoznano – wybierz typ</option>@endunless
          @foreach ($definitions as $type => $item)
            <option value="{{ $type }}" @selected($definition && $definition->type() === $type)>{{ $item->label() }}</option>
          @endforeach
        </select>
      </div>
      <noscript><x-pp.button variant="secondary">Zmień typ</x-pp.button></noscript>
      <p class="pp-help w-full sm:w-auto sm:flex-1">
        @if ($definition)
          {{ $definition->description() }} Zmiana typu zaproponuje nowe mapowanie kolumn.
        @else
          Nie udało się rozpoznać raportu po nagłówkach. Wybierz typ, a zaproponujemy mapowanie.
        @endif
      </p>
    </form>
  </section>

  @if ($definition)
    <form method="post" action="{{ AppUrl::project($project->id, 'imports/' . $import['id']) }}" data-busy class="space-y-6">
      <x-pp.nonce />
      <input type="hidden" name="action" value="confirm">
      <input type="hidden" name="report_type" value="{{ $definition->type() }}">

      <section class="pp-card" aria-labelledby="pp-mapping">
        <div class="pp-card-header">
          <h2 id="pp-mapping" class="pp-card-title">Mapowanie kolumn</h2>
          <p class="text-[13px] text-ink-mute">Kolumny bez przypisania są pomijane. Metryk pochodnych (CTR, CPC) nie importujemy – liczymy je sami.</p>
        </div>
        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
          @foreach ($definition->fields() as $field)
            @php
              $selected = $currentMapping[$field->key] ?? '';
              $error = $fieldErrors['mapping.' . $field->key] ?? null;
            @endphp
            <div>
              <label for="map-{{ $field->key }}" class="pp-label">
                {{ $field->label }}
                @if ($field->required)<span class="font-normal text-copper">wymagane</span>@endif
              </label>
              <select id="map-{{ $field->key }}" name="mapping[{{ $field->key }}]" class="pp-input pp-input-sm" @if ($error) aria-invalid="true" @endif>
                <option value="">Nie importuj</option>
                @foreach ($headers as $index => $header)
                  @if ($header !== null && $header !== '')
                    <option value="{{ $index }}" @selected((string) $selected === (string) $index)>{{ $header }}</option>
                  @endif
                @endforeach
              </select>
              @if ($error)<p class="pp-error">{{ $error }}</p>@endif
            </div>
          @endforeach
        </div>
      </section>

      <section class="pp-card" aria-labelledby="pp-period">
        <div class="pp-card-header">
          <h2 id="pp-period" class="pp-card-title">Zakres dat raportu</h2>
        </div>
        <div class="space-y-4 p-5">
          <p class="text-sm text-ink-soft">
            Jeśli zmapujesz kolumnę „Dzień”, każdy wiersz zostanie zapisany jako dane z jednego dnia, a zakres poniżej zostanie pominięty.
            Bez kolumny „Dzień” wyniki zostaną zapisane jako suma za cały okres – nie dzielimy ich na dni.
          </p>
          @if ($detectedPeriod)
            <p class="text-sm">Zakres odczytany z nagłówka pliku: <strong class="pp-num">{{ Format::period($detectedPeriod[0], $detectedPeriod[1]) }}</strong></p>
          @endif
          <div class="flex flex-wrap items-end gap-3">
            <div>
              <label for="f-from" class="pp-label">Od</label>
              <input id="f-from" type="date" name="date_from" value="{{ $periodFrom }}" class="pp-input w-auto" @if (isset($errors['period'])) aria-invalid="true" @endif>
            </div>
            <div>
              <label for="f-to" class="pp-label">Do</label>
              <input id="f-to" type="date" name="date_to" value="{{ $periodTo }}" class="pp-input w-auto" @if (isset($errors['period'])) aria-invalid="true" @endif>
            </div>
          </div>
        </div>
      </section>

      <section class="pp-card min-w-0" aria-labelledby="pp-sample">
        <div class="pp-card-header">
          <h2 id="pp-sample" class="pp-card-title">Podgląd danych</h2>
          <p class="text-[13px] text-ink-mute">Pierwsze {{ count($sample) }} wierszy pod nagłówkiem. Podświetlone kolumny są zmapowane.</p>
        </div>
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead>
              <tr>
                <th class="pp-num">Wiersz</th>
                @foreach ($headers as $index => $header)
                  <th @class(['!bg-mint-soft !text-mint-dark' => isset($mappedColumns[$index])])>{{ $header ?: '(bez nazwy)' }}</th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @foreach ($sample as $row)
                <tr>
                  <td class="pp-num text-ink-mute">{{ $row['line'] }}</td>
                  @foreach ($headers as $index => $header)
                    <td @class(['whitespace-nowrap', 'bg-mint-soft/40' => isset($mappedColumns[$index])])>{{ $row['cells'][$index] ?? '' }}</td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>

      <div class="flex flex-wrap items-center gap-3">
        <x-pp.button icon="check" data-busy-label="Importowanie…">Zatwierdź import</x-pp.button>
        <x-pp.button variant="secondary" type="submit" form="pp-cancel-import">Anuluj import</x-pp.button>
        <p class="text-sm text-ink-mute">Dane są walidowane w całości. Jeśli którykolwiek wiersz zawiera błąd, nic nie zostanie zapisane.</p>
      </div>
    </form>
  @endif

  <form id="pp-cancel-import" method="post" action="{{ AppUrl::project($project->id, 'imports/' . $import['id'] . '/cancel') }}" data-confirm="Anulować import? Przesłany plik zostanie odrzucony.">
    <x-pp.nonce />
    @unless ($definition)
      <x-pp.button variant="secondary">Anuluj import</x-pp.button>
    @endunless
  </form>
@endsection
