@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Analysis\Text;
  use App\PennyPincher\Domain\Planner\AdTemplates;
  use App\PennyPincher\Domain\Planner\AdValidator;
  use App\PennyPincher\Domain\Planner\BudgetAllocator;
  use App\PennyPincher\Domain\Planner\MatchType;
  use App\PennyPincher\Domain\Planner\PlanStatus;
  use App\PennyPincher\Domain\Projects\BusinessContext;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $plan['currency'];
  $base = rtrim(AppUrl::project($project->id, 'planner/' . $plan['id']), '/');
  $tabs = ['structure' => 'Struktura', 'budget' => 'Budżet i prognozy', 'negatives' => 'Wykluczenia', 'ads' => 'Reklamy', 'history' => 'Historia'];
  $statusTones = ['draft' => 'info', 'review' => 'to_review', 'approved' => 'accepted', 'archived' => 'archived'];
  $groupOptions = [];
  foreach ($tree['campaigns'] as $c) {
    foreach ($c['groups'] as $g) {
      $groupOptions[$g['id']] = $c['name'] . ' › ' . $g['name'];
    }
  }
  $hasBroad = (bool) array_filter($tree['keywords'], fn ($k) => $k['match_type'] === MatchType::BROAD);
  $keywordCount = count($tree['keywords']);
  $money = fn ($m) => Format::money($m === null ? null : (int) $m, $currency, 0);
@endphp

