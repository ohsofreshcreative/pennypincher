{{-- Działania powodujące konwersję (raport konwersji). --}}
@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Metrics\MetricCalculator;

  $currency = $project->currency;
@endphp

<section class="pp-card min-w-0" aria-labelledby="pp-conversions-heading">
  <div class="pp-card-header">
    <div>
      <h2 id="pp-conversions-heading" class="pp-card-title">Działania powodujące konwersję</h2>
      <p class="mt-0.5 text-meta text-ink-mute">Źródło: raport konwersji. Konwersje po obejrzeniu pokazujemy osobno – nie są doliczane do konwersji.</p>
    </div>
  </div>
  @if ($conversionActions === [])
    <p class="px-5 py-6 text-sm text-ink-soft">
      @if ($conversionResolved->outsidePeriods !== [])
        Raport konwersji nie mieści się w całości w wybranym zakresie dat
        (@foreach ($conversionResolved->outsidePeriods as [$from, $to])<span class="pp-num whitespace-nowrap">{{ Format::period($from, $to) }}</span>@if (! $loop->last), @endif @endforeach).
        Wybierz „Wszystkie dane” albo zakres raportu.
      @else
        Brak danych z raportu konwersji w tym zakresie. Zaimportuj raport „Konwersje” z Google Ads, żeby sprawdzić, co jest liczone jako konwersja.
      @endif
    </p>
  @else
    <p class="px-5 pt-3 text-meta text-ink-mute">
      @foreach ($conversionResolved->periods as [$from, $to])
        Okres raportu: <span class="pp-num">{{ Format::period($from, $to) }}</span>.
      @endforeach
      @if ($conversionResolved->hasDaily()) Dane dzienne. @endif
      Pokrycie zakresu: <span class="pp-num">{{ min($conversionResolved->coveredDays, $conversionResolved->totalDays) }} z {{ $conversionResolved->totalDays }}</span> dni.
    </p>
    <div class="pp-table-wrap">
      <table class="pp-table">
        <thead>
          <tr>
            <th class="pp-col-sticky">Działanie</th>
            <th>Kategoria</th>
            <th>W „Konwersjach”</th>
            <th class="pp-col-num">Konwersje</th>
            <th class="pp-col-num">Konw. po obejrzeniu</th>
            <th class="pp-col-num">Wszystkie konw.</th>
            <th class="pp-col-num">Wartość</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($conversionActions as $action)
            <tr>
              <td class="pp-col-sticky max-w-[300px] truncate font-medium" title="{{ $action['name'] }}">{{ $action['name'] }}</td>
              <td class="max-w-[200px] truncate text-ink-soft">{{ $action['category'] ?: Format::EMPTY }}</td>
              <td class="text-ink-soft">{{ $action['include_in_conversions'] === null ? Format::EMPTY : ((int) $action['include_in_conversions'] ? 'Tak' : 'Nie') }}</td>
              <td class="pp-col-num font-semibold">{{ Format::conversions($action['conversions']) }}</td>
              <td class="pp-col-num">{{ Format::conversions($action['view_through_conversions']) }}</td>
              <td class="pp-col-num">{{ Format::conversions($action['all_conversions']) }}</td>
              <td class="pp-col-num">{{ Format::money($action['conversions_value_micros'] !== null ? (int) $action['conversions_value_micros'] : null, $currency) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</section>
