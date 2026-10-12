@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Metrics\MetricCalculator;
  use App\PennyPincher\Domain\Import\ValueNormalizer;

  $currency = $project->currency;
  $filtered = $filters['q'] !== '' || $filters['campaign_id'] || $filters['conversions'] !== '';
@endphp

@section('content')
  @include('app.partials.coverage', ['resolved' => $resolved, 'reportLabel' => 'raportu wyszukiwanych haseł'])

  <section class="pp-card min-w-0" aria-label="Wyszukiwane hasła">
    <x-pp.filter-bar :action="AppUrl::project($project->id, 'search-terms')">
      <input type="hidden" name="sort" value="{{ $sort }}">
      <input type="hidden" name="dir" value="{{ $direction }}">
      <div class="w-full sm:w-64">
        <label for="f-q" class="pp-label">Hasło</label>
        <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="pp-input pp-input-sm" placeholder="Szukaj w hasłach…">
      </div>
      <div class="w-full sm:w-64">
        <label for="f-campaign" class="pp-label">Kampania</label>
        <select id="f-campaign" name="campaign" class="pp-input pp-input-sm">
          <option value="">Wszystkie kampanie</option>
          @foreach ($campaignOptions as $id => $name)
            <option value="{{ $id }}" @selected($filters['campaign_id'] === (int) $id)>{{ $name }}</option>
          @endforeach
        </select>
      </div>
      <div class="w-full sm:w-52">
        <label for="f-conv" class="pp-label">Konwersje</label>
        <select id="f-conv" name="conversions" class="pp-input pp-input-sm">
          <option value="">Wszystkie</option>
          <option value="with" @selected($filters['conversions'] === 'with')>Z konwersjami</option>
          <option value="without" @selected($filters['conversions'] === 'without')>Bez konwersji</option>
        </select>
      </div>
    </x-pp.filter-bar>

    @if ($terms->items === [])
      <x-pp.empty-state :title="$filtered ? 'Żadne hasło nie pasuje do filtrów' : 'Brak wyszukiwanych haseł w tym zakresie'" icon="search">
        @if ($filtered)
          Zmień lub wyczyść filtry.
        @else
          Zaimportuj raport „Wyszukiwane hasła” z Google Ads. Przydadzą się w kolejnym etapie do wykrywania nieefektywnych zapytań.
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
              <x-pp.sort-header key="term" label="Wyszukiwane hasło" :sort="$sort" :direction="$direction" class="pp-col-sticky" />
              <x-pp.sort-header key="campaign" label="Kampania" :sort="$sort" :direction="$direction" />
              <th>Grupa reklam</th>
              <th>Dopasowanie</th>
              <x-pp.sort-header key="clicks" label="Kliknięcia" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="impressions" label="Wyświetlenia" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="spend" label="Wydatki" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="conversions" label="Konwersje" :sort="$sort" :direction="$direction" numeric />
              <x-pp.sort-header key="cpa" label="Koszt / konw." :sort="$sort" :direction="$direction" numeric />
            </tr>
          </thead>
          <tbody>
            @foreach ($terms->items as $row)
              <tr>
                <td class="pp-col-sticky max-w-[320px]">
                  <span class="block truncate font-medium" title="{{ $row['term'] }}">{{ $row['term'] }}</span>
                  @if ($row['keyword_text'])<span class="block truncate text-[12px] text-ink-mute">Słowo kluczowe: {{ $row['keyword_text'] }}</span>@endif
                </td>
                <td class="max-w-[220px]"><a href="{{ AppUrl::project($project->id, 'campaigns/' . $row['campaign_id']) }}" class="block truncate text-ink-soft hover:text-mint">{{ $row['campaign_name'] }}</a></td>
                <td class="max-w-[180px] truncate text-ink-soft">{{ $row['ad_group_name'] ?: Format::EMPTY }}</td>
                <td class="whitespace-nowrap text-ink-soft">{{ ValueNormalizer::matchTypeLabel($row['match_type']) }}</td>
                <td class="pp-col-num">{{ Format::number($row['clicks']) }}</td>
                <td class="pp-col-num">{{ Format::number($row['impressions']) }}</td>
                <td class="pp-col-num font-semibold text-copper">{{ Format::money($row['cost_micros'], $currency) }}</td>
                <td class="pp-col-num">{{ Format::conversions($row['conversions']) }}</td>
                <td class="pp-col-num">{{ Format::money(MetricCalculator::costPerConversion($row['cost_micros'], $row['conversions']), $currency) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <x-pp.pagination :paginator="$terms" label="haseł" />
    @endif
  </section>
@endsection
