@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Metrics\MetricCalculator;

  $currency = $project->currency;
  $statusOptions = ['' => 'Wszystkie', 'enabled' => 'Aktywne', 'paused' => 'Wstrzymane', 'removed' => 'Usunięte', 'ended' => 'Zakończone', 'other' => 'Inne', 'unknown' => 'Brak danych o stanie'];
@endphp

@section('content')
  @include('app.partials.coverage', ['resolved' => $resolved, 'reportLabel' => 'raportu kampanii'])

  <section class="pp-card min-w-0" aria-label="Lista kampanii">
    <x-pp.filter-bar :action="AppUrl::project($project->id, 'campaigns')">
      <input type="hidden" name="sort" value="{{ $sort }}">
      <input type="hidden" name="dir" value="{{ $direction }}">
      <div class="w-full sm:w-64">
        <label for="f-q" class="pp-label">Nazwa kampanii</label>
        <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="pp-input pp-input-sm" placeholder="Szukaj…">
      </div>
      <div class="w-full sm:w-52">
        <label for="f-status" class="pp-label">Stan</label>
        <select id="f-status" name="status" class="pp-input pp-input-sm">
          @foreach ($statusOptions as $value => $label)
            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    </x-pp.filter-bar>

    @if ($campaigns->items === [])
      <x-pp.empty-state title="{{ $filters['q'] || $filters['status'] ? 'Żadna kampania nie pasuje do filtrów' : 'Brak kampanii' }}" icon="campaigns">
        @if ($filters['q'] || $filters['status'])
          Zmień lub wyczyść filtry.
        @else
          Kampanie pojawią się po zaimportowaniu raportu kampanii, wyszukiwanych haseł lub słów kluczowych.
        @endif
        <x-slot:actions>
          <x-pp.button :href="AppUrl::project($project->id, 'imports')" icon="import" variant="secondary">Importuj raport</x-pp.button>
        </x-slot:actions>
      </x-pp.empty-state>
    @else
      <div class="pp-table-wrap">
        <table class="pp-table">
          <thead>
            <tr>
              <x-pp.sort-header key="name" label="Kampania" :sort="$sort" :direction="$direction" class="pp-col-sticky" />
              <x-pp.sort-header key="status" label="Stan" :sort="$sort" :direction="$direction" />
              <th>Typ</th>
              <th class="pp-col-num">Budżet</th>
              <x-pp.sort-header key="spend" label="Wydatki" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="clicks" label="Kliknięcia" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="impressions" label="Wyświetlenia" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="ctr" label="CTR" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="cpc" label="Śr. CPC" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="conversions" label="Konwersje" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="cpa" label="Koszt / konw." :sort="$sort" :direction="$direction" numeric />
            </tr>
          </thead>
          <tbody>
            @foreach ($campaigns->items as $row)
              <tr>
                <td class="pp-col-sticky max-w-[300px]">
                  <a href="{{ AppUrl::project($project->id, 'campaigns/' . $row['id']) }}" class="block truncate font-medium text-ink hover:text-mint" title="{{ $row['name'] }}">{{ $row['name'] }}</a>
                </td>
                <td><x-pp.status-badge :status="$row['status'] ?? 'unknown'" :label="$row['status'] ? null : 'Brak danych'" title="{{ $row['status_raw'] }}" /></td>
                <td class="whitespace-nowrap text-ink-soft">{{ $row['channel_type'] ?: Format::EMPTY }}</td>
                <td class="pp-col-num text-ink-soft">
                  {{ Format::money($row['budget_micros'] !== null ? (int) $row['budget_micros'] : null, $currency) }}
                  @if ($row['budget_period'])<span class="block text-[12px] text-ink-mute">{{ $row['budget_period'] }}</span>@endif
                </td>
                <td class="pp-col-num font-semibold text-copper">{{ Format::money($row['cost_micros'], $currency) }}</td>
                <td class="pp-col-num">{{ Format::number($row['clicks']) }}</td>
                <td class="pp-col-num">{{ Format::number($row['impressions']) }}</td>
                <td class="pp-col-num">{{ Format::percent(MetricCalculator::ctr($row['clicks'], $row['impressions'])) }}</td>
                <td class="pp-col-num">{{ Format::money(MetricCalculator::averageCpc($row['cost_micros'], $row['clicks']), $currency) }}</td>
                <td class="pp-col-num">{{ Format::conversions($row['conversions']) }}</td>
                <td class="pp-col-num">{{ Format::money(MetricCalculator::costPerConversion($row['cost_micros'], $row['conversions']), $currency) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <x-pp.pagination :paginator="$campaigns" label="kampanii" />
    @endif
  </section>

  <p class="text-[13px] text-ink-mute">„—” oznacza brak danych w zaimportowanych raportach (nie zero). Kampanie bez danych w wybranym zakresie są na końcu listy.</p>
@endsection
