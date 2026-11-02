{{-- Wyszukiwane hasła z największymi wydatkami (osobne źródło – raport haseł). --}}
@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Metrics\MetricCalculator;

  $currency = $project->currency;
@endphp

<section class="pp-card min-w-0" aria-labelledby="pp-terms-heading">
  <div class="pp-card-header">
    <div>
      <h2 id="pp-terms-heading" class="pp-card-title">Wyszukiwane hasła z największymi wydatkami</h2>
      <p class="mt-0.5 text-meta text-ink-mute">Osobne źródło: raport wyszukiwanych haseł. Jego koszty nie są dodawane do wydatków kampanii.</p>
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
