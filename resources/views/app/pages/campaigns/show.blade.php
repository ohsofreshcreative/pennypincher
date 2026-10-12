@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Metrics\MetricCalculator;
  use App\PennyPincher\Domain\Import\ValueNormalizer;

  $currency = $project->currency;
  $reportLabels = ['campaigns' => 'Kampanie', 'search_terms' => 'Wyszukiwane hasła', 'keywords' => 'Słowa kluczowe', 'conversion_actions' => 'Konwersje'];
@endphp

@section('content')
  <a href="{{ AppUrl::project($project->id, 'campaigns') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-mint hover:underline">
    <x-pp.icon name="chevron-left" :size="16" />Wszystkie kampanie
  </a>

  <section class="pp-card" aria-label="Szczegóły kampanii">
    <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
      <div><dt class="text-[13px] text-ink-mute">Stan</dt><dd class="mt-1"><x-pp.status-badge :status="$campaign['status'] ?? 'unknown'" :label="$campaign['status'] ? null : 'Brak danych'" />@if ($campaign['status_raw'])<span class="ml-2 text-sm text-ink-soft">{{ $campaign['status_raw'] }}</span>@endif</dd></div>
      <div><dt class="text-[13px] text-ink-mute">Typ kampanii</dt><dd class="mt-1 font-medium">{{ $campaign['channel_type'] ?: Format::EMPTY }}</dd></div>
      <div><dt class="text-[13px] text-ink-mute">Budżet</dt><dd class="pp-num mt-1 font-medium">{{ Format::money($campaign['budget_micros'] !== null ? (int) $campaign['budget_micros'] : null, $currency) }} {{ $campaign['budget_period'] }}</dd></div>
      <div><dt class="text-[13px] text-ink-mute">Identyfikator Google Ads</dt><dd class="pp-num mt-1 font-medium">{{ $campaign['external_id'] ?: 'Brak w eksportach' }}</dd></div>
    </dl>
  </section>

  @include('app.partials.coverage', ['resolved' => $resolved, 'reportLabel' => 'raportu kampanii'])

  <section class="pp-card" aria-label="Wyniki kampanii">
    <dl class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 [&>div]:border-b [&>div]:border-line lg:[&>div]:border-b-0">
      <x-pp.stat-card label="Wydatki" :value="Format::money($totals['cost_micros'], $currency)" tone="spend" />
      <x-pp.stat-card label="Kliknięcia" :value="Format::number($totals['clicks'])" />
      <x-pp.stat-card label="Wyświetlenia" :value="Format::number($totals['impressions'])" />
      <x-pp.stat-card label="CTR" :value="Format::percent(MetricCalculator::ctr($totals['clicks'], $totals['impressions']))" />
      <x-pp.stat-card label="Średni CPC" :value="Format::money(MetricCalculator::averageCpc($totals['cost_micros'], $totals['clicks']), $currency)" />
      <x-pp.stat-card label="Konwersje" :value="Format::conversions($totals['conversions'])" />
      <x-pp.stat-card label="Koszt / konw." :value="Format::money(MetricCalculator::costPerConversion($totals['cost_micros'], $totals['conversions']), $currency)" />
    </dl>
  </section>

  @if ($series !== [])
    <section class="grid gap-4 md:grid-cols-3" aria-label="Wykresy dzienne kampanii">
      <x-pp.line-chart :series="$series" metric="cost_micros" title="Wydatki dziennie" :total="Format::money($totals['cost_micros'], $currency)" color="var(--color-copper)" :format="fn ($v) => Format::money((int) $v, $currency)" />
      <x-pp.line-chart :series="$series" metric="clicks" title="Kliknięcia dziennie" :total="Format::number($totals['clicks'])" :format="fn ($v) => Format::number($v)" />
      <x-pp.line-chart :series="$series" metric="conversions" title="Konwersje dziennie" :total="Format::conversions($totals['conversions'])" color="var(--color-mint)" :format="fn ($v) => Format::conversions($v)" />
    </section>
  @endif

  <div class="grid gap-6 xl:grid-cols-2">
    <section class="pp-card min-w-0" aria-labelledby="pp-sources">
      <div class="pp-card-header">
        <h2 id="pp-sources" class="pp-card-title">Źródła danych</h2>
      </div>
      <div class="pp-table-wrap">
        <table class="pp-table">
          <thead><tr><th>Import</th><th>Ziarnistość</th><th>Okres</th><th class="pp-col-num">Wydatki</th></tr></thead>
          <tbody>
            @forelse ($sources as $source)
              <tr>
                <td class="max-w-[220px]">
                  @if ($source['source'] === 'api')
                    <a href="{{ AppUrl::project($project->id, 'google-ads') }}" class="block truncate font-medium hover:text-mint">Google Ads API</a><span class="text-[12px] text-ink-mute">Synchronizacja (źródło nadrzędne)</span>
                  @else
                    <a href="{{ AppUrl::project($project->id, 'imports/' . $source['import_id']) }}" class="block truncate font-medium hover:text-mint">#{{ $source['import_id'] }} {{ $source['original_filename'] }}</a><span class="text-[12px] text-ink-mute">{{ $reportLabels[$source['report_type']] ?? '' }}</span>
                  @endif
                </td>
                <td class="whitespace-nowrap">{{ $source['granularity'] === 'day' ? 'Dzienne (' . $source['rows_count'] . ' dni)' : 'Okres' }}</td>
                <td class="pp-num whitespace-nowrap">{{ Format::period($source['date_from'], $source['date_to']) }}</td>
                <td class="pp-col-num">{{ Format::money($source['cost_micros'] !== null ? (int) $source['cost_micros'] : null, $currency) }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-ink-soft">Kampania występuje w raportach haseł lub słów kluczowych, ale nie ma jeszcze danych z raportu kampanii.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    <section class="pp-card min-w-0" aria-labelledby="pp-campaign-terms">
      <div class="pp-card-header">
        <h2 id="pp-campaign-terms" class="pp-card-title">Wyszukiwane hasła z największymi wydatkami</h2>
        <a href="{{ AppUrl::project($project->id, 'search-terms', ['campaign' => $campaign['id']]) }}" class="text-sm font-semibold text-mint hover:underline">Wszystkie</a>
      </div>
      @if ($searchTerms->items === [])
        <p class="px-5 py-6 text-sm text-ink-soft">Brak danych z raportu wyszukiwanych haseł dla tej kampanii w wybranym zakresie.</p>
      @else
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead><tr><th>Hasło</th><th>Dopasowanie</th><th class="pp-col-num">Wydatki</th><th class="pp-col-num">Konw.</th></tr></thead>
            <tbody>
              @foreach ($searchTerms->items as $row)
                <tr>
                  <td class="max-w-[240px] truncate font-medium">{{ $row['term'] }}</td>
                  <td class="whitespace-nowrap text-ink-soft">{{ ValueNormalizer::matchTypeLabel($row['match_type']) }}</td>
                  <td class="pp-col-num font-semibold text-copper">{{ Format::money($row['cost_micros'], $currency) }}</td>
                  <td class="pp-col-num">{{ Format::conversions($row['conversions']) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>
  </div>
@endsection
