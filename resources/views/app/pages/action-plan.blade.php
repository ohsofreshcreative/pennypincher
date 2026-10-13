@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\ActionPlan\ActionPlanBuilder;
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
  use App\PennyPincher\Repositories\ActionItemRepository;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $statusTones = ['todo' => 'new', 'in_progress' => 'running', 'blocked' => 'warning', 'done' => 'done', 'rejected' => 'rejected'];
  $back = http_build_query(array_filter(request()->query(), 'is_scalar'));
  $total = array_sum($counts);
  $byId = [];
  foreach ($all as $fp => $item) { $byId[$fp] = $item; }
  $blocks = [];
  foreach ($all as $fp => $item) { foreach ($item['depends_on'] as $dep) { $blocks[$dep][] = $item['title']; } }
  $eventLabels = ['created' => 'Utworzone', 'status' => 'Zmiana statusu', 'note' => 'Notatka', 'obsolete' => 'Nieaktualne', 'restored' => 'Ponownie aktualne', 'blocked' => 'Zablokowane (zależność)', 'unblocked' => 'Odblokowane'];
@endphp

@section('content')
  <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="max-w-3xl">
        <h2 class="pp-card-title">Plan działań</h2>
        <p class="mt-1">Penny Pincher łączy wyniki audytu, Budget Leaks, rekomendacji i Keyword Opportunities w listę działań w zalecanej kolejności. Podobne problemy są połączone w jedno działanie, a <strong class="text-ink">zależności</strong> pokazują, co zrobić najpierw – np. bez wiarygodnego pomiaru konwersji nie warto zwiększać budżetu na podstawie liczby konwersji Google Ads.</p>
        <p class="mt-1">Status „Wykonane” ustawiasz Ty – zniknięcie problemu w kolejnej analizie oznacza tylko, że działanie jest nieaktualne.</p>
      </div>
      @if ($canRun)
        <form method="post" action="{{ AppUrl::project($project->id, 'action-plan/generate') }}" data-busy>
          <x-pp.nonce />
          @include('app.partials.period-fields', ['period' => $period])
          <x-pp.button icon="refresh" data-busy-label="Analiza…">{{ $total ? 'Odśwież plan' : 'Wygeneruj plan' }} ({{ $period->range->label() }})</x-pp.button>
        </form>
      @endif
    </div>
  </section>

  @if ($total === 0 && $items === [])
    <section class="pp-card">
      <x-pp.empty-state title="Brak działań" icon="list">Wygeneruj plan dla wybranego okresu – uruchomi to analizę (audyt, Budget Leaks, słowa kluczowe, rekomendacje) na danych zapisanych w Penny Pincher.</x-pp.empty-state>
    </section>
  @else
    <section class="pp-card" aria-label="Statusy">
      <div class="grid grid-cols-2 divide-line sm:grid-cols-5 sm:divide-x">
        @foreach (ActionItemRepository::STATUSES as $value => $label)
          <a href="{{ AppUrl::project($project->id, 'action-plan', ['status' => $value]) }}" @class(['block hover:bg-ink/[0.03]', 'bg-mint-soft/60' => $filters['status'] === $value])>
            <dl><x-pp.stat-card :label="$label" :value="$counts[$value] ?? 0" /></dl>
          </a>
        @endforeach
      </div>
    </section>

    <section class="pp-card min-w-0" aria-label="Działania">
      <x-pp.filter-bar :action="AppUrl::project($project->id, 'action-plan')">
        <div class="w-full sm:w-40">
          <label for="f-status" class="pp-label">Status</label>
          <select id="f-status" name="status" class="pp-input pp-input-sm"><option value="">Wszystkie</option>@foreach (ActionItemRepository::STATUSES as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="w-full sm:w-52">
          <label for="f-category" class="pp-label">Obszar</label>
          <select id="f-category" name="category" class="pp-input pp-input-sm"><option value="">Wszystkie</option>@foreach (ActionPlanBuilder::CATEGORIES as $v => $l)<option value="{{ $v }}" @selected($filters['category'] === $v)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="w-full sm:w-32">
          <label for="f-priority" class="pp-label">Priorytet</label>
          <select id="f-priority" name="priority" class="pp-input pp-input-sm"><option value="">Wszystkie</option>@foreach (['high' => 'Wysoki', 'medium' => 'Średni', 'low' => 'Niski'] as $v => $l)<option value="{{ $v }}" @selected($filters['priority'] === $v)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="w-full sm:w-40">
          <label for="f-obsolete" class="pp-label">Aktualność</label>
          <select id="f-obsolete" name="obsolete" class="pp-input pp-input-sm"><option value="active" @selected($filters['obsolete'] === 'active')>Aktualne</option><option value="obsolete" @selected($filters['obsolete'] === 'obsolete')>Nieaktualne</option><option value="all" @selected($filters['obsolete'] === 'all')>Wszystkie</option></select>
        </div>
      </x-pp.filter-bar>

      @if ($items === [])
        <x-pp.empty-state title="Brak działań dla filtrów" icon="list">Zmień filtry.</x-pp.empty-state>
      @else
        <ol class="divide-y divide-line">
          @foreach ($items as $index => $item)
            @php
              $deps = array_values(array_filter(array_map(fn ($fp) => $byId[$fp] ?? null, $item['depends_on'])));
              $openDeps = array_filter($deps, fn ($d) => ! in_array($d['status'], ['done', 'rejected'], true) && ! $d['is_obsolete']);
            @endphp
            <li id="action-{{ $item['id'] }}" class="scroll-mt-24" x-data="{ open: {{ $open === (int) $item['id'] ? 'true' : 'false' }} }">
              <button type="button" class="flex w-full items-start gap-3 px-5 py-4 text-left hover:bg-ink/[0.02]" x-on:click="open = !open" x-bind:aria-expanded="open">
                <span class="pp-num grid size-7 shrink-0 place-items-center rounded-full bg-ink/5 text-[13px] font-semibold text-ink">{{ $index + 1 }}</span>
                <span class="min-w-0 flex-1">
                  <span class="flex flex-wrap items-center gap-2">
                    <x-pp.status-badge :status="$item['priority']" :label="'Priorytet: ' . ['high' => 'wysoki', 'medium' => 'średni', 'low' => 'niski'][$item['priority']]" />
                    <x-pp.status-badge :status="$statusTones[$item['status']] ?? 'info'" :label="ActionItemRepository::STATUSES[$item['status']]" />
                    @if ($item['is_obsolete'])<x-pp.status-badge status="obsolete" label="Nieaktualne" />@endif
                    <span class="text-[12px] text-ink-mute">{{ ActionPlanBuilder::CATEGORIES[$item['category']] ?? $item['category'] }} · pewność: {{ mb_strtolower(Statistics::CONFIDENCE_LABELS[$item['confidence']] ?? $item['confidence']) }} · nakład: {{ mb_strtolower(explode(' ', ActionPlanBuilder::EFFORT_LABELS[$item['effort']] ?? $item['effort'])[0]) }}</span>
                  </span>
                  <span class="mt-1.5 block font-semibold text-ink">{{ $item['title'] }}</span>
                  @if ($openDeps)
                    <span class="mt-0.5 block text-[12.5px] text-warn">Najpierw: {{ implode(', ', array_map(fn ($d) => $d['title'], $openDeps)) }}</span>
                  @elseif (! empty($blocks[$item['fingerprint']]))
                    <span class="mt-0.5 block text-[12.5px] text-mint-dark">Odblokowuje {{ count($blocks[$item['fingerprint']]) }} {{ Format::plural(count($blocks[$item['fingerprint']]), 'działanie', 'działania', 'działań') }}</span>
                  @endif
                </span>
                <x-pp.icon name="chevron-down" class="mt-0.5 text-ink-mute transition-transform" x-bind:class="open && 'rotate-180'" />
              </button>

              <div x-show="open" x-cloak class="space-y-4 px-5 pb-5 text-sm leading-6 sm:pl-15">
                <p class="text-ink-soft">{{ $item['description'] }}</p>
                @include('app.partials.changes.source-change', ['sourceId' => $item['id'], 'action' => AppUrl::project($project->id, 'action-plan/' . $item['id'] . '/prepare-change'), 'blocker' => array_key_exists((int) $item['id'], $blockers) ? $blockers[(int) $item['id']] : \App\PennyPincher\Services\Changes\ChangeLinkService::OUTSIDE_API])
                @if ($item['details']['action'] ?? null)
                  <div class="rounded-[var(--radius-control)] bg-mint-soft px-3 py-2.5"><h4 class="text-[13px] font-semibold text-mint-dark">Co zrobić</h4><p class="mt-0.5 text-ink">{{ $item['details']['action'] }}</p></div>
                @endif
                <dl class="grid gap-x-6 gap-y-1 text-[13px] sm:grid-cols-2">
                  <div><dt class="inline text-ink-mute">Sugerowany efekt:</dt> <dd class="inline text-ink">{{ $item['expected_effect'] }}</dd></div>
                  <div><dt class="inline text-ink-mute">Nakład pracy:</dt> <dd class="inline text-ink">{{ ActionPlanBuilder::EFFORT_LABELS[$item['effort']] ?? $item['effort'] }}</dd></div>
                  <div><dt class="inline text-ink-mute">Źródło:</dt> <dd class="inline text-ink">{{ implode(', ', array_map(fn ($s) => RecommendationGenerator::SOURCES[$s] ?? $s, $item['sources'])) ?: '—' }}</dd></div>
                  <div><dt class="inline text-ink-mute">Priorytet (pkt):</dt> <dd class="pp-num inline text-ink">{{ $item['priority_score'] }}/100</dd></div>
                  @if ($deps)<div class="sm:col-span-2"><dt class="inline text-ink-mute">Zależy od:</dt> <dd class="inline text-ink">{{ implode(', ', array_map(fn ($d) => $d['title'] . ' (' . mb_strtolower(ActionItemRepository::STATUSES[$d['status']]) . ')', $deps)) }}</dd></div>@endif
                </dl>
                @if ($item['rationale'])
                  <div><h4 class="text-[13px] font-semibold text-ink">Uzasadnienie</h4><p class="mt-0.5 whitespace-pre-line text-ink-soft">{{ $item['rationale'] }}</p></div>
                @endif
                @if ($item['details']['items'] ?? [])
                  <div>
                    <h4 class="text-[13px] font-semibold text-ink">Powiązane rekomendacje</h4>
                    <ul class="mt-1 space-y-2">
                      @foreach ($item['details']['items'] as $rec)
                        <li class="rounded-[var(--radius-control)] border border-line px-3 py-2">
                          <a href="{{ AppUrl::project($project->id, 'recommendations', ['open' => $rec['id'], 'obsolete' => 'all']) }}#rec-{{ $rec['id'] }}" class="font-medium text-ink hover:text-mint">{{ $rec['title'] }}</a>
                          <span class="text-[12px] text-ink-mute">· {{ RecommendationGenerator::STATUSES[$rec['status']] ?? $rec['status'] }}</span>
                          @if ($rec['evidence'])
                            <ul class="mt-1 text-[12.5px] text-ink-soft">@foreach ($rec['evidence'] as $pair)@if (is_array($pair) && count($pair) >= 2)<li>{{ $pair[0] }} – {{ $pair[1] }}</li>@endif @endforeach</ul>
                          @endif
                        </li>
                      @endforeach
                    </ul>
                  </div>
                @endif
                @if ($item['note'])<p class="rounded-[var(--radius-control)] border border-line bg-paper px-3 py-2 text-ink-soft"><span class="font-semibold text-ink">Notatka:</span> {{ $item['note'] }}</p>@endif

                @if ($canEdit)
                  <div class="grid gap-4 border-t border-line pt-4 lg:grid-cols-2">
                    <form method="post" action="{{ AppUrl::project($project->id, 'action-plan/' . $item['id'] . '/status') }}" class="space-y-2">
                      <x-pp.nonce /><input type="hidden" name="back" value="{{ $back }}">
                      <p class="text-[13px] font-semibold text-ink">Zmień status</p>
                      <div class="flex flex-wrap gap-2">
                        @foreach (ActionItemRepository::STATUSES as $value => $label)
                          @if ($value !== $item['status'])
                            <button type="submit" name="status" value="{{ $value }}" class="pp-btn pp-btn-sm {{ $value === 'rejected' ? 'pp-btn-ghost' : 'pp-btn-secondary' }}">{{ $label }}</button>
                          @endif
                        @endforeach
                      </div>
                      @if ($item['recommendation_ids'])
                        <label class="flex items-start gap-2 text-[12.5px] text-ink-soft"><input type="checkbox" name="also_recommendations" value="1" class="mt-1 accent-mint"> Przy „Wykonane” / „Odrzucone” zmień też status {{ count($item['recommendation_ids']) }} {{ Format::plural(count($item['recommendation_ids']), 'powiązanej rekomendacji', 'powiązanych rekomendacji', 'powiązanych rekomendacji') }}</label>
                      @endif
                    </form>
                    <form method="post" action="{{ AppUrl::project($project->id, 'action-plan/' . $item['id'] . '/note') }}" class="space-y-2">
                      <x-pp.nonce /><input type="hidden" name="back" value="{{ $back }}">
                      <label for="note-{{ $item['id'] }}" class="text-[13px] font-semibold text-ink">Notatka</label>
                      <textarea id="note-{{ $item['id'] }}" name="note" rows="2" maxlength="2000" class="pp-input">{{ $item['note'] }}</textarea>
                      <x-pp.button variant="secondary" size="sm">Zapisz notatkę</x-pp.button>
                    </form>
                  </div>
                @endif

                @if ($open === (int) $item['id'] && $events)
                  <div>
                    <h4 class="text-[13px] font-semibold text-ink">Historia</h4>
                    <ol class="mt-2 space-y-1 border-l border-line pl-4 text-[12.5px] text-ink-soft">
                      @foreach ($events as $event)
                        <li><span class="pp-num text-ink-mute">{{ Format::datetime($event['created_at']) }}</span> · {{ $eventLabels[$event['event']] ?? $event['event'] }}@if ($event['from_status'] || $event['to_status']): {{ ActionItemRepository::STATUSES[$event['from_status']] ?? '—' }} → {{ ActionItemRepository::STATUSES[$event['to_status']] ?? '—' }}@endif @if ($event['user_name'])· {{ $event['user_name'] }}@endif @if ($event['note'])– {{ $event['note'] }}@endif</li>
                      @endforeach
                    </ol>
                  </div>
                @elseif ($open !== (int) $item['id'])
                  <a href="{{ AppUrl::project($project->id, 'action-plan', array_filter(request()->query(), 'is_scalar') + ['open' => $item['id']]) }}#action-{{ $item['id'] }}" class="text-[12.5px] text-mint underline">Pokaż historię</a>
                @endif
              </div>
            </li>
          @endforeach
        </ol>
      @endif
    </section>
  @endif
@endsection
