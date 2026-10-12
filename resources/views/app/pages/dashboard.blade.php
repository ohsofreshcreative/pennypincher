@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Metrics\MetricCalculator;

  $currency = $project->currency;
  $used = $kpis['budget_used'];
@endphp

@section('content')
  @if (! $hasAnyData)
    <section class="pp-card">
      <x-pp.empty-state title="Ten projekt nie ma jeszcze danych">
        Zaimportuj raport kampanii z Google Ads (najlepiej z kolumną „Dzień”), a dashboard pokaże wydatki, kliknięcia, konwersje i wykorzystanie budżetu.
        @if ($project->monthlyBudgetMicros !== null)
          Budżet miesięczny projektu: <strong class="pp-num">{{ Format::money($project->monthlyBudgetMicros, $currency, 0) }}</strong>.
        @endif
        <x-slot:actions>
          <x-pp.button :href="AppUrl::project($project->id, 'imports')" icon="import">Zaimportuj raport</x-pp.button>
          <x-pp.button :href="AppUrl::project($project->id, 'settings')" variant="secondary">Uzupełnij ustawienia</x-pp.button>
        </x-slot:actions>
      </x-pp.empty-state>
    </section>
  @else
    @include('app.partials.coverage', ['resolved' => $resolved, 'reportLabel' => 'raportu kampanii'])

    {{-- Wydatki i budżet --}}
    <section class="pp-card overflow-hidden" aria-labelledby="pp-spend-heading">
      <div class="grid lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <div class="border-b border-line p-5 sm:p-6 lg:border-r lg:border-b-0">
          <h2 id="pp-spend-heading" class="text-sm font-medium text-ink-soft">Wydatki w wybranym zakresie</h2>
          <p class="pp-display pp-num mt-2 text-[34px] leading-[1.1] text-copper sm:text-[40px]">{{ Format::money($kpis['spend'], $currency) }}</p>

          @if ($kpis['budget_for_range'] !== null)
            <div class="mt-5">
              <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="text-ink-soft">Budżet na ten zakres</span>
                <span class="pp-num font-semibold text-ink">{{ Format::money($kpis['budget_for_range'], $currency) }}</span>
              </div>
              <div class="pp-meter mt-2" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $used !== null ? round(min($used, 100)) : 0 }}" aria-label="Wykorzystanie budżetu">
                <div @class(['pp-meter-fill', 'pp-meter-over' => $used !== null && $used > 100]) style="width: {{ $used !== null ? min(100, round($used, 1)) : 0 }}%"></div>
              </div>
              <p class="mt-2 text-sm text-ink-soft">
                @if ($used === null)
                  Brak danych o wydatkach w tym zakresie.
                @elseif ($used > 100)
                  <strong class="pp-num text-danger">{{ Format::percent($used, 1) }}</strong> budżetu – wydatki przekroczyły plan o {{ Format::money($kpis['spend'] - $kpis['budget_for_range'], $currency) }}.
                @else
                  Wykorzystano <strong class="pp-num text-ink">{{ Format::percent($used, 1) }}</strong> budżetu.
                @endif
              </p>
            </div>
          @else
            <p class="mt-5 text-sm text-ink-soft">
              Ustaw budżet miesięczny, żeby śledzić jego wykorzystanie.
              <a href="{{ AppUrl::project($project->id, 'settings') }}" class="font-semibold text-mint underline-offset-2 hover:underline">Przejdź do ustawień</a>
            </p>
          @endif
        </div>

        <dl class="grid grid-cols-2 divide-line sm:grid-cols-3 [&>div]:border-b [&>div]:border-line">
          <x-pp.stat-card label="Kliknięcia" :value="Format::number($kpis['clicks'])" />
          <x-pp.stat-card label="Wyświetlenia" :value="Format::number($kpis['impressions'])" />
          <x-pp.stat-card label="CTR" :value="Format::percent($kpis['ctr'])" />
          <x-pp.stat-card label="Średni CPC" :value="Format::money($kpis['cpc'], $currency)" />
          <x-pp.stat-card label="Konwersje" :value="Format::conversions($kpis['conversions'])" />
          <x-pp.stat-card label="Koszt konwersji" :value="Format::money($kpis['cpa'], $currency)" />
          <x-pp.stat-card label="Budżet miesięczny" :value="Format::money($kpis['monthly_budget'], $currency, 0)" class="col-span-2 sm:col-span-3 !border-b-0" hint="Ustawienie projektu; budżet zakresu jest proporcjonalny do liczby dni w każdym miesiącu." />
        </dl>
      </div>
    </section>

    {{-- Wykresy --}}
    @if ($series !== [])
      <section aria-label="Wykresy dzienne" class="grid gap-4 md:grid-cols-3">
        <x-pp.line-chart :series="$series" metric="cost_micros" title="Wydatki dziennie" :total="Format::money($kpis['spend'], $currency)" color="var(--color-copper)"
          :format="fn ($v) => Format::money((int) $v, $currency)" />
        <x-pp.line-chart :series="$series" metric="clicks" title="Kliknięcia dziennie" :total="Format::number($kpis['clicks'])" color="var(--color-ink)"
          :format="fn ($v) => Format::number($v)" />
        <x-pp.line-chart :series="$series" metric="conversions" title="Konwersje dziennie" :total="Format::conversions($kpis['conversions'])" color="var(--color-mint)"
          :format="fn ($v) => Format::conversions($v)" />
      </section>
    @elseif (! $resolved->isEmpty())
      <x-pp.alert type="info">Wykresy dzienne wymagają raportu kampanii z kolumną „Dzień”. Dane w tym zakresie pochodzą z raportów okresowych, więc pokazujemy tylko sumy.</x-pp.alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      {{-- Kampanie --}}
      <section class="pp-card min-w-0" aria-labelledby="pp-campaigns-heading">
        <div class="pp-card-header">
          <h2 id="pp-campaigns-heading" class="pp-card-title">Skuteczność kampanii</h2>
          <a href="{{ AppUrl::project($project->id, 'campaigns') }}" class="text-sm font-semibold text-mint underline-offset-2 hover:underline">Wszystkie kampanie</a>
        </div>
        @if ($campaigns->items === [])
          <p class="px-5 py-6 text-sm text-ink-soft">Brak kampanii w danych tego projektu.</p>
        @else
          <div class="pp-table-wrap">
            <table class="pp-table">
              <thead>
                <tr>
                  <th class="pp-col-sticky">Kampania</th>
                  <th class="pp-col-num">Wydatki</th>
                  <th class="pp-col-num">Kliknięcia</th>
                  <th class="pp-col-num">CTR</th>
                  <th class="pp-col-num">Konwersje</th>
                  <th class="pp-col-num">Koszt / konw.</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($campaigns->items as $row)
                  <tr>
                    <td class="pp-col-sticky max-w-[260px]">
                      <a href="{{ AppUrl::project($project->id, 'campaigns/' . $row['id']) }}" class="block truncate font-medium text-ink hover:text-mint">{{ $row['name'] }}</a>
                    </td>
                    <td class="pp-col-num font-semibold text-copper">{{ Format::money($row['cost_micros'], $currency) }}</td>
                    <td class="pp-col-num">{{ Format::number($row['clicks']) }}</td>
                    <td class="pp-col-num">{{ Format::percent(MetricCalculator::ctr($row['clicks'], $row['impressions'])) }}</td>
                    <td class="pp-col-num">{{ Format::conversions($row['conversions']) }}</td>
                    <td class="pp-col-num">{{ Format::money(MetricCalculator::costPerConversion($row['cost_micros'], $row['conversions']), $currency) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </section>

      {{-- Ostatnie importy --}}
      <section class="pp-card min-w-0" aria-labelledby="pp-imports-heading">
        <div class="pp-card-header">
          <h2 id="pp-imports-heading" class="pp-card-title">Ostatnie importy</h2>
          <a href="{{ AppUrl::project($project->id, 'imports') }}" class="text-sm font-semibold text-mint underline-offset-2 hover:underline">Importuj</a>
        </div>
        <ul class="divide-y divide-line">
          @forelse ($imports as $import)
            <li class="flex items-start justify-between gap-3 px-5 py-3">
              <div class="min-w-0">
                <a href="{{ AppUrl::project($project->id, 'imports/' . $import['id']) }}" class="block truncate text-sm font-medium text-ink hover:text-mint">{{ $import['original_filename'] }}</a>
                <p class="pp-num text-[12.5px] text-ink-mute">{{ Format::period($import['date_from'], $import['date_to']) }}, {{ Format::datetime($import['created_at']) }}</p>
              </div>
              <x-pp.status-badge :status="$import['status']" />
            </li>
          @empty
            <li class="px-5 py-6 text-sm text-ink-soft">Brak importów.</li>
          @endforelse
        </ul>
      </section>
    </div>

    {{-- Wyszukiwane hasła --}}
    <section class="pp-card min-w-0" aria-labelledby="pp-terms-heading">
      <div class="pp-card-header">
        <div>
          <h2 id="pp-terms-heading" class="pp-card-title">Wyszukiwane hasła z największymi wydatkami</h2>
          <p class="mt-0.5 text-[13px] text-ink-mute">Osobne źródło: raport wyszukiwanych haseł. Jego koszty nie są dodawane do wydatków kampanii.</p>
        </div>
        <a href="{{ AppUrl::project($project->id, 'search-terms') }}" class="text-sm font-semibold text-mint underline-offset-2 hover:underline">Wszystkie hasła</a>
      </div>
      @if ($searchTerms->items === [])
        <p class="px-5 py-6 text-sm text-ink-soft">
          @if ($searchResolved->outsidePeriods !== [])
            Raport wyszukiwanych haseł nie mieści się w całości w wybranym zakresie dat. Wybierz „Wszystkie dane” albo zakres raportu.
          @else
            Brak danych z raportu wyszukiwanych haseł w tym zakresie.
          @endif
        </p>
      @else
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead>
              <tr>
                <th class="pp-col-sticky">Hasło</th>
                <th>Kampania</th>
                <th class="pp-col-num">Wydatki</th>
                <th class="pp-col-num">Kliknięcia</th>
                <th class="pp-col-num">Konwersje</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($searchTerms->items as $row)
                <tr>
                  <td class="pp-col-sticky max-w-[280px] truncate font-medium">{{ $row['term'] }}</td>
                  <td class="max-w-[220px] truncate text-ink-soft">{{ $row['campaign_name'] }}</td>
                  <td class="pp-col-num font-semibold text-copper">{{ Format::money($row['cost_micros'], $currency) }}</td>
                  <td class="pp-col-num">{{ Format::number($row['clicks']) }}</td>
                  <td class="pp-col-num">{{ Format::conversions($row['conversions']) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>
  @endif
@endsection
