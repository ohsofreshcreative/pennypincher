{{-- Tabela kampanii z największymi wydatkami (Przegląd i Centrum dowodzenia). --}}
@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Metrics\MetricCalculator;

  $currency = $project->currency;
@endphp

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
