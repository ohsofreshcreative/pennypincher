@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $channel = mb_strtolower((string) $group['channel_type']);
  $search = str_contains($channel, 'search') || str_contains($channel, 'wyszuk');
  $editable = $canPrepare && $resource !== null && $search && $group['status'] !== 'removed';
  $action = AppUrl::project($project->id, 'management/ad-groups/' . $group['id'] . '/change');
  $adRows = $ads['data'] ?? [];
  $negativeRows = array_values(array_filter($criteria['data'] ?? [], fn ($c) => $c['negative']));
  $currency = $project->currency;
@endphp

@section('content')
  <nav class="text-[13px] text-ink-mute" aria-label="Okruszki">
    <a class="underline" href="{{ AppUrl::project($project->id, 'management') }}">Google Ads Management</a> /
    <a class="underline" href="{{ AppUrl::project($project->id, 'management/campaigns/' . $group['campaign_local_id']) }}">{{ $group['campaign_name'] }}</a> / grupa reklam
  </nav>

  <section class="pp-card p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0">
        <h2 class="text-lg font-semibold break-words text-ink">{{ $group['name'] }}</h2>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-[13px] text-ink-mute"><x-pp.status-badge :status="$group['status']" /> @if ($resource)<span class="break-all">{{ $resource }}</span>@endif</p>
      </div>
      @if ($canPrepare && $resource)
        <form method="post" action="{{ AppUrl::project($project->id, 'management/read') }}" data-busy>
          <x-pp.nonce /><input type="hidden" name="kind" value="ad_group"><input type="hidden" name="id" value="{{ $group['id'] }}">
          <x-pp.button variant="secondary" size="sm" icon="refresh" data-busy-label="Odczyt…">Odczytaj reklamy i wykluczenia z Google Ads</x-pp.button>
        </form>
      @endif
    </div>
  </section>

  @include('app.partials.changes.mode-banner')

  @if (! $resource)
    <x-pp.alert type="warning">Ta grupa pochodzi z importu i nie ma identyfikatora Google Ads – zmiany można przygotować po synchronizacji konta przez API.</x-pp.alert>
  @elseif (! $search)
    <x-pp.alert type="info">Grupa należy do kampanii typu „{{ $group['channel_type'] }}” – tylko do odczytu.</x-pp.alert>
  @endif

  @if ($editable)
    <section class="pp-card p-5" aria-label="Ustawienia grupy">
      <div class="grid gap-4 lg:grid-cols-3">
        <form method="post" action="{{ $action }}" class="space-y-3">
          <x-pp.nonce />
          <p class="pp-label">Status grupy</p>
          @if ($group['status'] === 'enabled')
            <x-pp.button variant="secondary" size="sm" name="action" value="pause">Wstrzymaj grupę</x-pp.button>
          @else
            <x-pp.button variant="secondary" size="sm" name="action" value="enable">Przygotuj włączenie grupy</x-pp.button>
            @unless ($servingAllowed ?? false)<span class="text-[12.5px] text-ink-mute" title="Zmianę można przygotować i zatwierdzić; wdrożenie czeka na zdjęcie blokady emisji.">wdrożenie zablokowane (blokada emisji)</span>@endunless
          @endif
          @include('app.partials.changes.draft-select', ['id' => 'f-set-gs'])
        </form>
        <form method="post" action="{{ $action }}" class="space-y-3">
          <x-pp.nonce /><input type="hidden" name="action" value="rename">
          <x-pp.field name="name" label="Nazwa grupy" :value="$group['name']" required />
          <x-pp.button variant="secondary" size="sm">Przygotuj zmianę nazwy</x-pp.button>
        </form>
        <form method="post" action="{{ $action }}" class="space-y-3">
          <x-pp.nonce /><input type="hidden" name="action" value="cpc">
          <x-pp.field name="cpc" label="Domyślny maks. CPC ({{ $currency }})" inputmode="decimal" required help="Działa przy ręcznym CPC." />
          <x-pp.button variant="secondary" size="sm">Przygotuj zmianę CPC</x-pp.button>
        </form>
      </div>
    </section>
  @endif

  <section class="pp-card min-w-0" aria-label="Słowa kluczowe">
    <div class="pp-card-header"><h3 class="pp-card-title">Słowa kluczowe ({{ count($keywords) }})</h3><span class="text-[12.5px] text-ink-mute">{{ $period->range->label() }}</span></div>
    @if ($keywords === [])
      <p class="px-5 py-4 text-[13px] text-ink-mute">Brak słów kluczowych w danych.</p>
    @else
      <div class="pp-table-wrap">
        <table class="pp-table">
          <thead><tr><th class="pp-col-sticky">Słowo</th><th>Dopasowanie</th><th>Status</th><th class="pp-col-num">Koszt</th><th class="pp-col-num">Kliknięcia</th><th class="pp-col-num">Konw.</th>@if ($editable)<th>Zmiana</th>@endif</tr></thead>
          <tbody>
            @foreach ($keywords as $k)
              @php $st = $stats[$k['id']] ?? null; $match = strtoupper((string) $k['match_type']); @endphp
              <tr>
                <td class="pp-col-sticky max-w-[16rem] break-words">{{ $k['text'] }}@if (! $k['external_id'])<span class="block text-[12px] text-ink-mute">import – brak ID</span>@endif</td>
                <td class="whitespace-nowrap">{{ OperationCatalog::MATCH_TYPES[$match] ?? $k['match_type'] }}</td>
                <td><x-pp.status-badge :status="$k['status']" /></td>
                <td class="pp-col-num">{{ $st ? Format::money((int) $st['cost_micros'], $currency) : '—' }}</td>
                <td class="pp-col-num">{{ $st ? Format::number($st['clicks']) : '—' }}</td>
                <td class="pp-col-num">{{ $st ? Format::conversions($st['conversions']) : '—' }}</td>
                @if ($editable)
                  <td class="min-w-[12rem]">
                    @if ($k['external_id'] && $k['status'] !== 'removed')
                      <details>
                        <summary class="cursor-pointer text-[13px] font-semibold text-mint">Przygotuj…</summary>
                        <div class="mt-2 space-y-2">
                          <form method="post" action="{{ $action }}">
                            <x-pp.nonce /><input type="hidden" name="keyword" value="{{ $k['id'] }}">
                            <x-pp.button variant="secondary" size="sm" name="action" :value="$k['status'] === 'enabled' ? 'keyword_pause' : 'keyword_enable'">{{ $k['status'] === 'enabled' ? 'Wstrzymaj' : 'Włącz' }}</x-pp.button>
                          </form>
                          <form method="post" action="{{ $action }}" class="flex flex-wrap items-center gap-2">
                            <x-pp.nonce /><input type="hidden" name="action" value="keyword_match"><input type="hidden" name="keyword" value="{{ $k['id'] }}">
                            <label class="sr-only" for="km-{{ $k['id'] }}">Nowe dopasowanie</label>
                            <select id="km-{{ $k['id'] }}" name="match_type" class="pp-input pp-input-sm w-36">
                              @foreach (OperationCatalog::MATCH_TYPES as $mt => $ml)@if ($mt !== $match)<option value="{{ $mt }}">{{ $ml }}</option>@endif @endforeach
                            </select>
                            <x-pp.button variant="ghost" size="sm" title="Zmiana dopasowania = nowe słowo + usunięcie starego w jednej niepodzielnej operacji">Zamień dopasowanie</x-pp.button>
                          </form>
                          <form method="post" action="{{ $action }}" class="flex flex-wrap items-center gap-2">
                            <x-pp.nonce /><input type="hidden" name="action" value="keyword_remove"><input type="hidden" name="keyword" value="{{ $k['id'] }}">
                            <label class="flex items-center gap-1 text-[12.5px]"><input type="checkbox" name="confirm_remove" value="1" required> potwierdzam usunięcie</label>
                            <x-pp.button variant="ghost" size="sm">Usuń</x-pp.button>
                          </form>
                        </div>
                      </details>
                    @else
                      <span class="text-[12.5px] text-ink-mute">—</span>
                    @endif
                  </td>
                @endif
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>

  <div class="grid gap-6 xl:grid-cols-2">
    @if ($editable)
      <section class="pp-card p-5" aria-labelledby="add-kw">
        <h3 id="add-kw" class="pp-card-title">Dodaj słowa kluczowe</h3>
        <form method="post" action="{{ $action }}" class="mt-3 space-y-3">
          <x-pp.nonce /><input type="hidden" name="action" value="keywords">
          <x-pp.field name="phrases" label="Słowa (jedno w wierszu, maks. 80 znaków i 10 słów)" type="textarea" rows="5" required help="Penny Pincher odrzuci duplikaty i ostrzeże o konfliktach z wykluczeniami." />
          <div class="grid gap-3 sm:grid-cols-2">
            <x-pp.field name="match_type" label="Dopasowanie" :options="OperationCatalog::MATCH_TYPES" value="PHRASE" required />
            <x-pp.field name="status" label="Status po dodaniu" :options="['ENABLED' => 'Aktywne', 'PAUSED' => 'Wstrzymane']" value="ENABLED" required />
          </div>
          @include('app.partials.changes.draft-select', ['id' => 'f-set-kw'])
          <x-pp.button variant="secondary" size="sm">Przygotuj słowa</x-pp.button>
        </form>
      </section>
    @endif

    <section class="pp-card min-w-0" aria-label="Wykluczenia grupy">
      <div class="pp-card-header"><h3 class="pp-card-title">Wykluczenia grupy</h3>@if ($criteria)<span class="text-[12px] text-ink-mute">odczyt {{ Format::datetime($criteria['read_at']) }}</span>@endif</div>
      @php $rows = $criteria ? $negativeRows : array_map(fn ($n) => ['keyword_text' => $n['text'], 'match_type' => strtoupper((string) $n['match_type']), 'resource_name' => null], $negatives); @endphp
      @if ($rows === [])
        <p class="px-5 py-3 text-[13px] text-ink-mute">Brak wykluczeń na poziomie grupy{{ $criteria ? '' : ' (dane z synchronizacji – odczytaj z Google Ads, aby móc je usuwać)' }}.</p>
      @else
        <ul class="divide-y divide-line text-[13px]">
          @foreach ($rows as $n)
            <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2">
              <span class="min-w-0 break-words">{{ $n['keyword_text'] }} <span class="text-ink-mute">({{ OperationCatalog::MATCH_TYPES[$n['match_type']] ?? $n['match_type'] }})</span></span>
              @if ($editable && $n['resource_name'])
                <form method="post" action="{{ $action }}" data-confirm="Przygotować usunięcie tego wykluczenia?">
                  <x-pp.nonce /><input type="hidden" name="action" value="remove_criterion"><input type="hidden" name="resource_name" value="{{ $n['resource_name'] }}">
                  <x-pp.button variant="ghost" size="sm">Usuń</x-pp.button>
                </form>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
      @if ($editable)
        <form method="post" action="{{ $action }}" class="space-y-3 border-t border-line p-5">
          <x-pp.nonce /><input type="hidden" name="action" value="negatives">
          <x-pp.field name="phrases" label="Nowe wykluczenia (jedno w wierszu)" type="textarea" rows="3" required />
          <x-pp.field name="match_type" label="Dopasowanie" :options="OperationCatalog::MATCH_TYPES" value="PHRASE" required />
          <x-pp.button variant="secondary" size="sm">Przygotuj wykluczenia</x-pp.button>
        </form>
      @endif
    </section>
  </div>

  <section class="pp-card min-w-0" aria-label="Reklamy">
    <div class="pp-card-header"><h3 class="pp-card-title">Reklamy</h3>@if ($ads)<span class="text-[12px] text-ink-mute">odczyt {{ Format::datetime($ads['read_at']) }}</span>@endif</div>
    @if (! $ads)
      <p class="px-5 py-4 text-[13px] text-ink-mute">Reklamy nie są synchronizowane – kliknij „Odczytaj reklamy i wykluczenia z Google Ads”.</p>
    @elseif ($adRows === [])
      <p class="px-5 py-4 text-[13px] text-ink-mute">Brak reklam w tej grupie.</p>
    @else
      <ul class="divide-y divide-line text-[13px]">
        @foreach ($adRows as $ad)
          <li class="px-5 py-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2"><x-pp.status-badge :status="strtolower((string) $ad['status'])" /> <span class="text-ink-mute">{{ $ad['ad_type'] }} · {{ $ad['approval_status'] ?? '—' }}</span></p>
                @if ($ad['headlines'])<p class="mt-1 font-semibold break-words text-ink">{{ implode(' | ', array_slice($ad['headlines'], 0, 3)) }}</p>@endif
                @if ($ad['descriptions'])<p class="break-words text-ink-soft">{{ $ad['descriptions'][0] }}</p>@endif
                <p class="break-all text-ink-mute">{{ $ad['final_urls'][0] ?? '' }}</p>
              </div>
              @if ($editable && $ad['status'] !== 'REMOVED')
                <form method="post" action="{{ $action }}">
                  <x-pp.nonce /><input type="hidden" name="resource_name" value="{{ $ad['resource_name'] }}">
                  <x-pp.button variant="ghost" size="sm" name="action" :value="$ad['status'] === 'ENABLED' ? 'ad_pause' : 'ad_enable'">{{ $ad['status'] === 'ENABLED' ? 'Przygotuj wstrzymanie' : 'Przygotuj włączenie' }}</x-pp.button>
                </form>
                <form method="post" action="{{ $action }}" data-confirm="Przygotować usunięcie reklamy? Usunięcie jest nieodwracalne – zamiast tego możesz ją wstrzymać. Nic nie zostanie wysłane bez zatwierdzenia i wdrożenia.">
                  <x-pp.nonce /><input type="hidden" name="resource_name" value="{{ $ad['resource_name'] }}">
                  <x-pp.button variant="ghost" size="sm" name="action" value="ad_remove">Przygotuj usunięcie</x-pp.button>
                </form>
              @endif
            </div>
          </li>
        @endforeach
      </ul>
    @endif

    @if ($editable)
      <form method="post" action="{{ $action }}" class="grid gap-3 border-t border-line p-5 sm:grid-cols-2">
        <x-pp.nonce /><input type="hidden" name="action" value="ad">
        <p class="font-semibold text-ink sm:col-span-2">Nowa elastyczna reklama w wyszukiwarce (RSA)</p>
        <p class="text-[12.5px] text-ink-soft sm:col-span-2">Wymagania: 3–15 nagłówków (maks. 30 znaków), 2–4 opisy (maks. 90 znaków), adres strony docelowej https, ścieżki maks. 15 znaków. Treści reklamy w Google Ads nie da się edytować w miejscu – „nowa wersja” tworzy nową reklamę i wstrzymuje poprzednią w jednej operacji.</p>
        <x-pp.field name="headlines" label="Nagłówki – jeden w wierszu" type="textarea" rows="6" required />
        <x-pp.field name="descriptions" label="Opisy – jeden w wierszu" type="textarea" rows="6" required />
        <x-pp.field name="final_url" label="Strona docelowa" type="url" :value="$project->landingPageUrl ?: $project->websiteUrl" required class="sm:col-span-2" />
        <x-pp.field name="path1" label="Ścieżka 1" maxlength="15" />
        <x-pp.field name="path2" label="Ścieżka 2" maxlength="15" />
        <x-pp.field name="status" label="Status" :options="['PAUSED' => 'Wstrzymana', 'ENABLED' => 'Aktywna']" value="PAUSED" required />
        @if ($adRows !== [])
          <x-pp.field name="replace" label="Nowa wersja reklamy (wstrzymaj starą)" :options="['' => 'Nie – dodaj obok'] + collect($adRows)->filter(fn ($a) => $a['status'] === 'ENABLED')->mapWithKeys(fn ($a) => [$a['resource_name'] => \Illuminate\Support\Str::limit(implode(' | ', array_slice($a['headlines'], 0, 2)) ?: $a['ad_id'], 60)])->all()" />
        @endif
        @include('app.partials.changes.draft-select', ['id' => 'f-set-ad'])
        <div class="sm:col-span-2"><x-pp.button variant="secondary" size="sm">Przygotuj reklamę</x-pp.button></div>
      </form>
    @endif
  </section>
@endsection