@section('content')
  <section class="pp-card" aria-label="Plan">
    <div class="flex flex-wrap items-start justify-between gap-4 p-5">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <x-pp.status-badge :status="$statusTones[$plan['status']] ?? 'info'" :label="PlanStatus::LABELS[$plan['status']]" />
          <span class="text-[13px] text-ink-mute">Autor: {{ $plan['author_name'] ?: '—' }} · utworzono {{ Format::datetime($plan['created_at']) }} · zmiana {{ Format::datetime($plan['updated_at']) }}</span>
        </div>
        <dl class="mt-3 grid gap-x-8 gap-y-1 text-[13px] sm:grid-cols-2 lg:grid-cols-3">
          <div><dt class="inline text-ink-mute">Cel:</dt> <dd class="inline text-ink">{{ BusinessContext::GOALS[$plan['goal']] ?? '—' }}</dd></div>
          <div><dt class="inline text-ink-mute">Usługa:</dt> <dd class="inline text-ink">{{ $plan['service'] ?: '—' }}</dd></div>
          <div><dt class="inline text-ink-mute">Lokalizacja / język:</dt> <dd class="inline text-ink">{{ $plan['location'] ?: '—' }} / {{ BusinessContext::LANGUAGES[$plan['language']] ?? $plan['language'] }}</dd></div>
          <div><dt class="inline text-ink-mute">Budżet miesięczny:</dt> <dd class="pp-num inline text-ink">{{ $plan['monthly_budget_micros'] !== null ? $money($plan['monthly_budget_micros']) : 'nie ustawiono' }}</dd></div>
          <div><dt class="inline text-ink-mute">Struktura:</dt> <dd class="pp-num inline text-ink">{{ count($tree['campaigns']) }} kamp. · {{ count($groupOptions) }} grup · {{ $keywordCount }} słów · {{ count($tree['negatives']) }} wykl.</dd></div>
          @if ($plan['audience'])<div class="sm:col-span-2 lg:col-span-3"><dt class="inline text-ink-mute">Grupa docelowa:</dt> <dd class="inline text-ink">{{ $plan['audience'] }}</dd></div>@endif
        </dl>
      </div>
      <div class="flex flex-wrap gap-2">
        <x-pp.button variant="secondary" size="sm" :href="$base . '/export/csv'">Eksport CSV</x-pp.button>
        <x-pp.button variant="secondary" size="sm" :href="$base . '/export/html'" target="_blank" rel="noopener">Dokument do druku</x-pp.button>
        @if ($canEditStatus)
          <form method="post" action="{{ $base }}/copy"><x-pp.nonce /><x-pp.button variant="ghost" size="sm">Kopiuj plan</x-pp.button></form>
        @endif
      </div>
    </div>

    @if ($canEditStatus)
      <form method="post" action="{{ $base }}/status" class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3">
        <x-pp.nonce />
        <input type="hidden" name="tab" value="{{ $tab }}">
        <span class="text-[13px] text-ink-mute">Zmień status:</span>
        @foreach (['draft' => 'Przywróć do szkicu', 'review' => 'Wyślij do przeglądu', 'approved' => 'Zatwierdź plan', 'archived' => 'Archiwizuj'] as $value => $label)
          @if ($value !== $plan['status'] && ($canApprove || (in_array($value, PlanStatus::EDITABLE, true) && $editable)))
            <button type="submit" name="status" value="{{ $value }}" class="pp-btn pp-btn-sm {{ $value === 'approved' ? 'pp-btn-primary' : 'pp-btn-ghost' }}">{{ $label }}</button>
          @endif
        @endforeach
        @unless ($editable)
          <span class="text-[12.5px] text-ink-mute">Plan zatwierdzony lub zarchiwizowany jest tylko do odczytu – zmień status na „Szkic” albo skopiuj plan.</span>
        @endunless
      </form>
    @endif

    @if ($plan['status'] === 'approved' || ($changes['sets'][(int) $plan['id']] ?? null))
      <div class="border-t border-line px-5 py-3 text-[13px]">
        @if ($changes['sets'][(int) $plan['id']] ?? null)
          @include('app.partials.changes.source-change', ['sourceId' => $plan['id'], 'action' => '', 'blocker' => null])
          @if ($deployedLinks !== [])<p class="mt-1 text-ink-mute">W Google Ads utworzono {{ count($deployedLinks) }} z {{ count($tree['campaigns']) }} kampanii planu – ponowne przygotowanie pominie już utworzone.</p>@endif
        @elseif ($changes['canPrepare'])
          <details>
            <summary class="cursor-pointer font-semibold text-ink"><x-pp.icon name="changes" :size="16" class="inline" /> Przygotuj wdrożenie do Google Ads</summary>
            <form method="post" action="{{ $base }}/deploy" class="mt-3 grid gap-4 lg:grid-cols-4" data-busy>
              <x-pp.nonce />
              <x-pp.field name="geo_ids" label="Lokalizacje (ID geo)" value="2616" required help="Np. 2616 = Polska." />
              <x-pp.field name="bidding" label="Strategia stawek" :options="\App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog::BIDDING" value="MANUAL_CPC" required />
              <x-pp.field name="eu_political" label="Reklamy polityczne w UE" :options="['' => 'Wybierz…', 'no' => 'Nie zawiera', 'yes' => 'Zawiera']" required />
              <label class="flex items-start gap-2 self-end pb-2"><input type="checkbox" name="search_partners" value="1" class="mt-1"> <span>Partnerzy wyszukiwania</span></label>
              <p class="text-ink-soft lg:col-span-4">Penny Pincher sprawdzi konto (duplikaty nazw i słów), przygotuje w kolejności zależności: budżet → kampania → grupy → słowa → reklamy → wykluczenia → kierowanie. Kampanie powstaną jako <strong>wstrzymane</strong>. To szkic w Change Center – wymaga przeglądu, zatwierdzenia i świadomego wdrożenia.</p>
              <div class="lg:col-span-4"><x-pp.button variant="secondary" size="sm" icon="changes" data-busy-label="Przygotowywanie…">Przygotuj wdrożenie (szkic)</x-pp.button></div>
            </form>
          </details>
        @endif
      </div>
    @endif

    @if ($canEdit)
      <details class="border-t border-line px-5 py-3">
        <summary class="cursor-pointer text-[13px] font-semibold text-ink">Edytuj dane planu</summary>
        <form method="post" action="{{ $base }}" class="mt-3 grid gap-4 lg:grid-cols-3">
          <x-pp.nonce />
          <x-pp.field name="name" label="Nazwa" required :value="$plan['name']" />
          <x-pp.field name="goal" label="Cel biznesowy" :options="['' => 'Wybierz…'] + BusinessContext::GOALS" :value="$plan['goal']" />
          <x-pp.field name="service" label="Usługa" :value="$plan['service']" />
          <x-pp.field name="location" label="Lokalizacja" :value="$plan['location']" />
          <x-pp.field name="language" label="Język" required :options="BusinessContext::LANGUAGES" :value="$plan['language']" />
          <x-pp.field name="audience" label="Grupa docelowa" :value="$plan['audience']" />
          <x-pp.field name="notes" label="Notatki" type="textarea" rows="2" :value="$plan['notes']" class="lg:col-span-3" />
          <div class="lg:col-span-3"><x-pp.button size="sm">Zapisz</x-pp.button></div>
        </form>
      </details>
    @endif
  </section>

  @if (! $measurement['reliable'])
    <x-pp.alert type="warning" title="Pomiar konwersji wymaga uwagi">
      {{ $measurement['reason'] }} Dopasowanie przybliżone i automatyczne strategie stawek uczą się na konwersjach – przy niewiarygodnym pomiarze mogą wydawać budżet na nietrafne zapytania. Najpierw popraw pomiar (Action Plan).
    </x-pp.alert>
  @endif

  @if ($conflicts !== [])
    <x-pp.alert type="error" title="Konflikty wykluczeń ({{ count($conflicts) }})">
      <ul class="list-disc pl-4">
        @foreach (array_slice($conflicts, 0, 5) as $conflict)<li>{{ $conflict['message'] }}</li>@endforeach
      </ul>
      <a href="{{ $base }}?tab=negatives#negatives" class="font-semibold underline">Przejdź do wykluczeń</a>
    </x-pp.alert>
  @endif

  <nav class="-mx-1 flex gap-1 overflow-x-auto border-b border-line px-1" aria-label="Zakładki planu">
    @foreach ($tabs as $key => $label)
      <a href="{{ $base }}?tab={{ $key }}" @class(['-mb-px shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium', 'border-mint text-ink' => $tab === $key, 'border-transparent text-ink-mute hover:text-ink' => $tab !== $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
  </nav>

  @if ($tab === 'structure')
    @if ($hasBroad && ! $measurement['reliable'])
      <x-pp.alert type="warning">Plan zawiera słowa w dopasowaniu przybliżonym, a pomiar konwersji nie jest wiarygodny. Rozważ dopasowanie do wyrażenia lub ścisłe.</x-pp.alert>
    @endif

    @forelse ($tree['campaigns'] as $campaign)
      <section class="pp-card min-w-0 scroll-mt-24" id="campaign-{{ $campaign['id'] }}" aria-label="Kampania {{ $campaign['name'] }}">
        <div class="pp-card-header flex-wrap gap-3">
          <div class="min-w-0">
            <h2 class="pp-card-title">{{ $campaign['name'] }}</h2>
            <p class="text-[12.5px] text-ink-mute">{{ $campaign['kind'] === 'brand' ? 'Kampania brandowa' : 'Kampania w wyszukiwarce' }} · budżet: {{ $campaign['budget_micros'] !== null ? $money($campaign['budget_micros']) . ' / mies.' : 'nie przydzielono' }} · {{ count($campaign['groups']) }} {{ Format::plural(count($campaign['groups']), 'grupa', 'grupy', 'grup') }}</p>
          </div>
          @if ($canEdit)
            <details class="text-[13px]">
              <summary class="cursor-pointer text-ink-soft">Edytuj kampanię</summary>
              <div class="mt-2 space-y-2">
                <form method="post" action="{{ $base }}/campaigns/{{ $campaign['id'] }}" class="flex flex-wrap items-end gap-2">
                  <x-pp.nonce />
                  <div><label class="pp-label" for="cn-{{ $campaign['id'] }}">Nazwa</label><input id="cn-{{ $campaign['id'] }}" name="name" value="{{ $campaign['name'] }}" class="pp-input pp-input-sm w-56" required maxlength="190"></div>
                  <div><label class="pp-label" for="ck-{{ $campaign['id'] }}">Typ</label><select id="ck-{{ $campaign['id'] }}" name="kind" class="pp-input pp-input-sm"><option value="search" @selected($campaign['kind'] === 'search')>Wyszukiwarka</option><option value="brand" @selected($campaign['kind'] === 'brand')>Brandowa</option></select></div>
                  <div><label class="pp-label" for="cu-{{ $campaign['id'] }}">URL docelowy</label><input id="cu-{{ $campaign['id'] }}" name="final_url" type="url" value="{{ $campaign['final_url'] }}" class="pp-input pp-input-sm w-56" placeholder="https://"></div>
                  <x-pp.button variant="secondary" size="sm">Zapisz</x-pp.button>
                </form>
                <form method="post" action="{{ $base }}/campaigns/{{ $campaign['id'] }}/delete" onsubmit="return confirm('Usunąć kampanię z planu razem z grupami i słowami?')">
                  <x-pp.nonce /><button class="pp-btn pp-btn-ghost pp-btn-sm text-danger">Usuń kampanię z planu</button>
                </form>
              </div>
            </details>
          @endif
        </div>

        @foreach ($campaign['groups'] as $group)
          <div class="scroll-mt-24 border-t border-line" id="group-{{ $group['id'] }}">
            <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
              <h3 class="font-semibold text-ink">{{ $group['name'] }} <span class="pp-num text-[13px] font-normal text-ink-mute">({{ count($group['keywords']) }} słów)</span></h3>
              @if ($canEdit)
                <details class="text-[13px]">
                  <summary class="cursor-pointer text-ink-soft">Edytuj grupę</summary>
                  <div class="mt-2 space-y-2">
                    <form method="post" action="{{ $base }}/groups/{{ $group['id'] }}" class="flex flex-wrap items-end gap-2">
                      <x-pp.nonce />
                      <div><label class="pp-label" for="gn-{{ $group['id'] }}">Nazwa</label><input id="gn-{{ $group['id'] }}" name="name" value="{{ $group['name'] }}" class="pp-input pp-input-sm w-52" required maxlength="190"></div>
                      <div><label class="pp-label" for="gc-{{ $group['id'] }}">Kampania</label><select id="gc-{{ $group['id'] }}" name="plan_campaign_id" class="pp-input pp-input-sm w-44">@foreach ($tree['campaigns'] as $c)<option value="{{ $c['id'] }}" @selected((int) $c['id'] === (int) $campaign['id'])>{{ $c['name'] }}</option>@endforeach</select></div>
                      <div><label class="pp-label" for="gu-{{ $group['id'] }}">URL docelowy</label><input id="gu-{{ $group['id'] }}" name="final_url" type="url" value="{{ $group['final_url'] }}" class="pp-input pp-input-sm w-52" placeholder="https://"></div>
                      <x-pp.button variant="secondary" size="sm">Zapisz</x-pp.button>
                    </form>
                    <form method="post" action="{{ $base }}/groups/{{ $group['id'] }}/delete" onsubmit="return confirm('Usunąć grupę reklam z planu?')"><x-pp.nonce /><button class="pp-btn pp-btn-ghost pp-btn-sm text-danger">Usuń grupę</button></form>
                  </div>
                </details>
              @endif
            </div>

            @if ($group['keywords'])
              <div class="pp-table-wrap">
                <table class="pp-table">
                  <thead>
                    <tr>
                      <th class="pp-col-sticky">Słowo kluczowe</th>
                      <th>Dopasowanie</th>
                      <th>Intencja</th>
                      <th class="text-right">Score</th>
                      <th class="text-right">Szac. CPC</th>
                      <th class="text-right">Wolumen</th>
                      <th>Źródło</th>
                      <th>Pewność</th>
                      @if ($canEdit)<th><span class="sr-only">Akcje</span></th>@endif
                    </tr>
                  </thead>
                  <tbody>
                    @foreach ($group['keywords'] as $keyword)
                      <tr class="align-top">
                        <td class="pp-col-sticky max-w-[260px] font-medium text-ink">{{ MatchType::notation($keyword['phrase'], $keyword['match_type']) }}
                          @if ($keyword['term_clicks'] !== null)<span class="block text-[11.5px] font-normal text-ink-mute">historia: {{ Format::number($keyword['term_clicks']) }} klik. · {{ Format::conversions($keyword['term_conversions']) }} konw.</span>@endif
                        </td>
                        <td>
                          @if ($canEdit)
                            <form method="post" action="{{ $base }}/keywords/{{ $keyword['id'] }}">
                              <x-pp.nonce /><input type="hidden" name="action" value="match"><input type="hidden" name="anchor_group" value="{{ $group['id'] }}">
                              <label for="mt-{{ $keyword['id'] }}" class="sr-only">Dopasowanie</label>
                              <select id="mt-{{ $keyword['id'] }}" name="match_type" class="pp-input pp-input-sm w-36" data-autosubmit>
                                @foreach (MatchType::LABELS as $value => $label)<option value="{{ $value }}" @selected($keyword['match_type'] === $value)>{{ $label }}</option>@endforeach
                              </select>
                            </form>
                          @else
                            {{ MatchType::LABELS[$keyword['match_type']] ?? $keyword['match_type'] }}
                          @endif
                          @if ($keyword['match_type'] === MatchType::BROAD && ! $measurement['reliable'])<span class="mt-1 block text-[11.5px] text-warn">ryzykowne przy obecnym pomiarze</span>@endif
                        </td>
                        <td class="whitespace-nowrap text-ink-soft">{{ Text::INTENT_LABELS[$keyword['intent']] ?? 'Brak danych' }}</td>
                        <td class="pp-col-num">{{ $keyword['score'] ?? '—' }}</td>
                        <td class="pp-col-num">
                          @if ($keyword['cpc_micros'] === null) Brak danych
                          @else {{ Format::money((int) $keyword['cpc_micros'], $keyword['cpc_currency'] ?? $currency) }}
                            @if (($keyword['cpc_currency'] ?? $currency) !== $currency)<span class="block text-[11.5px] text-ink-mute">{{ $keyword['cpc_project_micros'] !== null ? '≈ ' . Format::money((int) $keyword['cpc_project_micros'], $currency) : 'brak kursu' }}</span>@endif
                          @endif
                        </td>
                        <td class="pp-col-num">{{ $keyword['search_volume'] === null ? 'Brak danych' : Format::number($keyword['search_volume']) }}</td>
                        <td class="text-[12.5px] text-ink-soft">{{ str_replace(['search_terms', 'dataforseo', 'keyword_opportunities', 'manual', ','], ['wyszukiwane hasła', 'DataForSEO', 'Keyword Opportunities', 'ręcznie', ', '], $keyword['source']) }}</td>
                        <td class="text-ink-soft">{{ Statistics::CONFIDENCE_LABELS[$keyword['confidence']] ?? 'Brak danych' }}</td>
                        @if ($canEdit)
                          <td class="whitespace-nowrap">
                            @if (count($groupOptions) > 1)
                              <form method="post" action="{{ $base }}/keywords/{{ $keyword['id'] }}" class="mb-1 flex flex-col items-start gap-1">
                                <x-pp.nonce /><input type="hidden" name="action" value="move"><input type="hidden" name="anchor_group" value="{{ $group['id'] }}">
                                <label for="mv-{{ $keyword['id'] }}" class="sr-only">Przenieś do grupy</label>
                                <select id="mv-{{ $keyword['id'] }}" name="plan_ad_group_id" class="pp-input pp-input-sm w-36">
                                  @foreach ($groupOptions as $gid => $glabel)<option value="{{ $gid }}" @selected((int) $gid === (int) $group['id'])>{{ $glabel }}</option>@endforeach
                                </select>
                                <button class="pp-btn pp-btn-ghost pp-btn-sm">Przenieś</button>
                              </form>
                            @endif
                            <form method="post" action="{{ $base }}/keywords/{{ $keyword['id'] }}">
                              <x-pp.nonce /><input type="hidden" name="action" value="delete"><input type="hidden" name="anchor_group" value="{{ $group['id'] }}">
                              <button class="pp-btn pp-btn-ghost pp-btn-sm text-danger">Usuń</button>
                            </form>
                          </td>
                        @endif
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            @else
              <p class="px-5 pb-3 text-[13px] text-ink-mute">Brak słów kluczowych w grupie.</p>
            @endif

            @if ($canEdit)
              <form method="post" action="{{ $base }}/groups/{{ $group['id'] }}/keywords" class="flex flex-wrap items-end gap-2 px-5 py-3">
                <x-pp.nonce />
                <div class="min-w-0 flex-1">
                  <label for="kw-{{ $group['id'] }}" class="pp-label">Dodaj słowa (jedno w linii)</label>
                  <textarea id="kw-{{ $group['id'] }}" name="phrases" rows="2" class="pp-input" placeholder="np. strony internetowe dla firm"></textarea>
                </div>
                <div>
                  <label for="kwm-{{ $group['id'] }}" class="pp-label">Dopasowanie</label>
                  <select id="kwm-{{ $group['id'] }}" name="match_type" class="pp-input pp-input-sm w-44">
                    <option value="">Propozycja (ścisłe / do wyrażenia)</option>
                    @foreach (MatchType::LABELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                  </select>
                </div>
                <x-pp.button variant="secondary" size="sm" icon="plus">Dodaj</x-pp.button>
              </form>
            @endif
          </div>
        @endforeach

        @if ($canEdit)
          <form method="post" action="{{ $base }}/groups" class="flex flex-wrap items-end gap-2 border-t border-line px-5 py-3">
            <x-pp.nonce />
            <input type="hidden" name="plan_campaign_id" value="{{ $campaign['id'] }}">
            <div><label for="ng-{{ $campaign['id'] }}" class="pp-label">Nowa grupa reklam</label><input id="ng-{{ $campaign['id'] }}" name="name" class="pp-input pp-input-sm w-56" required maxlength="190" placeholder="np. Cena strony"></div>
            <x-pp.button variant="ghost" size="sm" icon="plus">Dodaj grupę</x-pp.button>
          </form>
        @endif
      </section>
    @empty
      <section class="pp-card"><x-pp.empty-state title="Plan jest pusty" icon="planner">Dodaj kampanię poniżej albo dodaj klastry fraz z Keyword Opportunities.</x-pp.empty-state></section>
    @endforelse

    @if ($canEdit)
      <section class="pp-card p-5">
        <form method="post" action="{{ $base }}/campaigns" class="flex flex-wrap items-end gap-2">
          <x-pp.nonce />
          <div><label for="nc" class="pp-label">Nowa kampania</label><input id="nc" name="name" class="pp-input pp-input-sm w-64" required maxlength="190" placeholder="np. Projektowanie stron WWW"></div>
          <div><label for="nck" class="pp-label">Typ</label><select id="nck" name="kind" class="pp-input pp-input-sm"><option value="search">Wyszukiwarka</option><option value="brand">Brandowa</option></select></div>
          <x-pp.button size="sm" icon="plus">Dodaj kampanię</x-pp.button>
        </form>
      </section>
    @endif
  @elseif ($tab === 'budget')
    @php $b = $budget; @endphp
    <section class="pp-card" aria-label="Podsumowanie budżetu">
      <dl class="grid divide-y divide-line sm:grid-cols-2 sm:divide-y-0 xl:grid-cols-4 xl:divide-x">
        <x-pp.stat-card label="Budżet planu" :value="$b['budget'] !== null ? $money($b['budget']) . ' / mies.' : 'Nie ustawiono'" :hint="$b['daily'] !== null ? 'ok. ' . $money($b['daily']) . ' dziennie' : null" tone="spend" />
        <x-pp.stat-card label="Przydzielono kampaniom" :value="$money($b['allocated'])" />
        <x-pp.stat-card :label="$b['over'] ? 'Przekroczenie' : 'Niewykorzystane'" :value="$b['unallocated'] === null ? '—' : $money(abs($b['unallocated']))" :hint="$b['over'] ? 'Suma kampanii jest wyższa niż budżet planu' : null" />
        <x-pp.stat-card label="Scenariusz" :value="$b['config']['label']" :hint="'Sprawdzone ' . round($b['config']['proven_share'] * 100) . '% · test ' . round((1 - $b['config']['proven_share']) * 100) . '%'" />
      </dl>
      @foreach ($b['warnings'] as $warning)
        <p class="border-t border-line px-5 py-2.5 text-[13px] text-warn">{{ $warning }}</p>
      @endforeach
      <p class="border-t border-line px-5 py-3 text-[12.5px] leading-5 text-ink-mute">
        Kwoty są planistyczne. W Google Ads ustawia się budżet dzienny – w pojedynczym dniu kampania może wydać do 2× budżetu dziennego, ale w miesiącu nie więcej niż ok. 30,4× budżet dzienny. Planowanie nie zmienia budżetów na koncie.
      </p>
    </section>

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
      <section class="pp-card min-w-0" aria-label="Podział i prognozy">
        <div class="pp-card-header">
          <h2 class="pp-card-title">Podział budżetu i prognozy</h2>
          @if ($canBudget && $b['proposal']['allocations'])
            <form method="post" action="{{ $base }}/budget/apply"><x-pp.nonce /><input type="hidden" name="tab" value="budget"><x-pp.button size="sm">Zastosuj proponowany podział</x-pp.button></form>
          @endif
        </div>
        @foreach ($b['proposal']['notes'] as $note)<p class="px-5 pt-3 text-[13px] text-ink-soft">{{ $note }}</p>@endforeach
        @if ($b['rows'] === [])
          <x-pp.empty-state title="Brak kampanii" icon="planner">Dodaj kampanie w zakładce Struktura.</x-pp.empty-state>
        @else
          <div class="pp-table-wrap">
            <table class="pp-table">
              <thead>
                <tr>
                  <th class="pp-col-sticky">Kampania</th>
                  <th>Dane</th>
                  <th class="text-right">Propozycja</th>
                  <th class="text-right">Budżet w planie</th>
                  <th class="text-right">Dziennie</th>
                  <th class="text-right">Szac. CPC</th>
                  <th class="text-right">Kliknięcia / mies.</th>
                  <th>Konwersje / koszt pozyskania</th>
                  <th>Niepewność</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($b['rows'] as $row)
                  @php $f = $row['forecast']; @endphp
                  <tr class="align-top">
                    <td class="pp-col-sticky max-w-[220px]"><span class="font-medium text-ink">{{ $row['name'] }}</span>@if ($row['priority'])<span class="block text-[11.5px] text-mint-dark">priorytet biznesowy</span>@endif</td>
                    <td class="text-[12.5px] text-ink-soft">{{ $row['profile']['evidence'] === 'proven' ? 'Sprawdzona' : 'Test' }}<span class="block text-ink-mute">{{ Format::number($row['profile']['clicks']) }} klik. · {{ Format::conversions($row['profile']['conversions']) }} konw. w historii</span></td>
                    <td class="pp-col-num">{{ $row['proposed_micros'] !== null ? $money($row['proposed_micros']) : '—' }}@if ($row['proposed_micros'] !== null && $b['budget'])<span class="block text-[11.5px] text-ink-mute">{{ round($row['proposed_micros'] / $b['budget'] * 100) }}%</span>@endif</td>
                    <td class="pp-col-num">
                      @if ($canBudget)
                        <form method="post" action="{{ $base }}/campaigns/{{ $row['id'] }}/budget" class="flex justify-end gap-1">
                          <x-pp.nonce /><input type="hidden" name="tab" value="budget">
                          <label for="cb-{{ $row['id'] }}" class="sr-only">Budżet kampanii</label>
                          <input id="cb-{{ $row['id'] }}" name="budget" inputmode="decimal" value="{{ Format::moneyInput($row['budget_micros']) }}" class="pp-input pp-input-sm w-24 text-right">
                          <button class="pp-btn pp-btn-ghost pp-btn-sm">OK</button>
                        </form>
                      @else
                        {{ $row['budget_micros'] !== null ? $money($row['budget_micros']) : '—' }}
                      @endif
                    </td>
                    <td class="pp-col-num">{{ $f['daily'] !== null ? $money($f['daily']) : '—' }}</td>
                    <td class="pp-col-num">{{ $f['cpc'] !== null ? Format::money($f['cpc_low'], $currency) . '–' . Format::money($f['cpc_high'], $currency) : 'Brak danych' }}@if ($f['cpc_source'])<span class="block text-[11.5px] text-ink-mute">{{ $f['cpc_source'] }}</span>@endif</td>
                    <td class="pp-col-num">{{ $f['clicks_low'] !== null ? Format::number($f['clicks_low']) . '–' . Format::number($f['clicks_high']) : '—' }}</td>
                    <td class="max-w-[240px] text-[12.5px] text-ink-soft">
                      @if ($f['lead_message'])
                        {{ $f['lead_message'] }}
                      @else
                        {{ rtrim(rtrim(number_format($f['conversions_low'], 1, ',', ''), '0'), ',') }}–{{ rtrim(rtrim(number_format($f['conversions_high'], 1, ',', ''), '0'), ',') }} konw. · {{ $money($f['cpl_low']) }}–{{ $money($f['cpl_high']) }} / konw.
                      @endif
                    </td>
                    <td><x-pp.status-badge :status="['low' => 'reviewed', 'medium' => 'warning', 'high' => 'high'][$f['uncertainty']]" :label="['low' => 'Niska', 'medium' => 'Średnia', 'high' => 'Wysoka'][$f['uncertainty']]" /></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <p class="border-t border-line px-5 py-3 text-[12.5px] leading-5 text-ink-mute">
            Prognozy to orientacyjne zakresy, nie obietnica wyników. CPC: z historii konta albo DataForSEO (przeliczone kursem NBP), z marginesem −20% / +30%. Konwersje pokazujemy tylko dla kampanii z udokumentowaną historią i przy wiarygodnym pomiarze – kliknięcia telefonu czy krótkie wizyty nie są liczone jako leady.
          </p>
        @endif
      </section>

      <section class="pp-card p-5" aria-labelledby="scenario">
        <h2 id="scenario" class="pp-card-title">Budżet i scenariusz</h2>
        <p class="mt-1 text-[13px] text-ink-soft">Podział zależy od priorytetów biznesowych, potencjału fraz, wyników historycznych i niepewności – nie od samej liczby kliknięć. Nowe frazy nie są z założenia lepsze od sprawdzonych.</p>
        <form method="post" action="{{ $base }}/budget" class="mt-4 space-y-4" x-data="{ s: @js($plan['scenario']) }">
          <x-pp.nonce /><input type="hidden" name="tab" value="budget">
          <fieldset @disabled(! $canBudget) class="space-y-4">
            <x-pp.field name="monthly_budget" :label="'Budżet miesięczny (' . $currency . ')'" inputmode="decimal" :value="Format::moneyInput($plan['monthly_budget_micros'])" />
            <div class="space-y-2">
              @foreach ($scenarios as $key => $scenario)
                <label class="flex items-start gap-2.5 rounded-[var(--radius-control)] border border-line p-3" x-bind:class="s === '{{ $key }}' && 'border-mint bg-mint-soft/40'">
                  <input type="radio" name="scenario" value="{{ $key }}" x-model="s" class="mt-1 accent-mint" @checked($plan['scenario'] === $key)>
                  <span><span class="font-semibold text-ink">{{ $scenario['label'] }}</span><span class="block text-[12.5px] text-ink-soft">{{ $scenario['description'] }} Sprawdzone: {{ round($scenario['proven_share'] * 100) }}%.</span></span>
                </label>
              @endforeach
            </div>
            <details>
              <summary class="cursor-pointer text-[13px] font-semibold text-ink">Dostosuj scenariusz (%)</summary>
              <div class="mt-3 grid grid-cols-2 gap-3">
                @foreach (['proven_share' => 'Udział sprawdzonych kampanii', 'priority' => 'Waga: priorytet biznesowy', 'potential' => 'Waga: potencjał fraz', 'performance' => 'Waga: wyniki historyczne'] as $key => $label)
                  <div>
                    <label for="sc-{{ $key }}" class="pp-label">{{ $label }}</label>
                    <input id="sc-{{ $key }}" name="{{ $key }}" inputmode="decimal" class="pp-input pp-input-sm" value="{{ isset($plan['scenario_config'][$key]) ? round($plan['scenario_config'][$key] * 100) : '' }}" placeholder="{{ round(($b['config'][$key]) * 100) }}">
                  </div>
                @endforeach
              </div>
              <p class="mt-2 text-[12px] text-ink-mute">Puste pole = wartość domyślna scenariusza. Wagi są normalizowane do 100%.</p>
            </details>
            @if ($canBudget)<x-pp.button size="sm">Zapisz budżet i scenariusz</x-pp.button>@else<p class="text-[12.5px] text-ink-mute">Budżet może zmienić administrator projektu{{ $editable ? '' : ' (plan jest zablokowany)' }}.</p>@endif
          </fieldset>
        </form>
      </section>
    </div>
  @elseif ($tab === 'negatives')
    <div class="grid gap-6 2xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]" id="negatives">
      <section class="pp-card min-w-0" aria-label="Wykluczenia planu">
        <div class="pp-card-header"><h2 class="pp-card-title">Wykluczenia w planie ({{ count($tree['negatives']) }})</h2></div>
        @if ($conflicts !== [])
          <ul class="space-y-1 border-b border-line bg-danger-soft px-5 py-3 text-[13px] text-ink">
            @foreach ($conflicts as $conflict)<li>⚠ {{ $conflict['message'] }}</li>@endforeach
          </ul>
        @endif
        @if ($tree['negatives'] === [])
          <x-pp.empty-state title="Brak wykluczeń" icon="check">Dodaj wykluczenia ręcznie albo z propozycji analizy. Penny Pincher sprawdzi, czy nie blokują planowanych słów.</x-pp.empty-state>
        @else
          <ul class="divide-y divide-line">
            @foreach ($tree['negatives'] as $negative)
              @php
                $scope = match ($negative['level']) {
                  'campaign' => $tree['campaigns'][(int) $negative['plan_campaign_id']]['name'] ?? '—',
                  'ad_group' => $groupOptions[(int) $negative['plan_ad_group_id']] ?? '—',
                  default => 'Cały plan',
                };
              @endphp
              <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 text-sm">
                <span><span class="font-medium text-ink">{{ MatchType::notation($negative['phrase'], $negative['match_type']) }}</span> <span class="text-[12.5px] text-ink-mute">· {{ $scope }} · {{ $negative['source'] === 'negative_suggestions' ? 'z propozycji analizy' : 'ręcznie' }}</span></span>
                @if ($canEdit)
                  <form method="post" action="{{ $base }}/negatives/{{ $negative['id'] }}/delete"><x-pp.nonce /><input type="hidden" name="tab" value="negatives"><button class="pp-btn pp-btn-ghost pp-btn-sm text-danger">Usuń</button></form>
                @endif
              </li>
            @endforeach
          </ul>
        @endif
        <p class="border-t border-line px-5 py-3 text-[12.5px] leading-5 text-ink-mute">Zasady Google Ads dla wykluczeń: przybliżone – zapytanie zawiera wszystkie słowa; do wyrażenia – słowa w tej kolejności; ścisłe – dokładnie to zapytanie. Wykluczenia nie obejmują odmian i literówek.</p>
      </section>

      @if ($canEdit)
        <section class="pp-card p-5" aria-labelledby="add-neg">
          <h2 id="add-neg" class="pp-card-title">Dodaj wykluczenia</h2>
          <form method="post" action="{{ $base }}/negatives" class="mt-3 space-y-3" x-data="{ level: 'plan' }">
            <x-pp.nonce /><input type="hidden" name="tab" value="negatives">
            <div class="grid gap-3 sm:grid-cols-2">
              <div><label class="pp-label" for="nl">Poziom</label><select id="nl" name="level" x-model="level" class="pp-input pp-input-sm">@foreach (PlanStatus::LEVELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
              <div><label class="pp-label" for="nm">Dopasowanie</label><select id="nm" name="match_type" class="pp-input pp-input-sm">@foreach (MatchType::LABELS as $value => $label)<option value="{{ $value }}" @selected($value === 'phrase')>{{ $label }}</option>@endforeach</select></div>
              <div x-show="level === 'campaign'" x-cloak class="sm:col-span-2"><label class="pp-label" for="nc2">Kampania</label><select id="nc2" name="plan_campaign_id" class="pp-input pp-input-sm">@foreach ($tree['campaigns'] as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select></div>
              <div x-show="level === 'ad_group'" x-cloak class="sm:col-span-2"><label class="pp-label" for="ng2">Grupa reklam</label><select id="ng2" name="plan_ad_group_id" class="pp-input pp-input-sm">@foreach ($groupOptions as $gid => $glabel)<option value="{{ $gid }}">{{ $glabel }}</option>@endforeach</select></div>
            </div>
            <div><label class="pp-label" for="np">Frazy (jedna w linii)</label><textarea id="np" name="phrases" rows="3" class="pp-input" placeholder="np. darmowy kreator"></textarea></div>
            @if ($negativeSuggestions)
              <details>
                <summary class="cursor-pointer text-[13px] font-semibold text-ink">Propozycje z analizy ({{ count($negativeSuggestions) }})</summary>
                <ul class="mt-2 max-h-72 space-y-1 overflow-y-auto text-[13px]">
                  @foreach ($negativeSuggestions as $i => $suggestion)
                    <li><label class="flex items-start gap-2"><input type="checkbox" name="suggested[]" value="{{ $suggestion['phrase'] }}" class="mt-1 accent-mint"><span><span class="text-ink">{{ $suggestion['phrase'] }}</span> <span class="text-ink-mute">– {{ $suggestion['reason'] }} ({{ Format::money((int) $suggestion['cost_micros'], $project->currency) }})</span></span></label></li>
                  @endforeach
                </ul>
                <p class="mt-1 text-[12px] text-ink-mute">Nic nie jest dodawane automatycznie – zaznacz tylko te, które pasują.</p>
              </details>
            @endif
            <x-pp.button size="sm" icon="plus">Dodaj wykluczenia</x-pp.button>
          </form>
        </section>
      @endif
    </div>
  @elseif ($tab === 'ads')
    <x-pp.alert type="info">Reklamy elastyczne (RSA): 3–15 nagłówków do 30 znaków, 2–4 opisy do 90 znaków. Kontrolujemy limity formalne; zgodność z zasadami reklamowymi Google sprawdza Google przy publikacji. Propozycje tekstów korzystają wyłącznie z faktów z kontekstu biznesowego – nie dopisujemy obietnic, certyfikatów ani wyników.</x-pp.alert>
    @forelse ($groupOptions as $gid => $glabel)
      @php
        $group = null;
        foreach ($tree['campaigns'] as $c) { if (isset($c['groups'][$gid])) { $group = $c['groups'][$gid]; } }
        $ad = $group['ads'][0] ?? null;
        $headlines = array_pad($ad['headlines'] ?? [], 15, '');
        $descriptions = array_pad($ad['descriptions'] ?? [], 4, '');
      @endphp
      <section class="pp-card scroll-mt-24" id="ad-{{ $gid }}" aria-label="Reklama {{ $glabel }}">
        <div class="pp-card-header">
          <h2 class="pp-card-title">{{ $glabel }}</h2>
          @if ($canEdit && ! $ad)
            <form method="post" action="{{ $base }}/groups/{{ $gid }}/ad/suggest"><x-pp.nonce /><input type="hidden" name="tab" value="ads"><x-pp.button variant="secondary" size="sm">Zaproponuj teksty z kontekstu</x-pp.button></form>
          @endif
        </div>
        <form method="post" action="{{ $base }}/groups/{{ $gid }}/ad" class="space-y-4 p-5">
          <x-pp.nonce /><input type="hidden" name="tab" value="ads">
          <fieldset @disabled(! $canEdit) class="space-y-4">
            <div>
              <p class="pp-label">Nagłówki (min. 3, maks. 15 × {{ AdValidator::HEADLINE_MAX }} znaków)</p>
              <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($headlines as $i => $headline)
                  <div x-data="{ v: @js($headline) }" class="relative">
                    <label for="h-{{ $gid }}-{{ $i }}" class="sr-only">Nagłówek {{ $i + 1 }}</label>
                    <input id="h-{{ $gid }}-{{ $i }}" name="headlines[]" x-model="v" value="{{ $headline }}" class="pp-input pp-input-sm pr-12" maxlength="60" placeholder="Nagłówek {{ $i + 1 }}">
                    <span class="pp-num pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-[11px]" x-bind:class="v.length > {{ AdValidator::HEADLINE_MAX }} ? 'text-danger' : 'text-ink-mute'" x-text="v.length + '/{{ AdValidator::HEADLINE_MAX }}'"></span>
                  </div>
                @endforeach
              </div>
            </div>
            <div>
              <p class="pp-label">Opisy (min. 2, maks. 4 × {{ AdValidator::DESCRIPTION_MAX }} znaków)</p>
              <div class="grid gap-2 lg:grid-cols-2">
                @foreach ($descriptions as $i => $description)
                  <div x-data="{ v: @js($description) }">
                    <label for="d-{{ $gid }}-{{ $i }}" class="sr-only">Opis {{ $i + 1 }}</label>
                    <textarea id="d-{{ $gid }}-{{ $i }}" name="descriptions[]" x-model="v" rows="2" class="pp-input" maxlength="180" placeholder="Opis {{ $i + 1 }}">{{ $description }}</textarea>
                    <span class="pp-num text-[11px]" x-bind:class="v.length > {{ AdValidator::DESCRIPTION_MAX }} ? 'text-danger' : 'text-ink-mute'" x-text="v.length + '/{{ AdValidator::DESCRIPTION_MAX }}'"></span>
                  </div>
                @endforeach
              </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div class="xl:col-span-2"><label class="pp-label" for="fu-{{ $gid }}">URL strony docelowej</label><input id="fu-{{ $gid }}" name="final_url" type="url" value="{{ $ad['final_url'] ?? ($group['final_url'] ?: $project->landingPageUrl) }}" class="pp-input pp-input-sm" placeholder="https://"></div>
              <div><label class="pp-label" for="p1-{{ $gid }}">Ścieżka 1 (maks. 15)</label><input id="p1-{{ $gid }}" name="path1" value="{{ $ad['path1'] ?? '' }}" class="pp-input pp-input-sm" maxlength="30"></div>
              <div><label class="pp-label" for="p2-{{ $gid }}">Ścieżka 2 (maks. 15)</label><input id="p2-{{ $gid }}" name="path2" value="{{ $ad['path2'] ?? '' }}" class="pp-input pp-input-sm" maxlength="30"></div>
              <div><label class="pp-label" for="cta-{{ $gid }}">Wezwanie do działania</label><input id="cta-{{ $gid }}" name="cta" value="{{ $ad['cta'] ?? '' }}" list="ctas" class="pp-input pp-input-sm" maxlength="60"></div>
              <div class="sm:col-span-2 xl:col-span-3"><label class="pp-label" for="an-{{ $gid }}">Notatki</label><input id="an-{{ $gid }}" name="notes" value="{{ $ad['notes'] ?? '' }}" class="pp-input pp-input-sm"></div>
            </div>
            @if ($canEdit)<x-pp.button size="sm">Zapisz reklamę</x-pp.button>@endif
          </fieldset>
        </form>
      </section>
    @empty
      <section class="pp-card"><x-pp.empty-state title="Brak grup reklam" icon="planner">Reklamy przygotowujesz dla grup reklam – najpierw dodaj je w zakładce Struktura.</x-pp.empty-state></section>
    @endforelse
    <datalist id="ctas">@foreach (AdTemplates::CTAS as $cta)<option value="{{ $cta }}"></option>@endforeach</datalist>
  @else
    <section class="pp-card" aria-label="Historia planu">
      <div class="pp-card-header"><h2 class="pp-card-title">Historia zmian</h2></div>
      <ol class="divide-y divide-line text-sm">
        @forelse ($events as $event)
          <li class="px-5 py-2.5"><span class="pp-num text-ink-mute">{{ Format::datetime($event['created_at']) }}</span> · {{ ['created' => 'Utworzenie planu', 'updated' => 'Zmiana danych', 'status' => 'Zmiana statusu', 'copied' => 'Utworzenie z kopii', 'budget' => 'Zmiana budżetu/scenariusza', 'budget_applied' => 'Zastosowanie podziału budżetu', 'keywords_added' => 'Dodanie fraz', 'campaign_added' => 'Dodanie kampanii', 'campaign_deleted' => 'Usunięcie kampanii', 'group_added' => 'Dodanie grupy reklam', 'group_deleted' => 'Usunięcie grupy reklam', 'keyword_removed' => 'Usunięcie słowa', 'keyword_moved' => 'Przeniesienie słowa', 'match_type' => 'Zmiana dopasowania', 'negatives_added' => 'Dodanie wykluczeń', 'negative_removed' => 'Usunięcie wykluczenia', 'ad_saved' => 'Zapis reklamy'][$event['event']] ?? $event['event'] }}@if ($event['note']) – {{ $event['note'] }}@endif @if ($event['user_name'])<span class="text-ink-mute">· {{ $event['user_name'] }}</span>@endif</li>
        @empty
          <li class="px-5 py-4 text-ink-mute">Brak zdarzeń.</li>
        @endforelse
      </ol>
    </section>
  @endif
@endsection
