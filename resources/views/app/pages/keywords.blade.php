@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Analysis\Text;
  use App\PennyPincher\Domain\Keywords\KeywordStatus;
  use App\PennyPincher\Domain\Keywords\OpportunityScoreV2;
  use App\PennyPincher\Domain\Keywords\OpportunityScore;
  use App\PennyPincher\Domain\Keywords\PhraseQuality;
  use App\PennyPincher\Infrastructure\DataForSeo\DataForSeoClient;
  use App\PennyPincher\Repositories\DataForSeoRepository;
  use App\PennyPincher\Services\DataForSeo\DataForSeoService;
  use App\PennyPincher\Support\AnalysisPeriodRequest;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $periodQuery = AnalysisPeriodRequest::query($period);
  $tabs = ['opportunities' => 'Nowe frazy', 'clusters' => 'Klastry', 'negatives' => 'Propozycje wykluczeń', 'dataforseo' => 'DataForSEO'];
  $sourceLabels = ['search_terms' => 'Wyszukiwane hasła', 'dataforseo' => 'DataForSEO'];
  $levels = ['account' => 'Lista wykluczeń konta', 'campaign' => 'Kampania', 'ad_group' => 'Grupa reklam'];
  $matchLabels = ['exact' => 'Ścisłe', 'phrase' => 'Do wyrażenia', 'broad' => 'Przybliżone'];
  $competition = ['LOW' => 'Niska', 'MEDIUM' => 'Średnia', 'HIGH' => 'Wysoka'];
  $statusTones = ['new' => 'info', 'to_review' => 'to_review', 'accepted' => 'reviewed', 'rejected' => 'dismissed', 'in_plan' => 'accepted', 'used' => 'done'];
  $dfs = $dataforseo;
  $usage = $dfs['usage'];
  $activeNegatives = array_filter($negatives, fn ($n) => ! (int) $n['already_excluded']);
  $back = http_build_query(array_filter(request()->query(), 'is_scalar'));
  $volumeText = fn ($v) => $v === null ? 'Brak danych' : Format::number($v);
@endphp

