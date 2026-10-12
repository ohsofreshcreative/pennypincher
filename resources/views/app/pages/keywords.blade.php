@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Analysis\Text;
  use App\PennyPincher\Domain\Keywords\OpportunityScore;
  use App\PennyPincher\Repositories\DataForSeoRepository;
  use App\PennyPincher\Services\DataForSeo\DataForSeoService;
  use App\PennyPincher\Support\AnalysisPeriodRequest;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $periodQuery = AnalysisPeriodRequest::query($period);
  $tabs = ['opportunities' => 'Nowe frazy', 'negatives' => 'Propozycje wykluczeń', 'dataforseo' => 'DataForSEO'];
  $sourceLabels = ['search_terms' => 'Wyszukiwane hasła', 'dataforseo' => 'DataForSEO'];
  $levels = ['account' => 'Lista wykluczeń konta', 'campaign' => 'Kampania', 'ad_group' => 'Grupa reklam'];
  $matchLabels = ['exact' => 'Ścisłe', 'phrase' => 'Do wyrażenia', 'broad' => 'Przybliżone'];
  $competition = ['LOW' => 'Niska', 'MEDIUM' => 'Średnia', 'HIGH' => 'Wysoka'];
  $dfs = $dataforseo;
  $usage = $dfs['usage'];
  $activeNegatives = array_filter($negatives, fn ($n) => ! (int) $n['already_excluded']);
@endphp

