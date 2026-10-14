@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
  use App\PennyPincher\Domain\Projects\BusinessContext;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $isSearch = fn (array $c) => str_contains(mb_strtolower((string) $c['channel_type']), 'search') || str_contains(mb_strtolower((string) $c['channel_type']), 'wyszuk');
  $connected = $connection && ($connection['status'] ?? '') === 'active' && $connection['customer_id'];
@endphp

@section('content')
  <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
    <h2 class="pp-card-title">Google Ads Management</h2>
    <p class="mt-1">Przegląd kampanii, grup reklam, słów kluczowych, wykluczeń i reklam oraz przygotowanie zmian. <strong class="text-ink">Każda akcja tworzy szkic w Change Center</strong> – nic nie trafia do Google Ads bez podglądu, odczytu aktualnego stanu z API, zatwierdzenia i świadomego wdrożenia.</p>
    <p class="mt-1">Edycja: kampanie w sieci wyszukiwania. Pozostałe typy (Performance Max, Display, wideo, zakupowe) są widoczne tylko do odczytu – Penny Pincher nie udaje operacji, których nie obsługuje.</p>
  </section>

  @include('app.partials.changes.mode-banner')

  @unless ($connected)
    <x-pp.alert type="warning" title="Brak połączenia z Google Ads">
      Dane poniżej pochodzą z importu lub wcześniejszej synchronizacji. Aby przygotowywać zmiany, <a class="font-semibold underline" href="{{ AppUrl::project($project->id, 'google-ads') }}">połącz konto Google Ads</a> i zsynchronizuj dane przez API (zasoby z importu CSV nie mają identyfikatorów Google Ads).
    </x-pp.alert>
  @endunless

  <section class="pp-card min-w-0" aria-label="Kampanie">
    <div class="pp-card-header">
      <h2 class="pp-card-title">Kampanie</h2>
      <span class="text-[12.5px] text-ink-mute">Metryki: {{ $period->range->label() }}</span>
    </div>
    @if ($campaigns === [])
      <x-pp.empty-state title="Brak kampanii" icon="campaigns">Zsynchronizuj konto Google Ads albo zaimportuj dane.</x-pp.empty-state>
    @else
      <div class="pp-table-wrap">
        <table class="pp-table">
          <thead>
            <tr>
              <th class="pp-col-sticky">Kampania</th><th>Status</th><th>Typ</th><th class="pp-col-num">Budżet / dzień</th><th>Stawki</th>
              <th class="pp-col-num">Koszt</th><th class="pp-col-num">Kliknięcia</th><th class="pp-col-num">Konwersje</th><th>Edycja</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($campaigns as $c)
              @php $m = $metrics[$c['id']] ?? null; $search = $isSearch($c); @endphp
              <tr>
                <td class="pp-col-sticky max-w-[18rem]">
                  <a class="font-semibold break-words text-ink underline-offset-2 hover:underline" href="{{ AppUrl::project($project->id, 'management/campaigns/' . $c['id']) }}">{{ $c['name'] }}</a>
                  <span class="block text-[12px] text-ink-mute">{{ (int) $c['ad_group_count'] }} {{ Format::plural((int) $c['ad_group_count'], 'grupa', 'grupy', 'grup') }}</span>
                </td>
                <td><x-pp.status-badge :status="$c['status']" /></td>
                <td class="text-[13px] whitespace-nowrap">{{ $c['channel_type'] ?: '—' }}</td>
                <td class="pp-col-num">{{ $c['budget_micros'] !== null ? Format::money((int) $c['budget_micros'], $project->currency) : '—' }}</td>
                <td class="text-[13px] whitespace-nowrap">{{ OperationCatalog::BIDDING[$c['bidding_strategy']] ?? ($c['bidding_strategy'] ?: '—') }}</td>
                <td class="pp-col-num">{{ $m ? Format::money($m['cost_micros'], $project->currency) : '—' }}</td>
                <td class="pp-col-num">{{ $m ? Format::number($m['clicks']) : '—' }}</td>
                <td class="pp-col-num">{{ $m ? Format::conversions($m['conversions']) : '—' }}</td>
                <td class="text-[12.5px] whitespace-nowrap">
                  @if (! $c['external_id'])<span class="text-ink-mute">import – brak ID</span>
                  @elseif (! $search)<span class="text-ink-mute">tylko odczyt</span>
                  @else<span class="text-mint-dark">wyszukiwanie</span>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>

  <div class="grid gap-6 xl:grid-cols-2">
    @if ($canPrepare)
      <section class="pp-card p-5" aria-labelledby="new-campaign">
        <h2 id="new-campaign" class="pp-card-title">Nowa kampania w sieci wyszukiwania</h2>
        <p class="mt-1 text-[13px] text-ink-soft">Kampania powstanie jako <strong>wstrzymana</strong> razem z własnym (niewspółdzielonym) budżetem, lokalizacją i językiem – w jednej niepodzielnej operacji. Grupy reklam, słowa i reklamy dodasz potem albo wdrożysz cały plan z Campaign Plannera.</p>
        <form method="post" action="{{ AppUrl::project($project->id, 'management/campaigns/new') }}" class="mt-3 grid gap-4 sm:grid-cols-2">
          <x-pp.nonce />
          <x-pp.field name="name" label="Nazwa kampanii" required class="sm:col-span-2" />
          <x-pp.field name="budget" label="Budżet dzienny ({{ $project->currency }})" inputmode="decimal" required help="Miesięcznie maks. ok. 30,4 × budżet dzienny." />
          <x-pp.field name="bidding" label="Strategia stawek" :options="OperationCatalog::BIDDING" value="MANUAL_CPC" required />
          <x-pp.field name="target_cpa" label="Docelowy CPA ({{ $project->currency }})" inputmode="decimal" help="Tylko dla maksymalizacji konwersji." />
          <x-pp.field name="geo_ids" label="Lokalizacje (ID geo Google Ads)" :value="'2616'" help="Np. 2616 = Polska. Kilka – po przecinku." required />
          <x-pp.field name="languages[]" label="Język" :options="BusinessContext::LANGUAGES" :value="$project->context()->language()" required />
          <x-pp.field name="presence" label="Kierowanie na lokalizację" :options="['presence' => 'Osoby w lokalizacji (zalecane)', 'interest' => 'Osoby w lokalizacji lub nią zainteresowane']" value="presence" required />
          <x-pp.field name="eu_political" label="Reklamy polityczne w UE" :options="['' => 'Wybierz…', 'no' => 'Nie zawiera reklam politycznych', 'yes' => 'Zawiera reklamy polityczne']" required help="Wymagane przez Google Ads przy tworzeniu kampanii." />
          <label class="flex items-start gap-2 text-[13px] sm:col-span-2"><input type="checkbox" name="search_partners" value="1" class="mt-1"> <span>Partnerzy wyszukiwania Google (domyślnie wyłączone)</span></label>
          @include('app.partials.changes.draft-select', ['id' => 'f-new-set'])
          <div class="sm:col-span-2"><x-pp.button icon="plus">Przygotuj kampanię (szkic)</x-pp.button></div>
        </form>
      </section>
    @endif

    <section class="pp-card p-5 text-[13px] leading-6" aria-labelledby="supported">
      <h2 id="supported" class="pp-card-title">Co można przygotować</h2>
      <ul class="mt-2 list-disc space-y-1 pl-5 text-ink-soft">
        <li><strong class="text-ink">Kampanie (wyszukiwanie):</strong> utworzenie (wstrzymana), nazwa, wstrzymanie/włączenie, budżet dzienny (z ostrzeżeniem o budżecie współdzielonym), sieci, strategia stawek (ręczny CPC, maks. kliknięć, maks. konwersji ± docelowy CPA), lokalizacje, języki, wykluczenia.</li>
        <li><strong class="text-ink">Grupy reklam:</strong> utworzenie, nazwa, wstrzymanie/włączenie, domyślny maks. CPC.</li>
        <li><strong class="text-ink">Słowa kluczowe:</strong> dodanie (ścisłe / do wyrażenia / przybliżone) z kontrolą duplikatów i konfliktów z wykluczeniami, wstrzymanie/włączenie, usunięcie (z potwierdzeniem), zmiana dopasowania jako zamiana (nowe + usunięcie starego w jednej niepodzielnej operacji).</li>
        <li><strong class="text-ink">Wykluczenia:</strong> na poziomie kampanii i grupy, z kontrolą blokowania aktywnych słów.</li>
        <li><strong class="text-ink">Reklamy RSA:</strong> nowa reklama, „nowa wersja” (nowa reklama + wstrzymanie starej – treść reklamy jest w Google Ads niezmienna), wstrzymanie/włączenie, usunięcie (nieodwracalne).</li>
      </ul>
      <h3 class="mt-3 font-semibold text-ink">Niedostępne (celowo)</h3>
      <ul class="mt-1 list-disc space-y-1 pl-5 text-ink-mute">
        <li>edycja Performance Max, Display, wideo, zakupowych i aplikacji (tylko odczyt),</li>
        <li>budżety współdzielone – tworzenie i przepinanie kampanii, strategie portfelowe, harmonogramy, stawki urządzeń, rozszerzenia (zasoby),</li>
        <li>zmiany na stronie docelowej i UX – to nie są operacje Google Ads.</li>
      </ul>
    </section>
  </div>

  @include('app.partials.changes.write-access')
@endsection