@section('content')
  <nav class="-mx-1 flex gap-1 overflow-x-auto border-b border-line px-1" aria-label="Zakładki">
    @foreach ($tabs as $key => $label)
      <a href="{{ AppUrl::project($project->id, 'keywords', ['tab' => $key] + $periodQuery) }}" @class(['-mb-px shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium', 'border-mint text-ink' => $tab === $key, 'border-transparent text-ink-mute hover:text-ink' => $tab !== $key]) @if ($tab === $key) aria-current="page" @endif>
        {{ $label }}
        @if ($key === 'opportunities' && $run)<span class="pp-num text-ink-mute">({{ (int) ($run['summary']['opportunities'] ?? 0) }})</span>@endif
        @if ($key === 'clusters' && $run)<span class="pp-num text-ink-mute">({{ (int) ($run['summary']['clusters'] ?? 0) }})</span>@endif
      </a>
    @endforeach
  </nav>

  @if ($tab !== 'dataforseo' && ! $run)
    <section class="pp-card">
      <x-pp.empty-state title="Brak analizy słów kluczowych dla okresu {{ $period->range->label() }}" icon="keyword">
        Analiza szuka skutecznych zapytań, których nie ma jeszcze wśród słów kluczowych, grupuje je w klastry o podobnej intencji, ocenia (Opportunity Score i pewność) i proponuje wykluczenia.
        Frazy z DataForSEO pojawią się dopiero po ręcznie zatwierdzonym pobraniu (zakładka DataForSEO). Penny Pincher nie dodaje słów ani wykluczeń w Google Ads.
        @if ($canRun)
          <x-slot:actions>
            @include('app.partials.analysis.run-form', ['return' => 'keywords'])
          </x-slot:actions>
        @endif
      </x-pp.empty-state>
    </section>
  @elseif ($tab === 'opportunities')
    <details class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
      <summary class="cursor-pointer font-semibold text-ink">Jak czytać ocenę fraz?</summary>
      <p class="mt-2"><strong class="text-ink">Opportunity Score (0–100)</strong> mówi, jak atrakcyjna biznesowo jest fraza: dopasowanie do oferty, intencja zakupowa, wyniki w Google Ads, zgodność z grupą docelową, brak w obecnych kampaniach, koszt kliknięcia (liczy się tylko przy dobrej intencji), popularność i konkurencja. To heurystyka, nie prognoza zysku.</p>
      <p class="mt-1"><strong class="text-ink">Pewność</strong> mówi, na ile ocena opiera się na danych: brakujące dane (np. wolumen, CPC) dają 0 punktów i obniżają pewność – nie oznaczają, że popytu nie ma.</p>
      <p class="mt-1">Status frazy (Zaakceptowana, Odrzucona…) jest zapamiętywany i przetrwa ponowną analizę, synchronizację i pobranie DataForSEO. Kliknij frazę, aby zobaczyć uzasadnienie.</p>
    </details>

    <section class="pp-card min-w-0" aria-label="Nowe frazy">
      <x-pp.filter-bar :action="AppUrl::project($project->id, 'keywords')">
        <input type="hidden" name="tab" value="opportunities">
        <div class="w-full sm:w-48">
          <label for="f-q" class="pp-label">Fraza</label>
          <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="pp-input pp-input-sm" placeholder="Szukaj…">
        </div>
        <div class="w-full sm:w-40">
          <label for="f-status" class="pp-label">Status</label>
          <select id="f-status" name="status" class="pp-input pp-input-sm">
            <option value="">Bez odrzuconych</option>
            @foreach (KeywordStatus::LABELS as $value => $label)
              <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }} ({{ $value === 'new' ? 'bez decyzji' : ($statusCounts[$value] ?? 0) }})</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-52">
          <label for="f-cluster" class="pp-label">Klaster</label>
          <select id="f-cluster" name="cluster" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach ($clusterNames as $key => $name)
              <option value="{{ $key }}" @selected($filters['cluster'] === $key)>{{ $name }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-36">
          <label for="f-intent" class="pp-label">Intencja</label>
          <select id="f-intent" name="intent" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach (['commercial', 'unknown', 'informational'] as $intent)
              <option value="{{ $intent }}" @selected($filters['intent'] === $intent)>{{ Text::INTENT_LABELS[$intent] }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-28">
          <label for="f-min" class="pp-label">Min. score</label>
          <select id="f-min" name="min_score" class="pp-input pp-input-sm">
            <option value="">—</option>
            @foreach ([30, 50, 70] as $value)
              <option value="{{ $value }}" @selected($filters['min_score'] === $value)>≥ {{ $value }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-40">
          <label for="f-source" class="pp-label">Źródło</label>
          <select id="f-source" name="source" class="pp-input pp-input-sm">
            <option value="">Wszystkie</option>
            @foreach ($sourceLabels as $value => $label)
              <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="w-full sm:w-40">
          <label for="f-sort" class="pp-label">Sortowanie</label>
          <select id="f-sort" name="sort" class="pp-input pp-input-sm">
            <option value="score" @selected($filters['sort'] === 'score')>Opportunity Score</option>
            <option value="confidence" @selected($filters['sort'] === 'confidence')>Pewność</option>
            <option value="volume" @selected($filters['sort'] === 'volume')>Wolumen</option>
            <option value="cpc" @selected($filters['sort'] === 'cpc')>CPC (rosnąco)</option>
          </select>
        </div>
      </x-pp.filter-bar>

      @if ($opportunities->items === [])
        <x-pp.empty-state title="Brak fraz dla wybranych filtrów" icon="keyword">
          Zmień filtry. Bez filtra statusu odrzucone frazy są ukryte – wybierz „Odrzucona”, aby je zobaczyć i przywrócić.
        </x-pp.empty-state>
      @else
        <form method="post" action="{{ AppUrl::project($project->id, 'keywords/status') }}" x-data="{ count: 0 }" x-on:change="count = $el.querySelectorAll('input[name=&quot;phrases[]&quot;]:checked').length">
          <x-pp.nonce />
          <input type="hidden" name="back" value="{{ $back }}">
          <input type="hidden" name="run" value="{{ $run['id'] }}">

          @if ($canDecide || $canPlan)
            <div class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3">
              <p class="mr-2 text-[13px] text-ink-mute">Zaznaczone: <span class="pp-num font-semibold text-ink" x-text="count">0</span></p>
              @if ($canDecide)
                <button type="submit" name="status" value="accepted" class="pp-btn pp-btn-secondary pp-btn-sm">Zaakceptuj</button>
                <button type="submit" name="status" value="to_review" class="pp-btn pp-btn-secondary pp-btn-sm">Do sprawdzenia</button>
                <button type="submit" name="status" value="rejected" class="pp-btn pp-btn-ghost pp-btn-sm">Odrzuć</button>
                <button type="submit" name="status" value="new" class="pp-btn pp-btn-ghost pp-btn-sm">Przywróć jako nowe</button>
              @endif
              @if ($canPlan)
                <div class="ml-auto flex flex-wrap items-end gap-2">
                  <label for="plan-select" class="sr-only">Plan kampanii</label>
                  <select id="plan-select" name="plan_id" class="pp-input pp-input-sm w-48">
                    <option value="">Nowy plan</option>
                    @foreach ($plans as $id => $name)
                      <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                  </select>
                  <button type="submit" formaction="{{ AppUrl::project($project->id, 'planner/from-keywords') }}" class="pp-btn pp-btn-primary pp-btn-sm">Dodaj do planu kampanii</button>
                </div>
              @endif
            </div>
          @endif

          <div class="pp-table-wrap">
            <table class="pp-table">
              <thead>
                <tr>
                  @if ($canDecide || $canPlan)<th class="w-8"><span class="sr-only">Zaznacz</span></th>@endif
                  <th class="pp-col-sticky">Fraza</th>
                  <th class="text-right">Score</th>
                  <th class="text-right">Pewność</th>
                  <th>Intencja</th>
                  <th class="text-right">CPC</th>
                  <th class="text-right">Wolumen / mies.</th>
                  <th>Konkurencja</th>
                  <th>Klaster</th>
                  <th>Sugerowane miejsce</th>
                  <th>Źródło</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($opportunities->items as $row)
                  @php
                    $status = $row['decision_status'] ?? 'new';
                    $group = $row['suggested_ad_group_id'] ? ($adGroups[(int) $row['suggested_ad_group_id']] ?? null) : null;
                    $explanation = $row['explanation'];
                    $isV2 = ($row['score_version'] ?? '1.0') === OpportunityScoreV2::VERSION;
                  @endphp
                  <tr x-data="{ open: {{ $openDecision && (int) ($row['decision_id'] ?? 0) === $openDecision ? 'true' : 'false' }} }" class="align-top">
                    @if ($canDecide || $canPlan)
                      <td><input type="checkbox" name="phrases[]" value="{{ $row['phrase'] }}" class="mt-1 size-4 accent-mint" aria-label="Zaznacz {{ $row['phrase'] }}"></td>
                    @endif
                    <td class="pp-col-sticky max-w-[320px]">
                      <button type="button" class="text-left font-medium text-ink hover:text-mint" x-on:click="open = !open" x-bind:aria-expanded="open">{{ $row['phrase'] }}</button>
                      <span class="mt-1 flex flex-wrap gap-1">
                        <x-pp.status-badge :status="$statusTones[$status] ?? 'info'" :label="KeywordStatus::LABELS[$status] ?? $status" />
                        @foreach ($row['quality_flags'] as $flag)
                          @if ($flag !== PhraseQuality::OWN_BRAND)<span class="pp-badge pp-badge-warn">{{ PhraseQuality::LABELS[$flag] ?? $flag }}</span>@endif
                        @endforeach
                      </span>
                      <div x-show="open" x-cloak class="mt-3 space-y-2 text-[12.5px] leading-5 text-ink-soft">
                        @if ($explanation['action'] ?? null)
                          <p class="rounded-[var(--radius-control)] bg-mint-soft px-2.5 py-1.5 text-ink"><strong>Sugerowane działanie:</strong> {{ $explanation['action'] }}</p>
                        @endif
                        @if ($explanation['strengths'] ?? [])
                          <div><p class="font-semibold text-ink">Mocne strony</p><ul class="list-disc pl-4">@foreach ($explanation['strengths'] as $item)<li>{{ $item }}</li>@endforeach</ul></div>
                        @endif
                        @if ($explanation['risks'] ?? [])
                          <div><p class="font-semibold text-ink">Ryzyka</p><ul class="list-disc pl-4">@foreach ($explanation['risks'] as $item)<li>{{ $item }}</li>@endforeach</ul></div>
                        @endif
                        @if ($explanation['missing'] ?? [])
                          <p><span class="font-semibold text-ink">Brakujące dane (0 pkt):</span> {{ implode(', ', $explanation['missing']) }}</p>
                        @endif
                        <p>{{ $row['reason'] }}</p>
                        <ul class="space-y-0.5">
                          @foreach ($row['score_breakdown'] as $key => $part)
                            <li class="pp-num">{{ ($isV2 ? OpportunityScoreV2::LABELS : OpportunityScore::LABELS)[$key] ?? $key }}: {{ $part['value'] === null ? 'brak danych (0 pkt)' : rtrim(rtrim(number_format($part['points'], 1, ',', ''), '0'), ',') . ' / ' . $part['max'] }}</li>
                          @endforeach
                        </ul>
                        <p class="text-ink-mute">Algorytm oceny: wersja {{ $row['score_version'] ?? '1.0' }} · dane: {{ Format::datetime($row['data_updated_at'] ?? $row['created_at']) }}</p>
                        @if ($row['decision_id'])
                          @if ($openDecision === (int) $row['decision_id'] && $decisionEvents)
                            <ol class="space-y-0.5 border-l border-line pl-3">
                              @foreach ($decisionEvents as $event)
                                <li><span class="pp-num text-ink-mute">{{ Format::datetime($event['created_at']) }}</span> · {{ KeywordStatus::LABELS[$event['from_status']] ?? '—' }} → {{ KeywordStatus::LABELS[$event['to_status']] ?? '—' }}@if ($event['user_name']) · {{ $event['user_name'] }}@endif @if ($event['note']) – {{ $event['note'] }}@endif</li>
                              @endforeach
                            </ol>
                          @else
                            <a href="{{ AppUrl::project($project->id, 'keywords', array_filter(request()->query(), 'is_scalar') + ['open' => $row['decision_id']]) }}" class="text-mint underline">Historia decyzji</a>
                          @endif
                        @endif
                      </div>
                    </td>
                    <td class="pp-col-num">
                      <span class="font-semibold text-ink">{{ $row['score'] }}</span>
                      <span class="mt-1 block h-1 w-12 overflow-hidden rounded-full bg-ink/10 sm:ml-auto" aria-hidden="true"><span class="block h-full bg-mint" style="width: {{ (int) $row['score'] }}%"></span></span>
                    </td>
                    <td class="pp-col-num">
                      <span class="text-ink">{{ Statistics::CONFIDENCE_LABELS[$row['confidence']] ?? $row['confidence'] }}</span>
                      @if ($row['confidence_score'] !== null)<span class="block text-[11.5px] text-ink-mute">{{ $row['confidence_score'] }}/100</span>@endif
                    </td>
                    <td class="whitespace-nowrap text-ink-soft">{{ Text::INTENT_LABELS[$row['intent']] ?? 'Brak danych' }}</td>
                    <td class="pp-col-num">
                      @if ($row['cpc_micros'] === null)
                        Brak danych
                      @else
                        {{ Format::money($row['cpc_micros'], $row['cpc_currency'] ?? $currency) }}
                        @if (($row['cpc_currency'] ?? $currency) !== $currency)
                          <span class="block text-[11.5px] text-ink-mute" title="{{ $row['fx_source'] ? 'Kurs ' . rtrim(rtrim(number_format((float) $row['fx_rate'], 4, ',', ''), '0'), ',') . ' z ' . Format::date($row['fx_date']) . ' (' . $row['fx_source'] . ')' : '' }}">
                            {{ $row['cpc_project_micros'] !== null ? '≈ ' . Format::money($row['cpc_project_micros'], $currency) . ' (kurs z ' . Format::date($row['fx_date']) . ')' : 'brak kursu – porównanie niedostępne' }}
                          </span>
                        @endif
                      @endif
                    </td>
                    <td class="pp-col-num">{{ $volumeText($row['search_volume']) }}</td>
                    <td class="text-ink-soft">{{ $row['competition'] ? ($competition[$row['competition']] ?? $row['competition']) : 'Brak danych' }}</td>
                    <td class="max-w-[180px] text-ink-soft">
                      @if ($row['cluster_key'] && isset($clusterNames[$row['cluster_key']]))
                        <a href="{{ AppUrl::project($project->id, 'keywords', ['tab' => 'opportunities', 'cluster' => $row['cluster_key']] + $periodQuery) }}" class="block truncate hover:text-mint">{{ $clusterNames[$row['cluster_key']] }}</a>
                      @else — @endif
                    </td>
                    <td class="max-w-[200px] text-ink-soft">
                      @if ($group)
                        <span class="block truncate">{{ $group['name'] }}</span>
                        <span class="block truncate text-[12px] text-ink-mute">{{ $campaignOptions[$group['campaign_id']] ?? '—' }}</span>
                      @elseif ($row['suggested_campaign_id'])
                        <span class="block truncate">{{ $campaignOptions[(int) $row['suggested_campaign_id']] ?? '—' }}</span>
                      @else
                        <span class="text-ink-mute">Do przypisania</span>
                      @endif
                    </td>
                    <td class="text-[12.5px] text-ink-soft">{{ implode(', ', array_map(fn ($s) => $sourceLabels[$s] ?? $s, explode(',', $row['sources']))) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </form>
        <x-pp.pagination :paginator="$opportunities" label="fraz" />
      @endif
      <p class="border-t border-line px-5 py-3 text-[12.5px] leading-5 text-ink-mute">
        CPC z DataForSEO jest podawane w oryginalnej walucie (USD) i przeliczane na {{ $currency }} wyłącznie po kursie NBP z podaną datą; bez aktualnego kursu porównanie nie jest wykonywane.
        Słowa dodajesz samodzielnie w Google Ads – Penny Pincher niczego nie zmienia na koncie.
      </p>
    </section>
  @elseif ($tab === 'clusters')
    <section class="space-y-4" aria-label="Klastry słów kluczowych">
      <p class="text-[13px] leading-6 text-ink-soft">
        Klaster łączy frazy o podobnym znaczeniu i tej samej intencji (np. różne sposoby pytania o cenę strony). Frazy z innym modyfikatorem – „cena”, „za darmo”, lokalizacja – albo inną intencją trafiają do osobnych klastrów.
        <strong class="text-ink">Wolumen klastra to szacunek:</strong> podajemy wolumen najpopularniejszej frazy i sumę jako górną granicę – podobne frazy mogą dotyczyć tych samych wyszukiwań.
      </p>

      @forelse ($clusters as $cluster)
        @php $counts = $memberStatuses[$cluster['cluster_key']] ?? []; @endphp
        <article class="pp-card" id="cluster-{{ $cluster['cluster_key'] }}">
          <div class="flex flex-wrap items-start justify-between gap-3 px-5 pt-4">
            <div class="min-w-0">
              <h3 class="font-semibold text-ink">{{ $cluster['name'] }}</h3>
              <p class="mt-0.5 text-[13px] text-ink-mute">
                {{ $cluster['phrase_count'] }} {{ Format::plural((int) $cluster['phrase_count'], 'fraza', 'frazy', 'fraz') }}
                · intencja: {{ Text::INTENT_LABELS[$cluster['intent']] ?? 'nieokreślona' }}
                @if ($cluster['topic'] && $cluster['topic'] !== '—') · temat: {{ $cluster['topic'] }}@endif
                @if ($cluster['service']) · usługa: {{ $cluster['service'] }}@endif
              </p>
            </div>
            <div class="text-right">
              <p class="pp-num text-xl font-semibold text-ink">{{ $cluster['score'] }}</p>
              <p class="text-[12px] text-ink-mute">pewność: {{ mb_strtolower(Statistics::CONFIDENCE_LABELS[$cluster['confidence']] ?? $cluster['confidence']) }}</p>
            </div>
          </div>
          <dl class="grid gap-x-6 gap-y-1 px-5 py-3 text-[13px] sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-ink-mute">Wolumen (szacunek)</dt><dd class="pp-num text-ink">{{ $cluster['volume_max'] === null ? 'Brak danych' : 'od ' . Format::number($cluster['volume_max']) . ' do ' . Format::number($cluster['volume_sum']) . ' / mies.' }}</dd></div>
            <div><dt class="text-ink-mute">CPC (mediana)</dt><dd class="pp-num text-ink">
              {{ $cluster['cpc_project_micros'] !== null ? Format::money((int) $cluster['cpc_project_micros'], $currency) : 'Brak porównywalnych danych' }}
              @foreach ($cluster['cpc_original'] as $cur => $value)
                @if ($cur !== $currency)<span class="block text-[11.5px] text-ink-mute">{{ Format::money((int) $value, $cur) }} ({{ $cur }}, oryginalnie)</span>@endif
              @endforeach
            </dd></div>
            <div><dt class="text-ink-mute">Sugerowane miejsce</dt><dd class="text-ink">
              @php $g = $cluster['suggested_ad_group_id'] ? ($adGroups[(int) $cluster['suggested_ad_group_id']] ?? null) : null; @endphp
              {{ $g ? $g['name'] . ' (' . ($campaignOptions[$g['campaign_id']] ?? '—') . ')' : ($cluster['suggested_campaign_id'] ? ($campaignOptions[(int) $cluster['suggested_campaign_id']] ?? '—') : 'Nowa grupa reklam') }}
            </dd></div>
            <div><dt class="text-ink-mute">Decyzje</dt><dd class="text-ink">
              @foreach ($counts as $status => $n){{ KeywordStatus::LABELS[$status] ?? $status }}: {{ $n }}@if (! $loop->last) · @endif @endforeach
            </dd></div>
          </dl>
          <div class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3">
            <a href="{{ AppUrl::project($project->id, 'keywords', ['tab' => 'opportunities', 'cluster' => $cluster['cluster_key']] + $periodQuery) }}" class="pp-btn pp-btn-ghost pp-btn-sm">Pokaż frazy</a>
            @if ($canDecide)
              <form method="post" action="{{ AppUrl::project($project->id, 'keywords/clusters/status') }}" class="flex gap-2">
                <x-pp.nonce />
                <input type="hidden" name="run" value="{{ $run['id'] }}">
                <input type="hidden" name="cluster" value="{{ $cluster['cluster_key'] }}">
                <input type="hidden" name="back" value="{{ $back }}">
                <button type="submit" name="status" value="accepted" class="pp-btn pp-btn-secondary pp-btn-sm">Zaakceptuj klaster</button>
                <button type="submit" name="status" value="rejected" class="pp-btn pp-btn-ghost pp-btn-sm">Odrzuć klaster</button>
              </form>
            @endif
            @if ($canPlan)
              <form method="post" action="{{ AppUrl::project($project->id, 'planner/from-keywords') }}" class="flex flex-wrap gap-2 sm:ml-auto">
                <x-pp.nonce />
                <input type="hidden" name="run" value="{{ $run['id'] }}">
                <input type="hidden" name="clusters[]" value="{{ $cluster['cluster_key'] }}">
                <label for="plan-{{ $cluster['cluster_key'] }}" class="sr-only">Plan</label>
                <select id="plan-{{ $cluster['cluster_key'] }}" name="plan_id" class="pp-input pp-input-sm w-44">
                  <option value="">Nowy plan</option>
                  @foreach ($plans as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                  @endforeach
                </select>
                <button type="submit" class="pp-btn pp-btn-primary pp-btn-sm">Dodaj klaster do planu</button>
              </form>
            @endif
          </div>
        </article>
      @empty
        <section class="pp-card"><x-pp.empty-state title="Brak klastrów" icon="keyword">W analizie nie ma fraz do pogrupowania.</x-pp.empty-state></section>
      @endforelse
    </section>
  @elseif ($tab === 'negatives')
    <section class="pp-card min-w-0" aria-label="Propozycje wykluczeń">
      @if ($negatives === [])
        <x-pp.empty-state title="Brak propozycji wykluczeń" icon="check">W tym okresie nie znaleziono nietrafnych zapytań o istotnym koszcie.</x-pp.empty-state>
      @else
        @php $deployableNegatives = $canPrepareChanges ? array_filter($negatives, fn ($n) => $n['level'] === 'campaign' && $n['campaign_id'] && ! (int) $n['already_excluded']) : []; @endphp
        <form id="negatives-change" method="post" action="{{ AppUrl::project($project->id, 'keywords/negatives/prepare-change') }}" data-busy>
          <x-pp.nonce />
          <input type="hidden" name="run" value="{{ $run['id'] }}">
        </form>
        @if ($deployableNegatives !== [])
          <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3 text-[13px]">
            <x-pp.button variant="secondary" size="sm" icon="changes" form="negatives-change" class="max-w-full whitespace-normal text-left" data-busy-label="Przygotowywanie…">Przygotuj zaznaczone wykluczenia</x-pp.button>
            <span class="text-ink-mute">Tworzy szkic do przeglądu i zatwierdzenia. Penny Pincher sprawdzi konflikty z aktywnymi słowami – nic nie trafia do Google Ads automatycznie, także pojedyncze drogie zapytanie.</span>
          </div>
        @endif
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead>
              <tr>
                @if ($deployableNegatives !== [])<th><span class="sr-only">Zaznacz</span></th>@endif
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
                  @if ($deployableNegatives !== [])
                    <td class="align-top">@if (isset($deployableNegatives[$loop->index]))<input type="checkbox" name="suggestions[]" value="{{ $row['id'] }}" form="negatives-change" class="accent-mint" aria-label="Zaznacz {{ $row['phrase'] }}">@endif</td>
                  @endif
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
        Zapytania informacyjne mają niską pewność – część z nich może prowadzić do klientów. Wykluczenia na poziomie kampanii możesz przygotować w Change Center (zaznacz i kliknij przycisk) albo dodać do planu kampanii (Campaign Planner → Wykluczenia).
        Penny Pincher nie dodaje wykluczeń automatycznie. Status „Już wykluczona” wynika z wykluczeń zsynchronizowanych z Google Ads.
      </p>
    </section>
  @else
    @php $plan = $dfs['plan']; $seedCount = count($plan['seeds']); @endphp
    <div class="grid gap-6 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
      <div class="min-w-0 space-y-6">
        <section class="pp-card min-w-0" aria-labelledby="pp-seeds">
          <div class="pp-card-header">
            <h2 id="pp-seeds" class="pp-card-title">Frazy źródłowe ({{ $seedCount }} / {{ DataForSeoClient::MAX_SEEDS }})</h2>
            <p class="text-[13px] text-ink-mute">{{ $plan['custom'] ? 'Twój zestaw' : 'Propozycje automatyczne' }}{{ $plan['seeds_updated_at'] ? ' · zmieniono ' . Format::datetime($plan['seeds_updated_at']) : '' }}</p>
          </div>
          <div class="space-y-4 p-5 text-sm leading-6">
            <p class="text-[13px] text-ink-soft">DataForSEO zwraca frazy powiązane z frazami źródłowymi. Automatyczne propozycje pochodzą z usług priorytetowych, grupy docelowej i skutecznych zapytań – bez fraz w innym języku, nazw firm, marek konkurencji, zapytań informacyjnych i darmowych. Możesz usunąć dowolną frazę i dodać własne (maks. {{ DataForSeoClient::MAX_SEEDS }}, każda do 80 znaków).</p>

            @if ($canManage)
              <form method="post" action="{{ AppUrl::project($project->id, 'keywords/dataforseo/seeds') }}" class="space-y-4">
                <x-pp.nonce />
                @include('app.partials.period-fields', ['period' => $period])
                @foreach ($plan['auto_seeds'] as $seed)
                  <input type="hidden" name="auto[]" value="{{ $seed['phrase'] }}">
                @endforeach
                @if ($plan['seeds'])
                  <ul class="divide-y divide-line rounded-[var(--radius-control)] border border-line">
                    @foreach ($plan['seeds'] as $i => $seed)
                      <li class="flex items-start gap-3 px-3 py-2">
                        <input id="seed-{{ $i }}" type="checkbox" name="seeds[]" value="{{ $seed['phrase'] }}" checked class="mt-1 size-4 accent-mint">
                        <label for="seed-{{ $i }}" class="min-w-0 flex-1">
                          <span class="text-ink">{{ $seed['phrase'] }}</span>
                          <span class="ml-1 text-[12px] text-ink-mute">{{ ($seed['source'] ?? '') === 'manual' ? 'dodana ręcznie' : (($seed['source'] ?? '') === 'auto' ? 'automatyczna' : $seed['source']) }}</span>
                          @if ($seed['warning'] ?? null)<span class="block text-[12px] text-warn">{{ $seed['warning'] }}</span>@endif
                        </label>
                      </li>
                    @endforeach
                  </ul>
                  <p class="text-[12px] text-ink-mute">Odznacz frazę, aby ją usunąć z zestawu.</p>
                @endif
                <div>
                  <label for="new-seeds" class="pp-label">Dodaj własne frazy (jedna w linii)</label>
                  <textarea id="new-seeds" name="new_seeds" rows="3" class="pp-input" placeholder="np. strony internetowe dla firm"></textarea>
                </div>
                <div class="flex flex-wrap gap-2">
                  <x-pp.button variant="secondary" size="sm" name="action" value="save">Zapisz zestaw</x-pp.button>
                  @if ($plan['custom'])
                    <x-pp.button variant="ghost" size="sm" name="action" value="reset">Przywróć propozycje automatyczne</x-pp.button>
                  @endif
                  @if ($plan['last_used'])
                    <x-pp.button variant="ghost" size="sm" name="action" value="last_used">Wczytaj ostatnio użyty zestaw ({{ count($plan['last_used']) }})</x-pp.button>
                  @endif
                </div>
              </form>
            @else
              <ul class="flex flex-wrap gap-1.5">
                @foreach ($plan['seeds'] as $seed)<li class="pp-badge pp-badge-neutral">{{ $seed['phrase'] }}</li>@endforeach
              </ul>
            @endif

            @if ($plan['rejected_seeds'])
              <details class="text-[13px]">
                <summary class="cursor-pointer text-ink-soft">Automatycznie pominięte frazy ({{ count($plan['rejected_seeds']) }})</summary>
                <ul class="mt-2 space-y-1 text-ink-soft">
                  @foreach ($plan['rejected_seeds'] as $rejected)
                    <li><span class="text-ink">{{ $rejected['phrase'] }}</span> <span class="text-ink-mute">({{ $rejected['source'] }})</span> – {{ $rejected['reason'] }}</li>
                  @endforeach
                </ul>
              </details>
            @endif
          </div>
        </section>

        <section class="pp-card min-w-0" aria-labelledby="pp-dfs">
          <div class="pp-card-header">
            <h2 id="pp-dfs" class="pp-card-title">Pobranie pomysłów z DataForSEO</h2>
            <p class="text-[13px] text-ink-mute">{{ $dfs['ideas'] }} zapisanych fraz{{ $dfs['last_fetched'] ? ' · ostatnie pobranie ' . Format::datetime($dfs['last_fetched']) : '' }}</p>
          </div>
          <div class="space-y-4 p-5 text-sm leading-6">
            @if (! $dfs['configured'])
              <x-pp.alert type="info" title="DataForSEO nie jest skonfigurowane">
                Dodaj stałe <code>PP_DATAFORSEO_LOGIN</code> i <code>PP_DATAFORSEO_PASSWORD</code> w wp-config.php (poza repozytorium). Instrukcja: docs/DATAFORSEO.md. Frazy źródłowe możesz przygotować już teraz.
              </x-pp.alert>
            @else
              <dl class="grid gap-x-6 gap-y-1 rounded-[var(--radius-control)] border border-line px-3 py-2.5 text-[13px] sm:grid-cols-2">
                <div><dt class="inline text-ink-mute">Endpoint:</dt> <dd class="inline text-ink">Google Ads Keywords For Keywords (live)</dd></div>
                <div><dt class="inline text-ink-mute">Lokalizacja / język:</dt> <dd class="inline text-ink">{{ \App\PennyPincher\Domain\Projects\BusinessContext::LOCATIONS[$plan['location_code']] ?? $plan['location_code'] }} / {{ $plan['language_code'] }}</dd></div>
                <div><dt class="inline text-ink-mute">Liczba fraz:</dt> <dd class="pp-num inline text-ink">{{ $seedCount }} / {{ DataForSeoClient::MAX_SEEDS }}</dd></div>
                <div><dt class="inline text-ink-mute">Szacowany koszt:</dt> <dd class="pp-num inline font-semibold text-ink">{{ $plan['cached'] ? '0,00 USD (wynik w pamięci podręcznej)' : 'ok. ' . DataForSeoService::usd($plan['estimated_cost']) . ' USD' }}</dd></div>
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
                    <span class="text-ink-soft">Zgadzam się na {{ $plan['cached'] ? 'użycie zapisanego wyniku (bez kosztu)' : 'płatne zapytanie do DataForSEO (ok. ' . DataForSeoService::usd($plan['estimated_cost']) . ' USD)' }} dla {{ $seedCount }} zapisanych fraz źródłowych. Zadanie wykona proces w tle.</span>
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
      </div>

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
          <p class="mt-2">Pobrane frazy są deduplikowane, porównywane z istniejącymi słowami i wykluczeniami, oceniane (oferta, intencja, grupa docelowa, CPC, popyt, konkurencja), grupowane w klastry i trafiają do „Nowych fraz”.</p>
        </section>
      </aside>
    </div>
  @endif

  @if ($tab !== 'dataforseo' && $run && $canRun)
    <section class="pp-card flex flex-wrap items-center justify-between gap-4 p-5">
      <p class="text-[13px] text-ink-mute">Analiza z {{ Format::datetime($run['created_at']) }} dla okresu {{ Format::period($run['date_from'], $run['date_to']) }} · {{ (int) ($run['summary']['terms_analyzed'] ?? 0) }} przeanalizowanych zapytań · {{ (int) ($run['summary']['ideas_available'] ?? 0) }} fraz z DataForSEO · ocena v{{ $run['summary']['score_version'] ?? '1.0' }}.</p>
      @include('app.partials.analysis.run-form', ['return' => 'keywords', 'variant' => 'secondary', 'size' => 'sm', 'label' => 'Uruchom ponownie'])
    </section>
  @endif
@endsection
