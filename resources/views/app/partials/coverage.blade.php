@php
  use App\PennyPincher\Support\Format;

  /** @var \App\PennyPincher\Domain\Metrics\ResolvedPeriods $resolved */
  $source = $resolved->source();
@endphp

@if ($resolved->isEmpty())
  <x-pp.alert type="warning" title="Brak danych {{ $reportLabel }} dla tego zakresu">
    @if ($resolved->outsidePeriods !== [])
      Dostępne raporty okresowe nie mieszczą się w całości w wybranym zakresie, a nie dzielimy ich na dni:
      @foreach ($resolved->outsidePeriods as [$from, $to])
        <span class="pp-num whitespace-nowrap">{{ Format::period($from, $to) }}</span>@if (! $loop->last), @endif
      @endforeach.
      Wybierz zakres obejmujący cały okres raportu albo zaimportuj raport z kolumną „Dzień”.
    @else
      Wybierz inny zakres dat albo zaimportuj raport obejmujący ten okres.
    @endif
  </x-pp.alert>
@else
  <p class="text-[13px] text-ink-mute">
    Dane z {{ $reportLabel }}:
    @if ($source === 'day')
      wartości dzienne.
    @elseif ($source === 'period')
      {{ count($resolved->periods) === 1 ? 'raport okresowy' : 'raporty okresowe' }}
      @foreach ($resolved->periods as [$from, $to])
        <span class="pp-num whitespace-nowrap">{{ Format::period($from, $to) }}</span>@if (! $loop->last), @endif
      @endforeach.
    @else
      wartości dzienne i raporty okresowe, bez nakładania się.
    @endif
    Pokrycie zakresu: <span class="pp-num">{{ min($resolved->coveredDays, $resolved->totalDays) }} z {{ $resolved->totalDays }}</span> dni.
  </p>

  @if (! $resolved->isComplete())
    <x-pp.alert type="info">Dane pokrywają tylko część wybranego zakresu. Sumy dotyczą wyłącznie dni z danymi, a budżet jest liczony dla całego zakresu, więc jego wykorzystanie może być zaniżone.</x-pp.alert>
  @endif

  @if ($resolved->excludedPeriods !== [] || $resolved->excludedDays > 0)
    <x-pp.alert type="info">
      Pominęliśmy dane, które nakładają się na wybrane źródło
      ({{ count($resolved->excludedPeriods) }} {{ count($resolved->excludedPeriods) === 1 ? 'raport okresowy' : 'raporty okresowe' }}@if ($resolved->excludedDays > 0), {{ $resolved->excludedDays }} dni danych dziennych @endif), żeby nie liczyć tych samych wydatków dwa razy.
    </x-pp.alert>
  @endif
@endif
