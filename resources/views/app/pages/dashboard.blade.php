@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Metrics\MetricCalculator;

  $currency = $project->currency;
  $used = $kpis['budget_used'];
@endphp

@section('content')
  @include('app.partials.dashboard-health')
  @include('app.partials.dashboard-optimization')

  @if (! $hasAnyData)
    <section class="pp-card">
      <x-pp.empty-state title="Ten projekt nie ma jeszcze danych">
        Połącz konto Google Ads albo zaimportuj raport kampanii (najlepiej z kolumną „Dzień”), a dashboard pokaże wydatki, kliknięcia, konwersje i wykorzystanie budżetu.
        @if ($project->monthlyBudgetMicros !== null)
          Budżet miesięczny projektu: <strong class="pp-num">{{ Format::money($project->monthlyBudgetMicros, $currency, 0) }}</strong>.
        @endif
        <x-slot:actions>
          <x-pp.button :href="AppUrl::project($project->id, 'google-ads')" icon="link">Połącz Google Ads</x-pp.button>
          <x-pp.button :href="AppUrl::project($project->id, 'imports')" icon="import" variant="secondary">Zaimportuj raport</x-pp.button>
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

          @include('app.partials.dashboard-budget-range')
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
      @include('app.partials.dashboard-campaigns')

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
                <p class="pp-num text-caption text-ink-mute">{{ Format::period($import['date_from'], $import['date_to']) }}, {{ Format::datetime($import['created_at']) }}</p>
              </div>
              <x-pp.status-badge :status="$import['status']" />
            </li>
          @empty
            <li class="px-5 py-6 text-sm text-ink-soft">Brak importów.</li>
          @endforelse
        </ul>
      </section>
    </div>

    @include('app.partials.dashboard-search-terms')

    {{-- Działania powodujące konwersję --}}
    @include('app.partials.dashboard-conversions')
  @endif
@endsection
