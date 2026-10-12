@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Audit\AuditScore;
  use App\PennyPincher\Domain\Leaks\Leak;
  use App\PennyPincher\Repositories\AnalysisRepository;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $summary = $run['summary'] ?? [];
  $filtered = array_filter($filters) !== [];
  $active = array_filter($all, fn ($l) => $l['status'] !== 'dismissed');
  $triggers = ['manual' => 'ręcznie', 'sync' => 'po synchronizacji', 'cli' => 'z konsoli', 'dataforseo' => 'po DataForSEO'];
  $statusActions = ['new' => 'Przywróć jako nowy', 'reviewed' => 'Oznacz jako przejrzany', 'dismissed' => 'Pomiń'];
@endphp

@section('content')
  @if (! $run)
    <section class="pp-card">
      <x-pp.empty-state title="Brak analizy Budget Leaks dla okresu {{ $period->range->label() }}" icon="leak">
        Analiza wskazuje wyszukiwane hasła, słowa kluczowe, kampanie i problemy z pomiarem, które prawdopodobnie marnują budżet.
        Uwzględnia wielkość próby – brak konwersji przy kilku kliknięciach nie jest uznawany za stratę.
        Analiza korzysta tylko z danych zapisanych w Penny Pincher i nie zmienia niczego w Google Ads.
        @if ($canRun)
          <x-slot:actions>
            @include('app.partials.analysis.run-form', ['return' => 'budget-leaks'])
          </x-slot:actions>
        @endif
      </x-pp.empty-state>
    </section>
  @else
    @if (($summary['data_state'] ?? null) === 'no_activity')
      <x-pp.alert type="info" title="Brak aktywności reklamowej">{{ AuditScore::NO_ACTIVITY }}</x-pp.alert>
    @elseif (($summary['data_state'] ?? null) === 'no_data')
      <x-pp.alert type="warning" title="Brak danych">W wybranym okresie nie ma zapisanych danych kampanii. Zsynchronizuj konto Google Ads albo zaimportuj raporty.</x-pp.alert>
    @elseif (($summary['data_state'] ?? null) === 'partial')
      <x-pp.alert type="warning">Dane obejmują tylko część wybranego okresu – wyniki mogą być niepełne.</x-pp.alert>
    @endif

    <section class="pp-card" aria-label="Podsumowanie">
      <div class="pp-card-header">
        <h2 class="pp-card-title">Podsumowanie okresu</h2>
        <p class="pp-num text-[13px] text-ink-mute">Analiza: {{ Format::datetime($run['created_at']) }} · {{ $triggers[$run['trigger_type']] ?? $run['trigger_type'] }}</p>
      </div>
      <dl class="grid divide-y divide-line sm:grid-cols-2 sm:divide-y-0 xl:grid-cols-4 xl:divide-x">
        <x-pp.stat-card label="Wydatki w okresie" :value="Format::money($summary['spend_micros'] ?? null, $currency)" :hint="Format::number($summary['clicks'] ?? null) . ' kliknięć'" tone="spend" />
        <x-pp.stat-card label="Wykryte problemy" :value="count($active)" :hint="sprintf('%d wysoki · %d średni · %d niski priorytet', $summary['counts']['high'] ?? 0, $summary['counts']['medium'] ?? 0, $summary['counts']['low'] ?? 0)" />
        <x-pp.stat-card label="Potencjał oszczędności (SZACUNEK)" :value="$savings && $savings['total'] > 0 ? 'ok. ' . Format::money($savings['total'], $currency, 0) . ' / mies.' : 'Brak danych'" hint="Bez podwójnego liczenia nakładających się problemów; bez pominiętych" />
        <x-pp.stat-card label="Konwersje" :value="Format::conversions($summary['google_conversions'] ?? null)" :hint="($summary['goal_conversions'] ?? null) !== null ? 'W tym celów biznesowych: ' . Format::conversions($summary['goal_conversions']) : 'Cele biznesowe: brak klasyfikacji'" />
      </dl>
      <p class="border-t border-line px-5 py-3 text-[12.5px] leading-5 text-ink-mute">
        Kwoty oszczędności to <strong class="font-semibold text-ink-soft">szacunek</strong> miesięcznych wydatków, które można ograniczyć – nie gwarancja. Konwersje haseł i słów kluczowych to „Konwersje” z Google Ads (nie da się ich rozbić na działania);
        @if (! ($summary['campaign_level_conversions'] ?? false))
          konwersje celów biznesowych per kampania będą dostępne po ponownym pobraniu historii z Google Ads.
        @else
          dla kampanii używamy konwersji celów biznesowych z <a href="{{ AppUrl::project($project->id, 'conversions') }}" class="underline">klasyfikacji konwersji</a>.
        @endif
      </p>
    </section>

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
      <section class="pp-card min-w-0" aria-label="Lista problemów">
        <x-pp.filter-bar :action="AppUrl::project($project->id, 'budget-leaks')">
          <div class="w-full sm:w-56">
            <label for="f-campaign" class="pp-label">Kampania</label>
            <select id="f-campaign" name="campaign" class="pp-input pp-input-sm">
              <option value="">Wszystkie</option>
              @foreach ($campaignOptions as $id => $name)
                <option value="{{ $id }}" @selected($filters['campaign_id'] === (int) $id)>{{ $name }}</option>
              @endforeach
            </select>
          </div>
          <div class="w-full sm:w-44">
            <label for="f-category" class="pp-label">Kategoria</label>
            <select id="f-category" name="category" class="pp-input pp-input-sm">
              <option value="">Wszystkie</option>
              @foreach (Leak::CATEGORIES as $value => $label)
                <option value="{{ $value }}" @selected($filters['category'] === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="w-full sm:w-36">
            <label for="f-priority" class="pp-label">Priorytet</label>
            <select id="f-priority" name="priority" class="pp-input pp-input-sm">
              <option value="">Wszystkie</option>
              @foreach (Leak::PRIORITIES as $value => $label)
                <option value="{{ $value }}" @selected($filters['priority'] === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="w-full sm:w-36">
            <label for="f-status" class="pp-label">Status</label>
            <select id="f-status" name="status" class="pp-input pp-input-sm">
              <option value="">Wszystkie</option>
              @foreach (AnalysisRepository::LEAK_STATUSES as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
        </x-pp.filter-bar>

        @if ($leaks === [])
          <x-pp.empty-state :title="$filtered ? 'Żaden problem nie pasuje do filtrów' : 'Nie wykryto wycieków budżetu'" icon="check">
            @if ($filtered)
              Zmień lub wyczyść filtry.
            @else
              W tym okresie żadne hasło, słowo kluczowe ani kampania nie przekroczyły progów istotności (min. {{ Format::money(20_000_000, $currency, 0) }} lub koszt kilku konwersji i min. 10 kliknięć). Przy małej liczbie danych spróbuj dłuższego okresu.
            @endif
          </x-pp.empty-state>
        @else
          <ul class="divide-y divide-line">
            @foreach ($leaks as $leak)
              @php
                $group = $leak['ad_group_id'] ? ($adGroups[(int) $leak['ad_group_id']]['name'] ?? null) : null;
              @endphp
              <li id="leak-{{ $leak['id'] }}" class="scroll-mt-24" x-data="{ open: false }">
                <button type="button" class="flex w-full items-start gap-3 px-5 py-4 text-left hover:bg-ink/[0.02]" x-on:click="open = !open" x-bind:aria-expanded="open">
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                      <x-pp.status-badge :status="$leak['priority']" :label="'Priorytet: ' . Leak::PRIORITIES[$leak['priority']]" />
                      <span class="pp-badge pp-badge-neutral">{{ Leak::CATEGORIES[$leak['category']] ?? $leak['category'] }}</span>
                      @if ($leak['status'] !== 'new')<x-pp.status-badge :status="$leak['status']" />@endif
                      <span class="text-[12px] text-ink-mute">Pewność: {{ Statistics::CONFIDENCE_LABELS[$leak['confidence']] ?? $leak['confidence'] }}</span>
                    </div>
                    <h3 class="mt-1.5 font-semibold text-ink">{{ $leak['title'] }}</h3>
                    <p class="mt-0.5 truncate text-[13px] text-ink-mute">
                      {{ $leak['campaign_id'] ? ($campaignOptions[(int) $leak['campaign_id']] ?? 'Kampania') : 'Całe konto' }}@if ($group) · {{ $group }}@endif
                    </p>
                  </div>
                  <div class="shrink-0 text-right">
                    <p class="pp-num font-semibold text-copper">{{ Format::money($leak['cost_micros'], $currency) }}</p>
                    @if ($leak['savings_micros'] !== null && $leak['status'] !== 'dismissed')
                      <p class="pp-num text-[12px] text-ink-mute" title="Szacunek miesięcznej oszczędności">SZACUNEK: ok. {{ Format::money($leak['savings_micros'], $currency, 0) }}/mies.</p>
                    @endif
                  </div>
                  <x-pp.icon name="chevron-down" class="mt-0.5 text-ink-mute transition-transform" x-bind:class="open && 'rotate-180'" />
                </button>

                <div x-show="open" x-cloak class="space-y-4 px-5 pb-5 text-sm leading-6">
                  <dl class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-[var(--radius-control)] border border-line px-3 py-2.5 sm:grid-cols-3 lg:grid-cols-6">
                    <div><dt class="text-[12px] text-ink-mute">Okres</dt><dd class="pp-num text-ink">{{ Format::period($run['date_from'], $run['date_to']) }}</dd></div>
                    <div><dt class="text-[12px] text-ink-mute">Wydatki</dt><dd class="pp-num text-ink">{{ Format::money($leak['cost_micros'], $currency) }}</dd></div>
                    <div><dt class="text-[12px] text-ink-mute">Kliknięcia</dt><dd class="pp-num text-ink">{{ Format::number($leak['clicks']) }}</dd></div>
                    <div><dt class="text-[12px] text-ink-mute">Konwersje (Google)</dt><dd class="pp-num text-ink">{{ Format::conversions($leak['conversions']) }}</dd></div>
                    <div><dt class="text-[12px] text-ink-mute">Konwersje wartościowe</dt><dd class="pp-num text-ink">{{ $leak['valuable_conversions'] === null ? 'Brak danych' : Format::conversions($leak['valuable_conversions']) }}</dd></div>
                    <div><dt class="text-[12px] text-ink-mute">Priorytet (pkt)</dt><dd class="pp-num text-ink">{{ $leak['priority_score'] }}/100</dd></div>
                  </dl>
                  @if ($leak['subject'])
                    <p class="text-ink-soft"><span class="text-ink-mute">{{ $leak['entity_type'] === 'search_term' ? 'Wyszukiwane hasło' : ($leak['entity_type'] === 'keyword' ? 'Słowo kluczowe' : 'Dotyczy') }}:</span> <strong class="font-semibold text-ink">{{ $leak['subject'] }}</strong></p>
                  @endif
                  <div>
                    <h4 class="text-[13px] font-semibold text-ink">Uzasadnienie</h4>
                    <p class="mt-0.5 text-ink-soft">{{ $leak['reason'] }}</p>
                  </div>
                  @if ($leak['evidence']['pairs'])
                    <dl class="divide-y divide-line rounded-[var(--radius-control)] border border-line">
                      @foreach ($leak['evidence']['pairs'] as [$label, $value])
                        <div class="grid gap-1 px-3 py-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] sm:gap-4">
                          <dt class="text-ink-mute">{{ $label }}</dt>
                          <dd class="pp-num break-words text-ink">{{ $value }}</dd>
                        </div>
                      @endforeach
                    </dl>
                  @endif
                  <div class="rounded-[var(--radius-control)] bg-mint-soft px-3 py-2.5">
                    <h4 class="text-[13px] font-semibold text-mint-dark">Sugerowane działanie</h4>
                    <p class="mt-0.5 text-ink">{{ $leak['action'] }}</p>
                  </div>
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-[12.5px] text-ink-mute">{{ $leak['rule_id'] }} · zmiany wprowadzasz samodzielnie w Google Ads.</p>
                    @if ($canRun)
                      <form method="post" action="{{ AppUrl::project($project->id, 'budget-leaks/' . $leak['id'] . '/status') }}" class="flex flex-wrap gap-2">
                        <x-pp.nonce />
                        @foreach ($statusActions as $value => $label)
                          @if ($value !== $leak['status'])
                            <button type="submit" name="status" value="{{ $value }}" class="pp-btn pp-btn-sm {{ $value === 'dismissed' ? 'pp-btn-ghost' : 'pp-btn-secondary' }}">{{ $label }}</button>
                          @endif
                        @endforeach
                      </form>
                    @endif
                  </div>
                </div>
              </li>
            @endforeach
          </ul>
        @endif
      </section>

      <aside class="space-y-6">
        @if ($canRun)
          <section class="pp-card p-5">
            <h2 class="pp-card-title">Ponowna analiza</h2>
            <p class="mt-1 text-[13px] leading-5 text-ink-mute">Przelicza audyt, Budget Leaks, słowa kluczowe i rekomendacje dla okresu {{ $period->range->label() }}. Statusy problemów zostają zachowane.</p>
            <div class="mt-3">@include('app.partials.analysis.run-form', ['return' => 'budget-leaks', 'variant' => 'secondary', 'size' => 'sm', 'label' => 'Uruchom ponownie'])</div>
          </section>
        @endif

        <section class="pp-card" aria-labelledby="pp-leak-history">
          <div class="pp-card-header"><h2 id="pp-leak-history" class="pp-card-title">Historia analiz</h2></div>
          <ul class="divide-y divide-line">
            @foreach ($history as $item)
              @php $query = $item['range_preset'] === 'custom' ? ['period' => 'custom', 'from' => $item['date_from'], 'to' => $item['date_to']] : ['period' => $item['range_preset']]; @endphp
              <li>
                <a href="{{ AppUrl::project($project->id, 'budget-leaks', $query) }}" @class(['block px-5 py-3 hover:bg-ink/[0.03]', 'bg-mint-soft/60' => (int) $item['id'] === (int) $run['id']])>
                  <span class="pp-num block text-sm text-ink">Okres: {{ Format::period($item['date_from'], $item['date_to']) }}</span>
                  <span class="pp-num block text-[12px] text-ink-mute">{{ Format::datetime($item['created_at']) }} · {{ array_sum($item['summary']['counts'] ?? []) }} problemów · {{ $triggers[$item['trigger_type']] ?? $item['trigger_type'] }}</span>
                </a>
              </li>
            @endforeach
          </ul>
        </section>

        <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
          <h2 class="pp-card-title">Jak to działa</h2>
          <p class="mt-2">Hasło lub słowo bez konwersji jest problemem dopiero, gdy przy typowym współczynniku konwersji kampanii brak wyniku jest mało prawdopodobny przypadkiem. Pewność „Wysoka” oznacza, że szansa na zero konwersji przy tej liczbie kliknięć to poniżej 5%.</p>
          <p class="mt-2">Priorytet łączy wpływ na budżet, pewność statystyczną i znaczenie biznesowe – nie tylko wysokość wydatków.</p>
          <p class="mt-2">Szacunek oszczędności dla kampanii to maksimum (nie suma) z nakładających się problemów i nigdy nie przekracza jej miesięcznych wydatków.</p>
        </section>
      </aside>
    </div>
  @endif
@endsection
