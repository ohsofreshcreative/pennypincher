{{-- Wykorzystanie budżetu w wybranym zakresie dat (budżet proporcjonalny do dni z danymi). --}}
@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $used = $kpis['budget_used'];
@endphp

@if ($kpis['budget_for_range'] !== null)
  <div class="mt-5">
    <div class="flex items-baseline justify-between gap-3 text-sm">
      <span class="text-ink-soft">{{ $kpis['budget_partial'] ? 'Budżet dla dni z danymi' : 'Budżet na ten zakres' }}</span>
      <span class="pp-num font-semibold text-ink">{{ Format::money($kpis['budget_compared'], $currency) }}</span>
    </div>
    <div class="pp-meter mt-2" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $used !== null ? round(min($used, 100)) : 0 }}" aria-label="Wykorzystanie budżetu">
      <div @class(['pp-meter-fill', 'pp-meter-over' => $used !== null && $used > 100]) style="width: {{ $used !== null ? min(100, round($used, 1)) : 0 }}%"></div>
    </div>
    <p class="mt-2 text-sm text-ink-soft">
      @if ($used === null)
        Brak danych o wydatkach w tym zakresie.
      @elseif ($used > 100)
        <strong class="pp-num text-danger">{{ Format::percent($used, 1) }}</strong> budżetu – wydatki przekroczyły plan o {{ Format::money($kpis['spend'] - $kpis['budget_compared'], $currency) }}.
      @else
        Wykorzystano <strong class="pp-num text-ink">{{ Format::percent($used, 1) }}</strong> budżetu.
      @endif
      @if ($kpis['budget_partial'] && $used !== null)
        <span class="block text-meta text-ink-mute">Budżet całego zakresu: {{ Format::money($kpis['budget_for_range'], $currency) }}.</span>
      @endif
    </p>
  </div>
@else
  <p class="mt-5 text-sm text-ink-soft">
    Ustaw budżet miesięczny, żeby śledzić jego wykorzystanie.
    <a href="{{ AppUrl::project($project->id, 'settings') }}" class="font-semibold text-mint underline-offset-2 hover:underline">Przejdź do ustawień</a>
  </p>
@endif
