{{-- Tempo budżetu w bieżącym miesiącu (DashboardService::pacing). --}}
@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $fmtPct = fn (?float $v) => $v === null ? '—' : number_format($v, 0, ',', ' ') . ' %';
@endphp

<div class="pp-card p-5">
  <h2 class="text-meta font-medium text-ink-mute">Budżet w tym miesiącu ({{ $pacing['month'] }})</h2>
  @if (! $pacing['budget'])
    <p class="mt-2 text-sm text-ink-soft">Ustaw budżet miesięczny w <a href="{{ AppUrl::project($project->id, 'settings') }}" class="font-semibold text-mint hover:underline">ustawieniach projektu</a>, aby śledzić tempo wydatków.</p>
  @elseif ($pacing['spend'] === null)
    <p class="mt-2 text-sm text-ink-soft">Brak dziennych danych z tego miesiąca{{ $pacing['elapsed_days'] < 1 ? ' – miesiąc dopiero się zaczął' : '' }}.</p>
  @else
    <p class="pp-num mt-2 text-[22px] leading-7 font-semibold text-copper">{{ Format::money($pacing['spend'], $project->currency, 0) }}
      <span class="text-sm font-normal text-ink-mute">z {{ Format::money($pacing['budget'], $project->currency, 0) }}</span></p>
    <div class="relative mt-2 h-1.5 overflow-hidden rounded-full bg-ink/10" aria-hidden="true">
      <div class="h-full rounded-full {{ ($pacing['used'] ?? 0) > 100 ? 'bg-danger' : 'bg-copper' }}" style="width: {{ min(100, round($pacing['used'] ?? 0)) }}%"></div>
      <div class="absolute inset-y-0 w-0.5 bg-ink/50" style="left: {{ min(100, round($pacing['expected_share'] ?? 0)) }}%" title="Upłynęło miesiąca"></div>
    </div>
    <p class="pp-num mt-1.5 text-meta text-ink-mute">
      Wykorzystano {{ $fmtPct($pacing['used']) }} przy {{ $fmtPct($pacing['expected_share']) }} miesiąca.
      @if ($pacing['forecast'] !== null)
        Prognoza (szacunek): <span @class(['font-semibold', 'text-danger' => $pacing['forecast_share'] >= 110, 'text-ink' => $pacing['forecast_share'] < 110])>{{ Format::money($pacing['forecast'], $project->currency, 0) }}</span> ({{ $fmtPct($pacing['forecast_share']) }}).
      @else
        Prognozy nie liczymy – dane z tego miesiąca są niepełne.
      @endif
    </p>
  @endif
</div>