@section('content')
  <nav class="flex flex-wrap gap-1 border-b border-line" aria-label="Zakładki">
    @foreach ($tabs as $key => $label)
      <a href="{{ AppUrl::project($project->id, 'keywords', ['tab' => $key] + $periodQuery) }}" @class(['-mb-px border-b-2 px-4 py-2.5 text-sm font-medium', 'border-mint text-ink' => $tab === $key, 'border-transparent text-ink-mute hover:text-ink' => $tab !== $key]) @if ($tab === $key) aria-current="page" @endif>
        {{ $label }}
        @if ($key === 'opportunities' && $run)<span class="pp-num text-ink-mute">({{ (int) ($run['summary']['opportunities'] ?? 0) }})</span>@endif
        @if ($key === 'negatives' && $run)<span class="pp-num text-ink-mute">({{ count($activeNegatives) }})</span>@endif
      </a>
    @endforeach
  </nav>

  @if ($tab !== 'dataforseo' && ! $run)
    <section class="pp-card">
      <x-pp.empty-state title="Brak analizy słów kluczowych dla okresu {{ $period->range->label() }}" icon="keyword">
        Analiza szuka skutecznych zapytań, których nie ma jeszcze wśród słów kluczowych, ocenia je Opportunity Score (0–100) i proponuje wykluczenia dla nietrafnych zapytań.
        Frazy z DataForSEO pojawią się dopiero po ręcznie zatwierdzonym pobraniu (zakładka DataForSEO). Penny Pincher nie dodaje słów ani wykluczeń w Google Ads.
        @if ($canRun)
          <x-slot:actions>
            @include('app.partials.analysis.run-form', ['return' => 'keywords'])
          </x-slot:actions>
        @endif
      </x-pp.empty-state>
    </section>
  @elseif ($tab === 'opportunities')
    <section class="pp-card min-w-0" aria-label="Nowe frazy">
      <x-pp.filter-bar :action="AppUrl::project($project->id, 'keywords')">
        <input type="hidden" name="tab" value="opportunities">
        <div class="w-full sm:w-36">
          <label for="f-min" class="pp-label">Min. score</label>
          <select id="f-min" name="min_score" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach ([30, 50, 70] as $value)
              <option value="{{ $value }}" @selected($filters['min_score'] === $value)>≥ {{ $value }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-44">
          <label for="f-source" class="pp-label">Źródło</label>
          <select id="f-source" name="source" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach ($sourceLabels as $value => $label)
              <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-44">
          <label for="f-sort" class="pp-label">Sortowanie</label>
          <select id="f-sort" name="sort" class="pp-input pp-input-sm">
            <option value="score" @selected($filters['sort'] === 'score')>Opportunity Score</option>
            <option value="volume" @selected($filters['sort'] === 'volume')>Wolumen</option>
            <option value="cpc" @selected($filters['sort'] === 'cpc')>CPC (rosnąco)</option>
          </select>
        </div>
      </x-pp.filter-bar>

      @if ($opportunities === [])
        <x-pp.empty-state title="Brak nowych fraz" icon="keyword">
          W tym okresie nie znaleziono skutecznych zapytań spoza słów kluczowych (min. score 20). Uzupełnij kontekst biznesowy w ustawieniach lub pobierz pomysły z DataForSEO.
        </x-pp.empty-state>
      @else
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead>
              <tr>
                <th class="pp-col-sticky">Fraza</th>
                <th class="text-right">Score</th>
                <th>Intencja</th>
                <th class="text-right">CPC</th>
                <th class="text-right">Wolumen / mies.</th>
                <th>Konkurencja</th>
                <th>Sugerowane miejsce</th>
                <th>Źródło</th>
                <th>Aktualizacja</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($opportunities as $row)
                @php $group = $row['suggested_ad_group_id'] ? ($adGroups[(int) $row['suggested_ad_group_id']] ?? null) : null; @endphp
                <tr x-data="{ open: false }">
                  <td class="pp-col-sticky max-w-[300px] align-top">
                    <button type="button" class="text-left font-medium text-ink hover:text-mint" x-on:click="open = !open" x-bind:aria-expanded="open">{{ $row['phrase'] }}</button>
                    <div x-show="open" x-cloak class="mt-2 space-y-2 text-[12.5px] leading-5 text-ink-soft">
                      <p>{{ $row['reason'] }}</p>
                      <ul class="space-y-0.5">
                        @foreach ($row['score_breakdown'] as $key => $part)
                          <li class="pp-num">{{ OpportunityScore::LABELS[$key] ?? $key }}: {{ $part['value'] === null ? 'brak danych (0 pkt)' : rtrim(rtrim(number_format($part['points'], 1, ',', ''), '0'), ',') . ' / ' . $part['max'] }}</li>
                        @endforeach
                      </ul>
                    </div>
                  </td>
                  <td class="pp-col-num align-top">
                    <span class="font-semibold text-ink">{{ $row['score'] }}</span>
                    <span class="block text-[11.5px] text-ink-mute">pewność: {{ mb_strtolower(Statistics::CONFIDENCE_LABELS[$row['confidence']] ?? $row['confidence']) }}</span>
                  </td>
                  <td class="align-top whitespace-nowrap text-ink-soft">{{ Text::INTENT_LABELS[$row['intent']] ?? 'Brak danych' }}</td>
                  <td class="pp-col-num align-top">{{ $row['cpc_micros'] === null ? 'Brak danych' : Format::money((int) $row['cpc_micros'], $row['cpc_currency'] ?? $currency) }}</td>
                  <td class="pp-col-num align-top">{{ $row['search_volume'] === null ? 'Brak danych' : Format::number($row['search_volume']) }}</td>
                  <td class="align-top text-ink-soft">{{ $row['competition'] ? ($competition[$row['competition']] ?? $row['competition']) : 'Brak danych' }}</td>
                  <td class="max-w-[220px] align-top text-ink-soft">
                    @if ($group)
                      <span class="block truncate">{{ $group['name'] }}</span>
                      <span class="block truncate text-[12px] text-ink-mute">{{ $campaignOptions[$group['campaign_id']] ?? '—' }}</span>
                    @elseif ($row['suggested_campaign_id'])
                      <span class="block truncate">{{ $campaignOptions[(int) $row['suggested_campaign_id']] ?? '—' }}</span>
                    @else
                      <span class="text-ink-mute">Do przypisania</span>
                    @endif
                  </td>
                  <td class="align-top text-[12.5px] text-ink-soft">{{ implode(', ', array_map(fn ($s) => $sourceLabels[$s] ?? $s, explode(',', $row['sources']))) }}</td>
                  <td class="pp-num align-top text-[12.5px] text-ink-mute">{{ Format::datetime($row['data_updated_at'] ?? $row['created_at']) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
      <p class="border-t border-line px-5 py-3 text-[12.5px] leading-5 text-ink-mute">
        Opportunity Score to heurystyka porządkująca (dopasowanie do oferty 35, intencja 25, CPC 15, wolumen 10, brak pokrycia 15) – nie prognoza ROI. Składnik bez danych dostaje 0 punktów.
        CPC z DataForSEO jest w USD i nie jest porównywane z CPC konta w {{ $currency }}. Słowa dodajesz samodzielnie w Google Ads.
      </p>
    </section>
  @elseif ($tab === 'negatives')
    <section class="pp-card min-w-0" aria-label="Propozycje wykluczeń">
      @if ($negatives === [])
        <x-pp.empty-state title="Brak propozycji wykluczeń" icon="check">W tym okresie nie znaleziono nietrafnych zapytań o istotnym koszcie.</x-pp.empty-state>
      @else
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead>
              <tr>
                <th class="pp-col-sticky">Fraza wykluczająca</th>
                <th>Powód</th>
                <th class="text-right">Koszt</th>
                <th class="text-right">Kliknięcia</th>
                <th>Pewność</th>
                <th>Poziom</th>
                <th>Dopasowanie</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($negatives as $row)
                <tr x-data="{ open: false }" @class(['opacity-60' => (int) $row['already_excluded']])>
                  <td class="pp-col-sticky max-w-[260px] align-top">
                    <button type="button" class="text-left font-medium text-ink hover:text-mint" x-on:click="open = !open" x-bind:aria-expanded="open">{{ $row['phrase'] }}</button>
                    <ul x-show="open" x-cloak class="mt-2 space-y-0.5 text-[12px] text-ink-soft">
                      @foreach ($row['related_terms'] as $term)
                        <li><span class="text-ink">{{ $term['term'] }}</span> · <span class="pp-num">{{ Format::money((int) $term['cost_micros'], $currency) }}</span> · {{ $term['campaign'] }}</li>
                      @endforeach
                    </ul>
                  </td>
                  <td class="max-w-[320px] align-top text-[13px] text-ink-soft">{{ $row['reason'] }}</td>
                  <td class="pp-col-num align-top">{{ Format::money($row['cost_micros'] === null ? null : (int) $row['cost_micros'], $currency) }}</td>
                  <td class="pp-col-num align-top">{{ Format::number($row['clicks']) }}</td>
                  <td class="align-top text-ink-soft">{{ Statistics::CONFIDENCE_LABELS[$row['confidence']] ?? $row['confidence'] }}</td>
                  <td class="align-top text-ink-soft">{{ $levels[$row['level']] ?? $row['level'] }}@if ($row['level'] === 'campaign' && $row['campaign_id'])<span class="block text-[12px] text-ink-mute">{{ $campaignOptions[(int) $row['campaign_id']] ?? '' }}</span>@endif</td>
                  <td class="align-top text-ink-soft">{{ $matchLabels[$row['match_type']] ?? $row['match_type'] }}</td>
                  <td class="align-top">
                    @if ((int) $row['already_excluded'])
                      <x-pp.status-badge status="reviewed" label="Już wykluczona" />
                    @else
                      <x-pp.status-badge status="new" label="Propozycja" />
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
      <p class="border-t border-line px-5 py-3 text-[12.5px] leading-5 text-ink-mute">
        Zapytania informacyjne mają niską pewność – część z nich może prowadzić do klientów. Sprawdź powiązane zapytania przed dodaniem wykluczenia.
        Penny Pincher nie dodaje wykluczeń automatycznie. Status „Już wykluczona” wynika z wykluczeń zsynchronizowanych z Google Ads.
      </p>
    </section>
  @else
    <div class="grid gap-6 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
      <section class="pp-card min-w-0" aria-labelledby="pp-dfs">
        <div class="pp-card-header">
          <h2 id="pp-dfs" class="pp-card-title">Pomysły słów kluczowych z DataForSEO</h2>
          <p class="text-[13px] text-ink-mute">{{ $dfs['ideas'] }} zapisanych fraz{{ $dfs['last_fetched'] ? ' · ostatnie pobranie ' . Format::datetime($dfs['last_fetched']) : '' }}</p>
        </div>
        <div class="space-y-4 p-5 text-sm leading-6">
          @if (! $dfs['configured'])
            <x-pp.alert type="info" title="DataForSEO nie jest skonfigurowane">
              Dodaj stałe <code>PP_DATAFORSEO_LOGIN</code> i <code>PP_DATAFORSEO_PASSWORD</code> w wp-config.php (poza repozytorium). Instrukcja: docs/DATAFORSEO.md. Bez konfiguracji analiza korzysta wyłącznie z danych Google Ads.
            </x-pp.alert>
          @elseif ($dfs['plan'])
            @php $plan = $dfs['plan']; @endphp
            <p class="text-ink-soft">Frazy źródłowe ({{ count($plan['seeds']) }} z max. 20) wybrane z usług priorytetowych i skutecznych zapytań w okresie {{ $period->range->label() }}:</p>
            @if ($plan['seeds'])
              <ul class="flex flex-wrap gap-1.5">
                @foreach ($plan['seeds'] as $seed)
                  <li class="pp-badge pp-badge-neutral" title="{{ $seed['source'] }}">{{ $seed['phrase'] }}</li>
                @endforeach
              </ul>
            @endif
            <dl class="grid gap-x-6 gap-y-1 rounded-[var(--radius-control)] border border-line px-3 py-2.5 text-[13px] sm:grid-cols-2">
              <div><dt class="inline text-ink-mute">Endpoint:</dt> <dd class="inline text-ink">Google Ads Keywords For Keywords (live)</dd></div>
              <div><dt class="inline text-ink-mute">Lokalizacja / język:</dt> <dd class="inline text-ink">{{ \App\PennyPincher\Domain\Projects\BusinessContext::LOCATIONS[$plan['location_code']] ?? $plan['location_code'] }} / {{ $plan['language_code'] }}</dd></div>
              <div><dt class="inline text-ink-mute">Szacowany koszt:</dt> <dd class="pp-num inline font-semibold text-ink">{{ $plan['cached'] ? '0,00 USD (wynik w pamięci podręcznej)' : 'ok. ' . DataForSeoService::usd($plan['estimated_cost']) . ' USD' }}</dd></div>
              <div><dt class="inline text-ink-mute">Rozliczenie:</dt> <dd class="inline text-ink">faktyczny koszt z odpowiedzi API</dd></div>
            </dl>

            @if ($plan['blocked'])
              <x-pp.alert type="warning">{{ $plan['blocked'] }}</x-pp.alert>
            @elseif ($canManage)
              <form method="post" action="{{ AppUrl::project($project->id, 'keywords/dataforseo') }}" class="space-y-3" data-busy>
                <x-pp.nonce />
                @include('app.partials.period-fields', ['period' => $period])
                <input type="hidden" name="estimate" value="{{ $plan['estimated_cost'] }}">
                <label class="flex items-start gap-2.5">
                  <input type="checkbox" name="consent" value="1" required class="mt-1 size-4 rounded border-line-strong accent-mint">
                  <span class="text-ink-soft">Zgadzam się na {{ $plan['cached'] ? 'użycie zapisanego wyniku (bez kosztu)' : 'płatne zapytanie do DataForSEO (ok. ' . DataForSeoService::usd($plan['estimated_cost']) . ' USD)' }}. Zadanie wykona proces w tle.</span>
                </label>
                <x-pp.button icon="keyword" data-busy-label="Zlecanie…">Pobierz pomysły z DataForSEO</x-pp.button>
              </form>
            @else
              <p class="text-[13px] text-ink-mute">Pobranie danych może zlecić administrator projektu.</p>
            @endif
          @endif

          @if ($dfs['tasks'])
            <div>
              <h3 class="text-[13px] font-semibold text-ink">Ostatnie zadania</h3>
              <ul class="mt-2 divide-y divide-line rounded-[var(--radius-control)] border border-line text-[13px]">
                @foreach ($dfs['tasks'] as $task)
                  <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                    <span class="pp-num text-ink-soft">#{{ $task['id'] }} · {{ Format::datetime($task['created_at']) }} · {{ count($task['payload'][0]['keywords'] ?? []) }} fraz źródłowych</span>
                    <span class="flex items-center gap-2">
                      <span class="pp-num text-ink-mute">{{ $task['cost'] !== null ? DataForSeoService::usd($task['cost']) . ' USD' : 'szac. ' . DataForSeoService::usd($task['estimated_cost']) . ' USD' }}{{ (int) $task['from_cache'] ? ' (cache)' : '' }}{{ $task['result_count'] !== null ? ' · ' . $task['result_count'] . ' wyników' : '' }}</span>
                      <x-pp.status-badge :status="$task['status']" :label="DataForSeoRepository::STATUSES[$task['status']] ?? $task['status']" />
                    </span>
                    @if ($task['last_error'])<span class="w-full text-[12px] text-danger">{{ $task['last_error'] }}</span>@endif
                  </li>
                @endforeach
              </ul>
            </div>
          @endif
        </div>
      </section>

      <aside class="space-y-6">
        <section class="pp-card p-5 text-sm">
          <h2 class="pp-card-title">Limit i wykorzystanie ({{ $usage['month'] }})</h2>
          <dl class="mt-3 space-y-1.5 text-[13px]">
            <div class="flex justify-between gap-3"><dt class="text-ink-mute">Wydano w projekcie</dt><dd class="pp-num text-ink">{{ DataForSeoService::usd($usage['project_spent']) }} USD</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-mute">Zarezerwowano (w kolejce)</dt><dd class="pp-num text-ink">{{ DataForSeoService::usd($usage['project_reserved']) }} USD</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-mute">Limit projektu</dt><dd class="pp-num text-ink">{{ $usage['project_limit'] === null ? 'nie ustawiono (zapytania zablokowane)' : DataForSeoService::usd($usage['project_limit']) . ' USD' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-ink-mute">Organizacja: wydano / limit</dt><dd class="pp-num text-ink">{{ DataForSeoService::usd($usage['organization_spent']) }} / {{ $usage['organization_limit'] === null ? 'bez limitu' : DataForSeoService::usd($usage['organization_limit']) . ' USD' }}</dd></div>
          </dl>
          @if ($canManage)
            <form method="post" action="{{ AppUrl::project($project->id, 'keywords/dataforseo/limit') }}" class="mt-4 flex flex-wrap items-end gap-2">
              <x-pp.nonce />
              <div class="w-32">
                <label for="dfs-limit" class="pp-label">Limit (USD / mies.)</label>
                <input id="dfs-limit" name="limit" inputmode="decimal" value="{{ $usage['project_limit'] === null ? '' : number_format($usage['project_limit'], 2, ',', '') }}" class="pp-input pp-input-sm">
              </div>
              <x-pp.button variant="secondary" size="sm">Zapisz limit</x-pp.button>
            </form>
            @if ($dfs['configured'])
              <form method="post" action="{{ AppUrl::project($project->id, 'keywords/dataforseo/test') }}" class="mt-3" data-busy>
                <x-pp.nonce />
                <x-pp.button variant="ghost" size="sm" data-busy-label="Sprawdzanie…">Test połączenia (bezpłatny)</x-pp.button>
              </form>
            @endif
          @endif
        </section>
        <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
          <h2 class="pp-card-title">Jak to działa</h2>
          <p class="mt-2">Wejście na stronę nie wysyła zapytań do DataForSEO. Płatne zapytanie powstaje tylko po Twojej zgodzie, w ramach miesięcznego limitu, i wykonuje je proces w tle. Ten sam zestaw fraz w ciągu 30 dni jest brany z pamięci podręcznej bez kosztu.</p>
          <p class="mt-2">Po pobraniu frazy są deduplikowane, porównywane z istniejącymi słowami i wykluczeniami, oceniane pod kątem oferty i intencji, a następnie trafiają do „Nowych fraz”.</p>
        </section>
      </aside>
    </div>
  @endif

  @if ($tab !== 'dataforseo' && $run && $canRun)
    <section class="pp-card flex flex-wrap items-center justify-between gap-4 p-5">
      <p class="text-[13px] text-ink-mute">Analiza z {{ Format::datetime($run['created_at']) }} dla okresu {{ Format::period($run['date_from'], $run['date_to']) }} · {{ (int) ($run['summary']['terms_analyzed'] ?? 0) }} przeanalizowanych zapytań · {{ (int) ($run['summary']['ideas_available'] ?? 0) }} fraz z DataForSEO.</p>
      @include('app.partials.analysis.run-form', ['return' => 'keywords', 'variant' => 'secondary', 'size' => 'sm', 'label' => 'Uruchom ponownie'])
    </section>
  @endif
@endsection
