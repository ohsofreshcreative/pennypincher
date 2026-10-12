@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
  use App\PennyPincher\Repositories\RecommendationRepository;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $back = http_build_query(array_filter(request()->query(), 'is_scalar'));
  $filtered = array_filter(array_diff_key($filters, ['obsolete' => 1, 'sort' => 1, 'from' => 1, 'to' => 1])) !== [] || $filters['obsolete'] !== 'active' || $scope === 'period';
  $total = array_sum($counts);
  $sorts = ['priority' => 'Priorytet', 'impact' => 'Szacowany wpływ', 'newest' => 'Najnowsze', 'updated' => 'Ostatnio zmienione'];
  $events = collect($events);
  $eventLabels = ['created' => 'Utworzona przez analizę', 'status' => 'Zmiana statusu', 'note' => 'Notatka', 'obsolete' => 'Oznaczona jako nieaktualna', 'restored' => 'Przywrócona'];
@endphp

@section('content')
  @if ($total === 0 && ! $filtered && $recommendations->total === 0)
    <section class="pp-card">
      <x-pp.empty-state title="Brak rekomendacji" icon="idea">
        Rekomendacje powstają z audytu, Budget Leaks i analizy słów kluczowych. Podobne problemy są łączone w jedną rekomendację, a ta sama sprawa wykryta przez kilka modułów nie jest dublowana.
        Uruchom analizę dla wybranego okresu ({{ $period->range->label() }}).
        @if ($canEdit)
          <x-slot:actions>
            @include('app.partials.analysis.run-form', ['return' => 'recommendations'])
          </x-slot:actions>
        @endif
      </x-pp.empty-state>
    </section>
  @else
    <section class="pp-card" aria-label="Statusy">
      <div class="grid grid-cols-2 divide-line sm:grid-cols-5 sm:divide-x">
        @foreach (RecommendationGenerator::STATUSES as $value => $label)
          <a href="{{ AppUrl::project($project->id, 'recommendations', ['status' => $value]) }}" @class(['block hover:bg-ink/[0.03]', 'bg-mint-soft/60' => $filters['status'] === $value])>
            <dl><x-pp.stat-card :label="$label" :value="$counts[$value] ?? 0" /></dl>
          </a>
        @endforeach
      </div>
    </section>

    <section class="pp-card min-w-0" aria-label="Lista rekomendacji">
      <x-pp.filter-bar :action="AppUrl::project($project->id, 'recommendations')">
        <div class="w-full sm:w-40">
          <label for="f-status" class="pp-label">Status</label>
          <select id="f-status" name="status" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach (RecommendationGenerator::STATUSES as $value => $label)
              <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-32">
          <label for="f-priority" class="pp-label">Priorytet</label>
          <select id="f-priority" name="priority" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach (['high' => 'Wysoki', 'medium' => 'Średni', 'low' => 'Niski'] as $value => $label)
              <option value="{{ $value }}" @selected($filters['priority'] === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-32">
          <label for="f-confidence" class="pp-label">Pewność</label>
          <select id="f-confidence" name="confidence" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach (Statistics::CONFIDENCE_LABELS as $value => $label)
              <option value="{{ $value }}" @selected($filters['confidence'] === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-44">
          <label for="f-source" class="pp-label">Źródło</label>
          <select id="f-source" name="source" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach (RecommendationGenerator::SOURCES as $value => $label)
              <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-52">
          <label for="f-campaign" class="pp-label">Kampania</label>
          <select id="f-campaign" name="campaign" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach ($campaignOptions as $id => $name)
              <option value="{{ $id }}" @selected($filters['campaign_id'] === (int) $id)>{{ $name }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-40">
          <label for="f-obsolete" class="pp-label">Aktualność</label>
          <select id="f-obsolete" name="obsolete" class="pp-input pp-input-sm">
            <option value="active" @selected($filters['obsolete'] === 'active')>Aktualne</option>
            <option value="obsolete" @selected($filters['obsolete'] === 'obsolete')>Nieaktualne</option>
            <option value="all" @selected($filters['obsolete'] === 'all')>Wszystkie</option>
          </select>
        </div>
        <div class="w-full sm:w-44">
          <label for="f-scope" class="pp-label">Okres</label>
          <select id="f-scope" name="scope" class="pp-input pp-input-sm">
            <option value="all" @selected($scope === 'all')>Wszystkie analizy</option>
            <option value="period" @selected($scope === 'period')>Tylko {{ $period->range->label() }}</option>
          </select>
        </div>
        <div class="w-full sm:w-44">
          <label for="f-sort" class="pp-label">Sortowanie</label>
          <select id="f-sort" name="sort" class="pp-input pp-input-sm">
            @foreach ($sorts as $value => $label)
              <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </x-pp.filter-bar>

      @if ($recommendations->items === [])
        <x-pp.empty-state title="Żadna rekomendacja nie pasuje do filtrów" icon="idea">Zmień lub wyczyść filtry.</x-pp.empty-state>
      @else
        <ul class="divide-y divide-line">
          @foreach ($recommendations->items as $rec)
            <li id="rec-{{ $rec['id'] }}" class="scroll-mt-24" x-data="{ open: {{ $open === (int) $rec['id'] ? 'true' : 'false' }} }">
              <button type="button" class="flex w-full items-start gap-3 px-5 py-4 text-left hover:bg-ink/[0.02]" x-on:click="open = !open" x-bind:aria-expanded="open">
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <x-pp.status-badge :status="$rec['priority']" :label="'Priorytet: ' . ['high' => 'wysoki', 'medium' => 'średni', 'low' => 'niski'][$rec['priority']]" />
                    <x-pp.status-badge :status="$rec['status']" :label="RecommendationGenerator::STATUSES[$rec['status']] ?? $rec['status']" />
                    @if ($rec['is_obsolete'])<x-pp.status-badge status="obsolete" />@endif
                    <span class="text-[12px] text-ink-mute">{{ implode(' + ', array_map(fn ($s) => RecommendationGenerator::SOURCES[$s] ?? $s, $rec['sources'])) }} · pewność: {{ mb_strtolower(Statistics::CONFIDENCE_LABELS[$rec['confidence']] ?? $rec['confidence']) }}</span>
                  </div>
                  <h3 class="mt-1.5 font-semibold text-ink">{{ $rec['title'] }}</h3>
                  <p class="mt-0.5 truncate text-[13px] text-ink-mute">
                    {{ $rec['campaign_id'] ? ($campaignOptions[(int) $rec['campaign_id']] ?? 'Kampania') : 'Całe konto' }}
                    · okres {{ Format::period($rec['date_from'], $rec['date_to']) }}
                  </p>
                </div>
                <div class="hidden shrink-0 text-right sm:block">
                  <p class="pp-num text-[13px] text-ink-soft">{{ $rec['impact_label'] ?? 'Wpływ: brak danych' }}</p>
                  <p class="pp-num text-[12px] text-ink-mute">{{ $rec['priority_score'] }}/100 pkt</p>
                </div>
                <x-pp.icon name="chevron-down" class="mt-0.5 text-ink-mute transition-transform" x-bind:class="open && 'rotate-180'" />
              </button>

              <div x-show="open" x-cloak class="space-y-4 px-5 pb-5 text-sm leading-6">
                <p class="text-ink-soft">{{ $rec['description'] }}</p>
                @if ($rec['rationale'])
                  <div>
                    <h4 class="text-[13px] font-semibold text-ink">Uzasadnienie (dane)</h4>
                    <p class="mt-0.5 whitespace-pre-line text-ink-soft">{{ $rec['rationale'] }}</p>
                  </div>
                @endif
                @if ($rec['evidence'])
                  <dl class="divide-y divide-line rounded-[var(--radius-control)] border border-line">
                    @foreach ($rec['evidence'] as $pair)
                      @if (is_array($pair) && count($pair) >= 2)
                        <div class="grid gap-1 px-3 py-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] sm:gap-4">
                          <dt class="break-words text-ink-mute">{{ $pair[0] }}</dt>
                          <dd class="pp-num break-words text-ink">{{ $pair[1] }}</dd>
                        </div>
                      @endif
                    @endforeach
                  </dl>
                @endif
                <div class="rounded-[var(--radius-control)] bg-mint-soft px-3 py-2.5">
                  <h4 class="text-[13px] font-semibold text-mint-dark">Działanie</h4>
                  <p class="mt-0.5 text-ink">{{ $rec['action'] }}</p>
                </div>
                @include('app.partials.changes.source-change', ['sourceId' => $rec['id'], 'action' => AppUrl::project($project->id, 'recommendations/' . $rec['id'] . '/prepare-change'), 'blocker' => \App\PennyPincher\Services\Changes\ChangeProposalService::deployable($rec) !== null ? 'Wymaga działań poza Google Ads API (np. na stronie) – bez operacji do wdrożenia.' : null])
                <dl class="grid gap-x-6 gap-y-1 text-[12.5px] text-ink-mute sm:grid-cols-2 lg:grid-cols-4">
                  <div><dt class="inline">Szacowany wpływ:</dt> <dd class="inline text-ink-soft">{{ $rec['impact_label'] ?? 'brak danych' }}</dd></div>
                  <div><dt class="inline">Pierwsze wykrycie:</dt> <dd class="pp-num inline text-ink-soft">{{ Format::datetime($rec['first_seen_at']) }}</dd></div>
                  <div><dt class="inline">Ostatnia analiza:</dt> <dd class="pp-num inline text-ink-soft">{{ Format::datetime($rec['last_seen_at']) }}</dd></div>
                  <div><dt class="inline">Aktualność:</dt> <dd class="inline text-ink-soft">{{ $rec['is_obsolete'] ? ($rec['obsolete_reason'] ?? 'nieaktualna') : 'aktualna' }}</dd></div>
                </dl>

                @if ($rec['note'])
                  <p class="rounded-[var(--radius-control)] border border-line bg-paper px-3 py-2 text-ink-soft"><span class="font-semibold text-ink">Notatka:</span> {{ $rec['note'] }}</p>
                @endif

                @if ($canEdit)
                  <div class="grid gap-4 border-t border-line pt-4 lg:grid-cols-2">
                    <form method="post" action="{{ AppUrl::project($project->id, 'recommendations/' . $rec['id'] . '/status') }}" class="space-y-2">
                      <x-pp.nonce />
                      <input type="hidden" name="back" value="{{ $back }}">
                      <p class="text-[13px] font-semibold text-ink">Zmień status</p>
                      <div class="flex flex-wrap gap-2">
                        @foreach (RecommendationGenerator::STATUSES as $value => $label)
                          @if ($value !== $rec['status'])
                            <button type="submit" name="status" value="{{ $value }}" class="pp-btn pp-btn-sm {{ $value === 'rejected' ? 'pp-btn-ghost' : 'pp-btn-secondary' }}">{{ $label }}</button>
                          @endif
                        @endforeach
                      </div>
                      <p class="text-[12px] text-ink-mute">„Wykonana” to Twoje potwierdzenie, że zmianę wprowadzono. Status nie zmienia się automatycznie po wdrożeniu przez Change Center – skuteczność oceniasz Ty.</p>
                    </form>
                    <form method="post" action="{{ AppUrl::project($project->id, 'recommendations/' . $rec['id'] . '/note') }}" class="space-y-2">
                      <x-pp.nonce />
                      <input type="hidden" name="back" value="{{ $back }}">
                      <label for="note-{{ $rec['id'] }}" class="text-[13px] font-semibold text-ink">Notatka</label>
                      <textarea id="note-{{ $rec['id'] }}" name="note" rows="2" maxlength="2000" class="pp-input">{{ $rec['note'] }}</textarea>
                      <div class="flex flex-wrap gap-2">
                        <x-pp.button variant="secondary" size="sm">Zapisz notatkę</x-pp.button>
                      </div>
                    </form>
                  </div>
                  <form method="post" action="{{ AppUrl::project($project->id, 'recommendations/' . $rec['id'] . '/obsolete') }}">
                    <x-pp.nonce />
                    <input type="hidden" name="back" value="{{ $back }}">
                    <input type="hidden" name="obsolete" value="{{ $rec['is_obsolete'] ? '0' : '1' }}">
                    <button type="submit" class="pp-btn pp-btn-ghost pp-btn-sm">{{ $rec['is_obsolete'] ? 'Przywróć jako aktualną' : 'Oznacz jako nieaktualną' }}</button>
                  </form>
                @endif

                @if ($open === (int) $rec['id'] && $events->isNotEmpty())
                  <div>
                    <h4 class="text-[13px] font-semibold text-ink">Historia</h4>
                    <ol class="mt-2 space-y-1.5 border-l border-line pl-4 text-[12.5px] text-ink-soft">
                      @foreach ($events as $event)
                        <li>
                          <span class="pp-num text-ink-mute">{{ Format::datetime($event['created_at']) }}</span> ·
                          {{ $eventLabels[$event['event']] ?? $event['event'] }}@if ($event['from_status'] || $event['to_status']): {{ RecommendationGenerator::STATUSES[$event['from_status']] ?? '—' }} → {{ RecommendationGenerator::STATUSES[$event['to_status']] ?? '—' }}@endif
                          @if ($event['user_name']) · {{ $event['user_name'] }}@endif
                          @if ($event['note'] && $event['event'] !== 'note') – {{ $event['note'] }}@endif
                        </li>
                      @endforeach
                    </ol>
                  </div>
                @elseif ($open !== (int) $rec['id'])
                  <a href="{{ AppUrl::project($project->id, 'recommendations', array_filter(request()->query(), 'is_scalar') + ['open' => $rec['id']]) }}#rec-{{ $rec['id'] }}" class="text-[12.5px] text-mint underline">Pokaż historię zmian</a>
                @endif
              </div>
            </li>
          @endforeach
        </ul>
        <x-pp.pagination :paginator="$recommendations" label="rekomendacji" />
      @endif
    </section>

    @if ($canEdit)
      <section class="pp-card flex flex-wrap items-center justify-between gap-4 p-5">
        <p class="max-w-2xl text-[13px] leading-5 text-ink-mute">Ponowna analiza aktualizuje istniejące rekomendacje (bez dublowania) i zachowuje statusy oraz notatki. Rekomendacje, których problem zniknął w analizie tego samego okresu, są oznaczane jako nieaktualne.</p>
        @include('app.partials.analysis.run-form', ['return' => 'recommendations', 'variant' => 'secondary', 'size' => 'sm', 'label' => 'Analizuj ' . $period->range->label()])
      </section>
    @endif
  @endif
@endsection
