{{-- Kampania › Optymalizacja: wycieki i rekomendacje tej kampanii (tylko odczyt, decyzje w modułach źródłowych). --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Leaks\Leak;
  use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $campaignId = (int) $campaign['id'];
  $leakQuery = ($leakRun ? ['period' => $leakRun['range_preset'] === 'custom' || ! $leakRun['range_preset'] ? 'custom' : $leakRun['range_preset']] + ($leakRun['range_preset'] === 'custom' || ! $leakRun['range_preset'] ? ['from' => $leakRun['date_from'], 'to' => $leakRun['date_to']] : []) : []) + ['campaign' => $campaignId];
@endphp

@section('content')
  <x-pp.campaign-tabs :project="$project" :campaign-id="$campaignId" :campaign-name="$campaign['name']" active="optimization" />

  <section class="pp-card min-w-0" aria-labelledby="pp-campaign-leaks">
    <div class="pp-card-header">
      <div>
        <h2 id="pp-campaign-leaks" class="pp-card-title">Wycieki budżetu</h2>
        @if ($leakRun)
          <p class="pp-num mt-0.5 text-meta text-ink-mute">Ostatnia analiza: {{ Format::period($leakRun['date_from'], $leakRun['date_to']) }}</p>
        @endif
      </div>
      <a href="{{ AppUrl::project($project->id, 'budget-leaks', $leakQuery) }}" class="text-meta font-semibold text-mint hover:underline">Otwórz w Budget Leaks</a>
    </div>
    @if (! $leakRun)
      <p class="px-5 py-4 text-sm text-ink-soft">Analiza wycieków nie była jeszcze uruchamiana. Uruchom ją w module Budget Leaks albo z Centrum dowodzenia.</p>
    @elseif ($leaks === [])
      <p class="px-5 py-4 text-sm text-ink-soft">W ostatniej analizie nie wykryto wycieków w tej kampanii.</p>
    @else
      <ul class="divide-y divide-line">
        @foreach ($leaks as $leak)
          <li>
            <a href="{{ AppUrl::project($project->id, 'budget-leaks', $leakQuery) }}#leak-{{ $leak['id'] }}" class="flex items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]">
              <x-pp.status-badge :status="$leak['priority']" :label="Leak::PRIORITIES[$leak['priority']]" class="mt-0.5" />
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-ink">{{ $leak['title'] }}</span>
                <span class="block text-caption text-ink-mute">{{ Leak::CATEGORIES[$leak['category']] ?? '' }}</span>
              </span>
              <span class="pp-num shrink-0 text-sm font-semibold text-copper" title="Koszt w okresie analizy">{{ Format::money($leak['cost_micros'], $currency, 0) }}</span>
            </a>
          </li>
        @endforeach
      </ul>
    @endif
  </section>

  <section class="pp-card min-w-0" aria-labelledby="pp-campaign-recs">
    <div class="pp-card-header">
      <h2 id="pp-campaign-recs" class="pp-card-title">Rekomendacje <span class="pp-num text-ink-mute">({{ $recommendations->total }})</span></h2>
      <a href="{{ AppUrl::project($project->id, 'recommendations', ['campaign' => $campaignId]) }}" class="text-meta font-semibold text-mint hover:underline">Wszystkie rekomendacje kampanii</a>
    </div>
    @if ($recommendations->items === [])
      <p class="px-5 py-4 text-sm text-ink-soft">Brak aktywnych rekomendacji dla tej kampanii.</p>
    @else
      <ul class="divide-y divide-line">
        @foreach ($recommendations->items as $rec)
          <li>
            <a href="{{ AppUrl::project($project->id, 'recommendations', ['campaign' => $campaignId, 'open' => $rec['id']]) }}#rec-{{ $rec['id'] }}" class="flex flex-wrap items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]">
              <x-pp.status-badge :status="$rec['priority']" class="mt-0.5" />
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-ink">{{ $rec['title'] }}</span>
                <span class="block text-caption text-ink-mute">
                  {{ implode(' + ', array_map(fn ($s) => RecommendationGenerator::SOURCES[$s] ?? $s, $rec['sources'] ?? [])) }}
                  · pewność: {{ mb_strtolower(Statistics::CONFIDENCE_LABELS[$rec['confidence']] ?? $rec['confidence']) }}
                  @if ($rec['impact_label']) · {{ $rec['impact_label'] }} @endif
                </span>
              </span>
              <x-pp.status-badge :status="$rec['status']" />
            </a>
          </li>
        @endforeach
      </ul>
    @endif
  </section>
@endsection
