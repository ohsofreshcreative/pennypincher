{{-- Optymalizacja (pp2_optimization): okres → pełna analiza → wyniki reguł, szacunki i rekomendacje; szczegóły w modułach. --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Analysis\Statistics;
  use App\PennyPincher\Domain\Audit\AuditScore;
  use App\PennyPincher\Domain\Audit\Category;
  use App\PennyPincher\Domain\Leaks\Leak;
  use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
  use App\PennyPincher\Support\AnalysisPeriodRequest;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $periodQuery = AnalysisPeriodRequest::query($period);
  $campaignQuery = $campaign ? ['campaign' => $campaign] : [];
  $hasAny = $audit || $leakRun || $keywordRun || $recommendations->total > 0;
  $severities = ['critical' => 0, 'warning' => 0, 'info' => 0];
  foreach ($findings as $f) { $severities[$f['severity']] = ($severities[$f['severity']] ?? 0) + 1; }
  $leakCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
  foreach ($leaks as $l) { $leakCounts[$l['priority']] = ($leakCounts[$l['priority']] ?? 0) + 1; }
  $keywordSummary = $keywordRun['summary'] ?? [];
@endphp

@section('content')
  <section class="pp-card" aria-labelledby="pp-opt-run">
    <div class="flex flex-wrap items-start justify-between gap-4 p-5">
      <div class="min-w-0">
        <h2 id="pp-opt-run" class="pp-card-title">Analiza okresu {{ $period->range->label() }}</h2>
        <p class="mt-1 text-sm text-ink-soft">
          Tryb: <strong class="font-semibold text-ink">pełna analiza</strong> – audyt ({{ count(\App\PennyPincher\Domain\Audit\RuleRegistry::RULES) }} reguł), wycieki budżetu, słowa kluczowe i rekomendacje dla całego konta.
          Analiza czyta wyłącznie dane zapisane w Penny Pincher i niczego nie zmienia w Google Ads. Okres zmienisz w nagłówku strony.
        </p>
      </div>
      @if ($canRun)
        @include('app.partials.analysis.run-form', ['return' => 'optimization', 'label' => $hasAny ? 'Uruchom ponownie' : 'Uruchom analizę okresu'])
      @endif
    </div>

    <x-pp.filter-bar :action="AppUrl::project($project->id, 'optimization')" class="border-t border-b-0">
      @foreach ($periodQuery as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
      @endforeach
      <div class="w-full sm:w-72">
        <label for="f-campaign" class="pp-label">Zakres wyników</label>
        <select id="f-campaign" name="campaign" class="pp-input pp-input-sm">
          <option value="">Całe konto</option>
          @foreach ($campaignOptions as $id => $name)
            <option value="{{ $id }}" @selected($campaign === (int) $id)>{{ $name }}</option>
          @endforeach
        </select>
      </div>
      @if ($campaign)
        <p class="text-caption text-ink-mute">Kampania zawęża wycieki i rekomendacje. Audyt i słowa kluczowe dotyczą całego konta.</p>
      @endif
    </x-pp.filter-bar>
  </section>

  @if (! $hasAny)
    <section class="pp-card">
      <x-pp.empty-state title="Brak analizy dla okresu {{ $period->range->label() }}" icon="idea">
        Po uruchomieniu analizy zobaczysz tu w jednym miejscu wynik audytu, wycieki budżetu, propozycje słów kluczowych i rekomendacje – z linkami do dowodów w każdym module.
        @unless ($canRun) Analizę może uruchomić osoba z uprawnieniem do importu danych. @endunless
      </x-pp.empty-state>
    </section>
  @else
    {{-- Stan modułów dla tego samego okresu --}}
    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" aria-label="Moduły analizy">
      <article class="pp-card flex flex-col p-5" data-module="audit">
        <h3 class="pp-card-title">Audyt</h3>
        @if ($audit)
          <p class="pp-num mt-2 text-[22px] leading-7 font-semibold text-ink">{{ $audit['score'] ?? '—' }}<span class="text-meta font-normal text-ink-mute"> / 100 · {{ AuditScore::label($audit['score']) }}</span></p>
          <p class="pp-num mt-1 text-meta text-ink-soft">{{ $severities['critical'] }} krytycznych · {{ $severities['warning'] }} ostrzeżeń · {{ $severities['info'] }} informacji</p>
          <p class="pp-num mt-1 text-caption text-ink-mute">{{ Format::datetime($audit['created_at']) }} · reguły v{{ $audit['rules_version'] }}</p>
          <a href="{{ AppUrl::project($project->id, 'audits/' . $audit['id']) }}" class="mt-auto pt-3 text-meta font-semibold text-mint hover:underline">Raport audytu</a>
        @else
          <p class="mt-2 text-sm text-ink-soft">Brak audytu tego okresu.</p>
          <a href="{{ AppUrl::project($project->id, 'audits', $periodQuery) }}" class="mt-auto pt-3 text-meta font-semibold text-mint hover:underline">Historia audytów</a>
        @endif
      </article>

      <article class="pp-card flex flex-col p-5" data-module="budget-leaks">
        <h3 class="pp-card-title">Wycieki budżetu</h3>
        @if ($leakRun)
          <p class="pp-num mt-2 text-[22px] leading-7 font-semibold text-ink">{{ count($leaks) }}<span class="text-meta font-normal text-ink-mute"> {{ Format::plural(count($leaks), 'problem', 'problemy', 'problemów') }}</span></p>
          <p class="pp-num mt-1 text-meta text-ink-soft">{{ $leakCounts['high'] }} wysoki · {{ $leakCounts['medium'] }} średni · {{ $leakCounts['low'] }} niski priorytet</p>
          <p class="pp-num mt-1 text-caption text-ink-mute">
            SZACUNEK oszczędności (konto): {{ $savings && $savings['total'] > 0 ? 'ok. ' . Format::money($savings['total'], $currency, 0) . ' / mies.' : 'brak danych' }}
          </p>
          <a href="{{ AppUrl::project($project->id, 'budget-leaks', $periodQuery + $campaignQuery) }}" class="mt-auto pt-3 text-meta font-semibold text-mint hover:underline">Wszystkie wycieki i dowody</a>
        @else
          <p class="mt-2 text-sm text-ink-soft">Brak analizy wycieków tego okresu.</p>
        @endif
      </article>

      <article class="pp-card flex flex-col p-5" data-module="keywords">
        <h3 class="pp-card-title">Słowa kluczowe</h3>
        @if ($keywordRun)
          <p class="pp-num mt-2 text-[22px] leading-7 font-semibold text-ink">{{ (int) ($keywordSummary['opportunities'] ?? 0) }}<span class="text-meta font-normal text-ink-mute"> nowych fraz do oceny</span></p>
          <p class="pp-num mt-1 text-meta text-ink-soft">{{ (int) ($keywordSummary['negatives'] ?? 0) }} propozycji wykluczeń · {{ (int) ($keywordSummary['terms_analyzed'] ?? 0) }} haseł przeanalizowanych</p>
          <p class="mt-1 text-caption text-ink-mute">Propozycje do decyzji, nie stwierdzone problemy.</p>
          <div class="mt-auto flex flex-wrap gap-x-4 pt-3">
            <a href="{{ AppUrl::project($project->id, 'keywords', ['tab' => 'opportunities'] + $periodQuery) }}" class="text-meta font-semibold text-mint hover:underline">Nowe frazy</a>
            <a href="{{ AppUrl::project($project->id, 'keywords', ['tab' => 'negatives'] + $periodQuery) }}" class="text-meta font-semibold text-mint hover:underline">Wykluczenia</a>
          </div>
        @else
          <p class="mt-2 text-sm text-ink-soft">Brak analizy słów kluczowych tego okresu.</p>
        @endif
      </article>

      <article class="pp-card flex flex-col p-5" data-module="recommendations">
        <h3 class="pp-card-title">Rekomendacje</h3>
        <p class="pp-num mt-2 text-[22px] leading-7 font-semibold text-ink">{{ $recommendations->total }}<span class="text-meta font-normal text-ink-mute"> dla tego okresu{{ $campaign ? ' i kampanii' : '' }}</span></p>
        <p class="mt-1 text-caption text-ink-mute">Łączą wyniki audytu, wycieków i słów kluczowych; statusy ustawiasz Ty.</p>
        <a href="{{ AppUrl::project($project->id, 'recommendations', $periodQuery + $campaignQuery) }}" class="mt-auto pt-3 text-meta font-semibold text-mint hover:underline">Wszystkie rekomendacje</a>
      </article>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
      {{-- Twarde wyniki: deterministyczne reguły na zapisanych danych --}}
      <section class="pp-card min-w-0" aria-labelledby="pp-opt-facts">
        <div class="pp-card-header">
          <div>
            <h2 id="pp-opt-facts" class="pp-card-title">Wyniki reguł</h2>
            <p class="mt-0.5 text-meta text-ink-mute">Stwierdzone na zapisanych danych przez stałe reguły – każdy wynik ma dowody w module.</p>
          </div>
        </div>
        @if ($findings === [] && $leaks === [])
          <p class="px-5 py-4 text-sm text-ink-soft">Reguły nie zgłosiły problemów w tym okresie{{ $campaign ? ' dla wybranej kampanii' : '' }}.</p>
        @else
          <ul class="divide-y divide-line">
            @foreach (array_slice($findings, 0, 5) as $finding)
              <li>
                <a href="{{ AppUrl::project($project->id, 'audits/' . $audit['id']) }}#finding-{{ $finding['id'] }}" class="flex items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]" data-fact="finding">
                  <x-pp.status-badge :status="$finding['severity']" class="mt-0.5" />
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-ink">{{ $finding['title'] }}</span>
                    <span class="block text-caption text-ink-mute">Audyt · {{ $finding['rule_id'] }} · {{ Category::LABELS[$finding['category']] ?? $finding['category'] }}</span>
                  </span>
                </a>
              </li>
            @endforeach
            @foreach (array_slice($leaks, 0, 5) as $leak)
              <li>
                <a href="{{ AppUrl::project($project->id, 'budget-leaks', $periodQuery + $campaignQuery) }}#leak-{{ $leak['id'] }}" class="flex items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]" data-fact="leak">
                  <x-pp.status-badge :status="$leak['priority']" :label="Leak::PRIORITIES[$leak['priority']]" class="mt-0.5" />
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-ink">{{ $leak['title'] }}</span>
                    <span class="block text-caption text-ink-mute">Wyciek · {{ Leak::CATEGORIES[$leak['category']] ?? '' }}</span>
                  </span>
                  <span class="pp-num shrink-0 text-sm font-semibold text-copper" title="Koszt w okresie analizy">{{ Format::money($leak['cost_micros'], $currency, 0) }}</span>
                </a>
              </li>
            @endforeach
          </ul>
          @if (count($findings) > 5 || count($leaks) > 5)
            <p class="border-t border-line px-5 py-3 text-caption text-ink-mute">Pokazano po 5 najważniejszych z audytu i wycieków – pełne listy w modułach.</p>
          @endif
        @endif
      </section>

      {{-- Rekomendacje: priorytet, źródło, pewność; wpływ tylko jako oznaczony szacunek --}}
      <section class="pp-card min-w-0" aria-labelledby="pp-opt-recs">
        <div class="pp-card-header">
          <div>
            <h2 id="pp-opt-recs" class="pp-card-title">Rekomendacje do decyzji</h2>
            <p class="mt-0.5 text-meta text-ink-mute">Wnioski z wyników reguł. Pewność wynika z wielkości próby; kwoty wpływu to szacunki.</p>
          </div>
        </div>
        @if ($recommendations->items === [])
          <p class="px-5 py-4 text-sm text-ink-soft">Brak rekomendacji dla tego okresu{{ $campaign ? ' i kampanii' : '' }}.</p>
        @else
          <ul class="divide-y divide-line">
            @foreach ($recommendations->items as $rec)
              <li>
                <a href="{{ AppUrl::project($project->id, 'recommendations', $periodQuery + $campaignQuery + ['open' => $rec['id']]) }}#rec-{{ $rec['id'] }}" class="flex flex-wrap items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]" data-rec>
                  <x-pp.status-badge :status="$rec['priority']" class="mt-0.5" />
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-ink">{{ $rec['title'] }}</span>
                    <span class="block text-caption text-ink-mute">
                      {{ implode(' + ', array_map(fn ($s) => RecommendationGenerator::SOURCES[$s] ?? $s, $rec['sources'] ?? [])) }}
                      · pewność: {{ mb_strtolower(Statistics::CONFIDENCE_LABELS[$rec['confidence']] ?? $rec['confidence']) }}
                      @if ($rec['impact_label']) · SZACUNEK: {{ $rec['impact_label'] }} @endif
                    </span>
                  </span>
                  <x-pp.status-badge :status="$rec['status']" />
                </a>
              </li>
            @endforeach
          </ul>
        @endif
      </section>
    </div>

    <p class="text-caption text-ink-mute">
      Wszystkie wyniki na tej stronie pochodzą z deterministycznych reguł Penny Pincher (te same dane = ten sam wynik). Aplikacja nie korzysta tu z modeli AI;
      hipotezami są wyłącznie propozycje słów kluczowych i szacunki oszczędności – wymagają Twojej oceny.
    </p>
  @endif
@endsection
