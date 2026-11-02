{{--
  Centrum dowodzenia (flaga pp2_dashboard). Te same dane co Przegląd (DashboardService::build/health/optimization),
  inaczej ułożone: 4 KPI, budżet i tempo, 3 priorytety, źródła danych, akcje, historia, szczegóły eksperckie.
  Stary widok: pages/dashboard.blade.php (flaga wyłączona).
--}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Leaks\Leak;
  use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $o = $optimization;
  $connection = $health['connection'];
  $connected = $connection && $connection['customer_id'] && ($connection['status'] ?? null) === 'active';
  $leakPeriod = $o['leak_run'] ? ['period' => $o['leak_run']['range_preset'] === 'custom' ? 'custom' : $o['leak_run']['range_preset']] + ($o['leak_run']['range_preset'] === 'custom' ? ['from' => $o['leak_run']['date_from'], 'to' => $o['leak_run']['date_to']] : []) : [];
  $lastImport = collect($imports)->firstWhere('status', 'completed');

  $steps = [
    ['done' => $hasAnyData || $connected, 'title' => 'Dane konta', 'text' => 'Połącz Google Ads albo zaimportuj raport kampanii (najlepiej z kolumną „Dzień”).'],
    ['done' => $project->monthlyBudgetMicros !== null, 'title' => 'Budżet miesięczny', 'text' => 'Wpisz budżet w ustawieniach projektu – bez niego nie liczymy wykorzystania ani tempa wydatków.'],
    ['done' => (bool) ($o['leak_run'] || $health['audit']), 'title' => 'Pierwsza analiza', 'text' => 'Uruchom analizę okresu: audyt, wycieki budżetu, słowa kluczowe i rekomendacje (tylko dane z bazy, bez zmian na koncie).'],
  ];
  $stepsDone = count(array_filter(array_column($steps, 'done')));
  $priorities = $o['top_recommendations'];
@endphp

@section('content')
  {{-- Pierwsze kroki: jedna karta zamiast powtarzanych komunikatów „brak danych” --}}
  @if ($stepsDone < count($steps))
    <section class="pp-card" aria-labelledby="pp-onboarding">
      <div class="pp-card-header">
        <h2 id="pp-onboarding" class="pp-card-title">Pierwsze kroki</h2>
        <span class="pp-num text-meta text-ink-mute">{{ $stepsDone }} z {{ count($steps) }}</span>
      </div>
      <ol class="divide-y divide-line">
        @foreach ($steps as $i => $step)
          <li class="flex flex-wrap items-start gap-x-4 gap-y-3 px-5 py-4">
            <span @class(['grid size-7 shrink-0 place-items-center rounded-full text-meta font-semibold', 'bg-mint-soft text-mint' => $step['done'], 'bg-copper-soft text-copper' => ! $step['done']])>
              @if ($step['done'])<x-pp.icon name="check" :size="16" /><span class="sr-only">Zrobione:</span>@else{{ $i + 1 }}@endif
            </span>
            <div class="min-w-0 flex-1">
              <p @class(['font-semibold', 'text-ink' => ! $step['done'], 'text-ink-mute' => $step['done']])>{{ $step['title'] }}</p>
              @unless ($step['done'])<p class="text-sm text-ink-soft">{{ $step['text'] }}</p>@endunless
            </div>
            @unless ($step['done'])
              <div class="flex flex-wrap gap-2">
                @if ($i === 0)
                  <x-pp.button :href="AppUrl::project($project->id, 'google-ads')" icon="link" size="sm">Połącz Google Ads</x-pp.button>
                  <x-pp.button :href="AppUrl::project($project->id, 'imports')" icon="import" variant="secondary" size="sm">Zaimportuj raport</x-pp.button>
                @elseif ($i === 1)
                  <x-pp.button :href="AppUrl::project($project->id, 'settings')" variant="secondary" size="sm">Ustawienia projektu</x-pp.button>
                @elseif ($canRun && $hasAnyData && $analysisPeriod)
                  @include('app.partials.analysis.run-form', ['period' => $analysisPeriod, 'return' => '', 'size' => 'sm', 'label' => 'Uruchom analizę zakresu'])
                @else
                  <span class="text-meta text-ink-mute">{{ $hasAnyData ? 'Analizę uruchamia członek projektu z prawem importu danych.' : 'Dostępne po pierwszych danych.' }}</span>
                @endif
              </div>
            @endunless
          </li>
        @endforeach
      </ol>
    </section>
  @endif

  @foreach ($health['dataQuality'] as $finding)
    <x-pp.alert :type="$finding['severity'] === 'critical' ? 'error' : 'warning'" :title="$finding['title']">
      {{ $finding['recommendation'] }}
      <a href="{{ AppUrl::project($project->id, 'audits/' . $finding['audit_run_id']) }}#finding-{{ $finding['id'] }}" class="font-semibold underline">Szczegóły</a>
    </x-pp.alert>
  @endforeach

  @if ($hasAnyData)
    @include('app.partials.coverage', ['resolved' => $resolved, 'reportLabel' => 'raportu kampanii'])

    {{-- 4 KPI: ile wydaliśmy i co z tego mamy --}}
    <section class="pp-card overflow-hidden" aria-label="Najważniejsze wskaźniki">
      <dl class="grid grid-cols-2 gap-px bg-line lg:grid-cols-4 [&>div]:bg-surface">
        <x-pp.stat-card label="Wydatki" :value="Format::money($kpis['spend'], $currency)" tone="spend" data-kpi="spend" />
        <x-pp.stat-card label="Konwersje" :value="Format::conversions($kpis['conversions'])" data-kpi="conversions" />
        <x-pp.stat-card label="Koszt konwersji" :value="Format::money($kpis['cpa'], $currency)" data-kpi="cpa" />
        <x-pp.stat-card label="CTR" :value="Format::percent($kpis['ctr'])" :hint="'Średni CPC: ' . Format::money($kpis['cpc'], $currency)" data-kpi="ctr" />
      </dl>
    </section>

    {{-- Budżet: zakres dat i tempo bieżącego miesiąca --}}
    <section class="grid gap-4 md:grid-cols-2" aria-label="Budżet">
      <div class="pp-card p-5">
        <h2 class="text-meta font-medium text-ink-mute">Budżet w wybranym zakresie <span class="pp-num">({{ $range->label() }})</span></h2>
        @include('app.partials.dashboard-budget-range')
      </div>
      @include('app.partials.dashboard-pacing', ['pacing' => $health['pacing']])
    </section>
  @endif

  <div class="grid gap-6 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
    {{-- Co warto zrobić dzisiaj --}}
    <section class="pp-card min-w-0" aria-labelledby="pp-priorities">
      <div class="pp-card-header">
        <h2 id="pp-priorities" class="pp-card-title">Co warto zrobić dzisiaj</h2>
        <a href="{{ AppUrl::project($project->id, 'recommendations') }}" class="text-meta font-semibold text-mint hover:underline">Wszystkie rekomendacje</a>
      </div>
      @if ($priorities !== [])
        <ol class="divide-y divide-line">
          @foreach ($priorities as $rec)
            <li>
              <a href="{{ AppUrl::project($project->id, 'recommendations', ['open' => $rec['id']]) }}#rec-{{ $rec['id'] }}" class="flex items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]">
                <x-pp.status-badge :status="$rec['priority']" class="mt-0.5" />
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-medium text-ink">{{ $rec['title'] }}</span>
                  <span class="block text-caption text-ink-mute">
                    {{ implode(' + ', array_map(fn ($s) => RecommendationGenerator::SOURCES[$s] ?? $s, $rec['sources'] ?? [])) }}
                    · pewność: {{ mb_strtolower(Statistics::CONFIDENCE_LABELS[$rec['confidence']] ?? $rec['confidence']) }}
                    @if ($rec['impact_label']) · {{ $rec['impact_label'] }} @endif
                  </span>
                </span>
              </a>
            </li>
          @endforeach
        </ol>
      @else
        <p class="px-5 py-4 text-sm text-ink-soft">
          {{ $o['leak_run'] ? 'Brak otwartych rekomendacji z ostatniej analizy.' : 'Rekomendacje pojawią się po pierwszej analizie okresu.' }}
        </p>
      @endif

      {{-- Gdzie tracimy pieniądze: wycieki z ostatniej analizy i nowe alerty, z linkiem do dowodów --}}
      @if ($o['top_leaks'] !== [] || $newAlerts !== [])
        <div class="border-t border-line">
          <h3 class="px-5 pt-3 text-caption font-semibold text-ink-soft">Gdzie tracimy pieniądze</h3>
          <ul class="divide-y divide-line">
            @foreach ($o['top_leaks'] as $leak)
              <li>
                <a href="{{ AppUrl::project($project->id, 'budget-leaks', $leakPeriod) }}#leak-{{ $leak['id'] }}" class="flex items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]">
                  <x-pp.status-badge :status="$leak['priority']" :label="Leak::PRIORITIES[$leak['priority']]" class="mt-0.5" />
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ $leak['title'] }}</span>
                    <span class="block truncate text-caption text-ink-mute">Wyciek budżetu · {{ Leak::CATEGORIES[$leak['category']] ?? '' }}</span>
                  </span>
                  <span class="pp-num shrink-0 text-sm font-semibold text-copper" title="Koszt w okresie analizy">{{ Format::money($leak['cost_micros'], $currency, 0) }}</span>
                </a>
              </li>
            @endforeach
            @foreach ($newAlerts as $alert)
              <li>
                <a href="{{ AppUrl::project($project->id, 'monitoring', ['open' => $alert['id']]) }}#alert-{{ $alert['id'] }}" class="flex items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]">
                  <x-pp.status-badge :status="$alert['severity']" class="mt-0.5" />
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ $alert['title'] }}</span>
                    <span class="block truncate text-caption text-ink-mute">Alert monitoringu · {{ Format::datetime($alert['last_detected_at']) }}</span>
                  </span>
                </a>
              </li>
            @endforeach
          </ul>
          @if ($o['savings'] && $o['savings']['total'] > 0)
            <p class="pp-num border-t border-line px-5 py-2.5 text-caption text-ink-mute">
              Potencjał optymalizacji (SZACUNEK miesięczny, bez sumowania nakładających się problemów): <span class="font-semibold text-copper">{{ Format::money($o['savings']['total'], $currency, 0) }}</span>
            </p>
          @endif
        </div>
      @endif
    </section>

    {{-- Źródła danych i akcje --}}
    <section class="pp-card min-w-0" aria-labelledby="pp-sources">
      <div class="pp-card-header">
        <h2 id="pp-sources" class="pp-card-title">Źródła danych</h2>
      </div>
      <ul class="divide-y divide-line text-sm">
        <li class="flex items-start justify-between gap-3 px-5 py-3">
          <div class="min-w-0">
            <a href="{{ AppUrl::project($project->id, 'google-ads') }}" class="font-medium text-ink hover:text-mint">Google Ads API</a>
            <p class="pp-num text-caption text-ink-mute">
              @if ($connection && $connection['customer_id'])
                {{ $connection['customer_name'] ?: $connection['customer_id'] }} · ostatnia synchronizacja: {{ $health['lastSync'] ? Format::datetime($health['lastSync']['finished_at']) : 'jeszcze nie' }}
              @elseif ($connection)
                Wybierz konto reklamowe, aby rozpocząć synchronizację.
              @else
                Nie połączono – dane tylko z importów.
              @endif
            </p>
          </div>
          @if ($connection)
            <x-pp.status-badge :status="($health['lastRun']['status'] ?? null) === 'failed' ? 'failed' : $connection['status']" :label="($health['lastRun']['status'] ?? null) === 'failed' ? 'Ostatnia synchronizacja: błąd' : null" />
          @else
            <x-pp.status-badge status="other" label="Nie połączono" />
          @endif
        </li>
        <li class="flex items-start justify-between gap-3 px-5 py-3">
          <div class="min-w-0">
            <a href="{{ AppUrl::project($project->id, 'imports') }}" class="font-medium text-ink hover:text-mint">Importy raportów</a>
            <p class="pp-num truncate text-caption text-ink-mute">
              {{ $lastImport ? 'Ostatni: ' . $lastImport['original_filename'] . ' · ' . Format::datetime($lastImport['completed_at'] ?? $lastImport['created_at']) : 'Brak zaimportowanych raportów.' }}
            </p>
          </div>
          @if ($lastImport)<x-pp.status-badge status="completed" />@endif
        </li>
        <li class="flex items-start justify-between gap-3 px-5 py-3">
          <div class="min-w-0">
            <a href="{{ AppUrl::project($project->id, 'audits') }}" class="font-medium text-ink hover:text-mint">Audyt i jakość danych</a>
            <p class="pp-num text-caption text-ink-mute">
              @if ($health['audit'])
                Ostatni audyt: {{ Format::datetime($health['audit']['created_at']) }} · krytycznych: {{ $health['auditCritical'] }} · problemów z danymi: {{ count($health['dataQuality']) }}
              @else
                Audyt nie był jeszcze wykonywany.
              @endif
            </p>
          </div>
          @if ($health['audit'])
            <x-pp.status-badge :status="$health['auditCritical'] > 0 ? 'critical' : 'reviewed'" :label="$health['auditCritical'] > 0 ? 'Problemy krytyczne' : 'Bez krytycznych'" />
          @endif
        </li>
      </ul>

      {{-- Proste akcje --}}
      <div class="flex flex-wrap gap-2 border-t border-line px-5 py-4">
        @if ($canRun && $hasAnyData && $analysisPeriod)
          @include('app.partials.analysis.run-form', ['period' => $analysisPeriod, 'return' => '', 'size' => 'sm', 'label' => 'Analizuj ten zakres'])
        @endif
        <x-pp.button :href="AppUrl::project($project->id, 'imports')" icon="import" variant="secondary" size="sm">Importuj raport</x-pp.button>
        <x-pp.button :href="AppUrl::project($project->id, 'campaigns')" icon="campaigns" variant="secondary" size="sm">Kampanie</x-pp.button>
        <x-pp.button :href="AppUrl::project($project->id, 'action-plan')" icon="list" variant="secondary" size="sm">Plan działania</x-pp.button>
      </div>
    </section>
  </div>

  {{-- Historia ostatnich zdarzeń --}}
  <section class="pp-card min-w-0" aria-labelledby="pp-activity">
    <div class="pp-card-header">
      <h2 id="pp-activity" class="pp-card-title">Ostatnie zdarzenia</h2>
    </div>
    @if ($activity === [])
      <p class="px-5 py-4 text-sm text-ink-soft">Brak zdarzeń – tu pojawią się importy, synchronizacje, analizy, alerty i zmiany na koncie.</p>
    @else
      <ul class="divide-y divide-line">
        @foreach ($activity as $event)
          <li>
            <a href="{{ AppUrl::project($project->id, $event['path'], $event['query']) }}{{ $event['anchor'] ? '#' . $event['anchor'] : '' }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 hover:bg-ink/[0.03]">
              <span class="pp-num w-32 shrink-0 text-caption text-ink-mute">{{ Format::datetime($event['at']) }}</span>
              <span class="min-w-0 flex-1 text-sm"><span class="text-ink-mute">{{ $event['kind'] }}:</span> <span class="font-medium text-ink">{{ $event['title'] }}</span></span>
              @if ($event['status'])
                <x-pp.status-badge :status="$event['status']" :label="$event['status_label'] ?? ($event['status'] === 'completed' && $event['kind'] !== 'Import' ? 'Zakończono' : null)" />
              @endif
            </a>
          </li>
        @endforeach
      </ul>
    @endif
  </section>

  {{-- Szczegóły eksperckie: pełne tabele i wykresy dotychczasowego Przeglądu --}}
  @if ($hasAnyData)
    <details class="pp-card group" @if (request()->query('details') === 'expert') open @endif>
      <summary class="pp-card-header cursor-pointer list-none">
        <span class="pp-card-title">Szczegóły eksperckie</span>
        <span class="text-meta text-ink-mute">Wykresy dzienne, kampanie, wyszukiwane hasła, konwersje, wyniki audytu</span>
      </summary>

      <div class="space-y-6 p-5">
        <dl class="pp-card grid grid-cols-2 gap-px overflow-hidden bg-line sm:grid-cols-4 [&>div]:bg-surface">
          <x-pp.stat-card label="Kliknięcia" :value="Format::number($kpis['clicks'])" data-kpi="clicks" />
          <x-pp.stat-card label="Wyświetlenia" :value="Format::number($kpis['impressions'])" data-kpi="impressions" />
          <x-pp.stat-card label="Średni CPC" :value="Format::money($kpis['cpc'], $currency)" data-kpi="cpc" />
          <x-pp.stat-card label="Budżet miesięczny" :value="Format::money($kpis['monthly_budget'], $currency, 0)" data-kpi="monthly_budget" />
        </dl>

        {{-- Wykresy tylko z danych dziennych – z raportów okresowych pokazujemy wyłącznie sumy --}}
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

        @include('app.partials.dashboard-campaigns')
        @include('app.partials.dashboard-search-terms')
        @include('app.partials.dashboard-conversions')

        @if ($health['auditTop'] !== [])
          <section class="pp-card min-w-0" aria-labelledby="pp-audit-top">
            <div class="pp-card-header">
              <h2 id="pp-audit-top" class="pp-card-title">Najważniejsze wyniki audytu</h2>
              <a href="{{ AppUrl::project($project->id, 'audits') }}" class="text-sm font-semibold text-mint hover:underline">Pełny audyt</a>
            </div>
            <ul class="divide-y divide-line">
              @foreach ($health['auditTop'] as $finding)
                <li>
                  <a href="{{ AppUrl::project($project->id, 'audits/' . $finding['audit_run_id']) }}#finding-{{ $finding['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-ink/[0.03]">
                    <x-pp.status-badge :status="$finding['severity']" />
                    <span class="min-w-0 flex-1 text-sm font-medium text-ink">{{ $finding['title'] }}</span>
                  </a>
                </li>
              @endforeach
            </ul>
          </section>
        @endif
      </div>
    </details>
  @endif
@endsection
