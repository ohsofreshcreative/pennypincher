@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Audit\AuditScore;
  use App\PennyPincher\Domain\Audit\RuleRegistry;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $sections = [
    'critical' => ['Problemy krytyczne', 'Wymagają działania – realnie zagrażają budżetowi lub wiarygodności danych.'],
    'warning' => ['Ostrzeżenia', 'Warto sprawdzić – mogą obniżać skuteczność wydatków.'],
    'info' => ['Informacje', 'Ustawienia i obserwacje do świadomej oceny – niekoniecznie błędy.'],
  ];
  $triggers = ['manual' => 'ręcznie', 'sync' => 'po synchronizacji', 'cli' => 'z konsoli'];
  $latestId = $history[0]['id'] ?? null;
@endphp

@section('content')
  @if (! $run)
    <section class="pp-card">
      <x-pp.empty-state title="Brak audytu dla okresu {{ $period->range->label() }}" icon="audit">
        Audyt sprawdza {{ count(RuleRegistry::RULES) }} deterministycznych reguł: pomiar konwersji, ustawienia kampanii, tempo wydawania budżetu, jakość danych i zmiany skuteczności w wybranym okresie (porównanie z poprzednim okresem tej samej długości).
        Nie korzysta z AI i nie wprowadza żadnych zmian na koncie Google Ads.
        @if ($canRun)
          <x-slot:actions>
            <form method="post" action="{{ AppUrl::project($project->id, 'audits') }}" data-busy>
              <x-pp.nonce />
              @include('app.partials.period-fields', ['period' => $period])
              <x-pp.button icon="audit" data-busy-label="Analiza…">Uruchom audyt dla tego okresu</x-pp.button>
            </form>
          </x-slot:actions>
        @endif
      </x-pp.empty-state>
    </section>

    <div class="max-w-2xl">
      @include('app.partials.audit.history')
    </div>
  @else
    @if ($latestId !== null && (int) $run['id'] !== (int) $latestId)
      <x-pp.alert type="info">Przeglądasz audyt z {{ Format::datetime($run['created_at']) }} dla okresu {{ Format::period($run['date_from'], $run['date_to']) }}. <a href="{{ AppUrl::project($project->id, 'audits') }}" class="font-semibold underline">Przejdź do bieżącego okresu</a>.</x-pp.alert>
    @endif

    @if (($run['data_quality']['activity']['state'] ?? null) === 'no_activity')
      <x-pp.alert type="info" title="Brak aktywności reklamowej">{{ \App\PennyPincher\Domain\Audit\AuditScore::NO_ACTIVITY }}</x-pp.alert>
    @endif

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
      <div class="min-w-0 space-y-6">
        <section class="pp-card" aria-labelledby="pp-score">
          <div class="pp-card-header">
            <h2 id="pp-score" class="pp-card-title">Wynik audytu</h2>
            <p class="pp-num text-[13px] text-ink-mute">
              Dane: {{ Format::period($run['date_from'], $run['date_to']) }} · {{ Format::datetime($run['created_at']) }} · {{ $triggers[$run['trigger_type']] ?? $run['trigger_type'] }} · reguły v{{ $run['rules_version'] }}
            </p>
          </div>
          <div class="grid gap-6 p-5 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <div class="space-y-4">
              @include('app.partials.audit.score', ['run' => $run])
              <dl class="grid grid-cols-3 divide-x divide-line rounded-[var(--radius-control)] border border-line text-center">
                <div class="px-2 py-2.5"><dt class="text-[12px] text-ink-mute">Krytyczne</dt><dd class="pp-num text-lg font-semibold text-danger">{{ count($groups['critical']) }}</dd></div>
                <div class="px-2 py-2.5"><dt class="text-[12px] text-ink-mute">Ostrzeżenia</dt><dd class="pp-num text-lg font-semibold text-warn">{{ count($groups['warning']) }}</dd></div>
                <div class="px-2 py-2.5"><dt class="text-[12px] text-ink-mute">Informacje</dt><dd class="pp-num text-lg font-semibold text-info">{{ count($groups['info']) }}</dd></div>
              </dl>
              @if ($canRun)
                <form method="post" action="{{ AppUrl::project($project->id, 'audits') }}" data-busy>
                  <x-pp.nonce />
                  @include('app.partials.period-fields', ['period' => $period])
                  <x-pp.button variant="secondary" size="sm" icon="refresh" data-busy-label="Analiza…">Uruchom audyt ponownie</x-pp.button>
                </form>
              @endif
            </div>

            <div>
              <h3 class="text-[13px] font-semibold text-ink">Z czego wynika wynik</h3>
              <ul class="mt-2 space-y-3">
                @foreach ($run['score_breakdown']['dimensions'] ?? [] as $dimension)
                  <li>
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                      <span class="text-ink-soft">{{ $dimension['label'] }}</span>
                      <span class="pp-num text-ink">{{ rtrim(rtrim(number_format($dimension['points'], 1, ',', ''), '0'), ',') }} / {{ $dimension['max'] }}</span>
                    </div>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-ink/10" aria-hidden="true">
                      <div class="h-full rounded-full bg-mint" style="width: {{ $dimension['max'] > 0 ? round($dimension['points'] / $dimension['max'] * 100) : 0 }}%"></div>
                    </div>
                    @if ($dimension['findings'] > 0)
                      <p class="pp-num mt-0.5 text-[12px] text-ink-mute">−{{ rtrim(rtrim(number_format(min($dimension['penalty'], $dimension['max']), 1, ',', ''), '0'), ',') }} pkt za {{ $dimension['findings'] }} {{ $dimension['findings'] === 1 ? 'problem' : 'problemy' }}</p>
                    @endif
                  </li>
                @endforeach
              </ul>
              <p class="mt-3 text-[12px] leading-5 text-ink-mute">
                Audit Score ocenia higienę konta i wiarygodność danych – to nie jest ROAS ani prognoza wyników.
                Kary: krytyczny −10, ostrzeżenie −4, informacja −1 (niektóre informacje 0) w puli swojego obszaru. Pominięte problemy nie obniżają wyniku.
              </p>
            </div>
          </div>

          @php $quality = $run['data_quality'] ?? []; $activity = $quality['activity'] ?? null; @endphp
          @if ($quality)
            <div class="border-t border-line px-5 py-3 text-[12.5px] leading-5 text-ink-mute">
              @if ($activity)
                <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-3">
                  <div><dt class="inline">Pierwszy dzień z danymi:</dt> <dd class="pp-num inline text-ink">{{ Format::date($activity['first_day']) }}</dd></div>
                  <div><dt class="inline">Ostatni dzień z danymi:</dt> <dd class="pp-num inline text-ink">{{ Format::date($activity['last_day']) }}</dd></div>
                  <div><dt class="inline">Dni z aktywnością w okresie:</dt> <dd class="pp-num inline text-ink">{{ $activity['active_days'] ?? 'brak danych dziennych' }}</dd></div>
                  <div><dt class="inline">Źródło:</dt> <dd class="inline text-ink">{{ $activity['source_label'] }}</dd></div>
                  <div><dt class="inline">Kompletność:</dt> <dd class="pp-num inline text-ink">{{ $activity['covered_days'] }} z {{ $activity['total_days'] }} dni{{ $activity['complete'] ? '' : ' (niepełne)' }}</dd></div>
                  <div><dt class="inline">Ustawienia konwersji:</dt> <dd class="inline text-ink">{{ ! empty($quality['conversion_settings_known']) ? 'znane (API)' : 'nieznane' }}</dd></div>
                </dl>
              @else
                Dane kampanii: {{ $quality['covered_days'] ?? 0 }} z {{ $quality['total_days'] ?? 0 }} dni · źródło: {{ \App\PennyPincher\Services\Audit\AuditContextBuilder::sourceLabel($quality['source'] ?? null) }}
              @endif
              @if (! empty($quality['rule_errors']))
                <p class="mt-1 text-danger">Nie sprawdzono reguł: {{ implode(', ', $quality['rule_errors']) }}</p>
              @endif
            </div>
          @endif
        </section>

        @foreach ($sections as $key => [$title, $hint])
          <section aria-labelledby="pp-sec-{{ $key }}" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <h2 id="pp-sec-{{ $key }}" class="text-[15px] font-semibold text-ink">{{ $title }} <span class="pp-num text-ink-mute">({{ count($groups[$key]) }})</span></h2>
              <p class="text-[13px] text-ink-mute">{{ $hint }}</p>
            </div>
            @forelse ($groups[$key] as $finding)
              @include('app.partials.audit.finding', ['finding' => $finding, 'projectId' => $project->id, 'open' => $key === 'critical' && $finding['status'] === 'new'])
            @empty
              <p class="rounded-[var(--radius-card)] border border-dashed border-line px-5 py-4 text-sm text-ink-mute">Brak.</p>
            @endforelse
          </section>
        @endforeach

        @if ($groups['dismissed'] !== [])
          <details class="space-y-3">
            <summary class="cursor-pointer text-[15px] font-semibold text-ink">Pominięte <span class="pp-num text-ink-mute">({{ count($groups['dismissed']) }})</span></summary>
            <div class="mt-3 space-y-3">
              @foreach ($groups['dismissed'] as $finding)
                @include('app.partials.audit.finding', ['finding' => $finding, 'projectId' => $project->id])
              @endforeach
            </div>
          </details>
        @endif
      </div>

      <aside class="space-y-6">
        @include('app.partials.audit.history')

        <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
          <h2 class="pp-card-title">Jak działa audyt</h2>
          <p class="mt-2">Reguły są deterministyczne i opisane w dokumentacji – ten sam zestaw danych daje zawsze ten sam wynik. Porównania okresów uwzględniają wielkość próby (np. min. 100 kliknięć), a reguły pomiaru konwersji opierają się na ustawieniach z Google Ads API, nie na nazwach.</p>
          <p class="mt-2">Status „Przejrzany” lub „Pominięty” przechodzi na kolejne audyty, dopóki problem występuje.</p>
        </section>
      </aside>
    </div>
  @endif
@endsection
