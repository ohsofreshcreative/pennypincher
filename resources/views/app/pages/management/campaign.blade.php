@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
  use App\PennyPincher\Domain\Projects\BusinessContext;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $channel = mb_strtolower((string) $campaign['channel_type']);
  $search = str_contains($channel, 'search') || str_contains($channel, 'wyszuk');
  $editable = $canPrepare && $resource !== null && $search && $campaign['status'] !== 'removed';
  $action = AppUrl::project($project->id, 'management/campaigns/' . $campaign['id'] . '/change');
  $networks = $settings['networks'] ?? null;
  $criteriaRows = $criteria['data'] ?? [];
  $critTypes = ['LOCATION' => 'Lokalizacja', 'LANGUAGE' => 'Język', 'KEYWORD' => 'Wykluczenie', 'PROXIMITY' => 'Promień', 'AD_SCHEDULE' => 'Harmonogram', 'DEVICE' => 'Urządzenie'];
@endphp

@section('content')
  <nav class="text-[13px] text-ink-mute" aria-label="Okruszki"><a class="underline" href="{{ AppUrl::project($project->id, 'management') }}">Google Ads Management</a> / kampania</nav>

  <section class="pp-card p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0">
        <h2 class="text-lg font-semibold break-words text-ink">{{ $campaign['name'] }}</h2>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-[13px] text-ink-mute">
          <x-pp.status-badge :status="$campaign['status']" /> {{ $campaign['channel_type'] ?: '—' }}
          @if ($resource)· <span class="break-all">{{ $resource }}</span>@endif
        </p>
      </div>
      @if ($canPrepare && $resource)
        <form method="post" action="{{ AppUrl::project($project->id, 'management/read') }}" data-busy>
          <x-pp.nonce /><input type="hidden" name="kind" value="campaign"><input type="hidden" name="id" value="{{ $campaign['id'] }}">
          <x-pp.button variant="secondary" size="sm" icon="refresh" data-busy-label="Odczyt…">Odczytaj kierowanie z Google Ads</x-pp.button>
        </form>
      @endif
    </div>
    <dl class="mt-4 grid gap-3 text-[13px] sm:grid-cols-2 xl:grid-cols-4">
      <div><dt class="text-ink-mute">Budżet dzienny (synchronizacja)</dt><dd class="pp-num font-semibold">{{ $campaign['budget_micros'] !== null ? Format::money((int) $campaign['budget_micros'], $project->currency) : '—' }}@if (! empty($settings['budget_shared'])) <span class="pp-badge pp-badge-warn">współdzielony</span>@endif</dd></div>
      <div><dt class="text-ink-mute">Strategia stawek</dt><dd class="font-semibold">{{ OperationCatalog::BIDDING[$campaign['bidding_strategy']] ?? ($campaign['bidding_strategy'] ?: '—') }}</dd></div>
      <div><dt class="text-ink-mute">Sieci</dt><dd>@if ($networks)Google{{ $networks['search_partners'] ? ' + partnerzy' : '' }}{{ $networks['display'] ? ' + Display' : '' }}@else — @endif</dd></div>
      <div><dt class="text-ink-mute">{{ $period->range->label() }}</dt><dd class="pp-num">@if ($metrics){{ Format::money($metrics['cost_micros'], $project->currency) }} · {{ Format::number($metrics['clicks']) }} klik. · {{ Format::conversions($metrics['conversions']) }} konw.@else — @endif</dd></div>
    </dl>
  </section>

  @include('app.partials.changes.mode-banner')

  @if (! $resource)
    <x-pp.alert type="warning">Ta kampania pochodzi z importu i nie ma identyfikatora Google Ads – zmiany można przygotować dopiero po synchronizacji konta przez API.</x-pp.alert>
  @elseif (! $search)
    <x-pp.alert type="info">Typ kampanii „{{ $campaign['channel_type'] }}” jest dostępny tylko do odczytu. Penny Pincher obsługuje edycję kampanii w sieci wyszukiwania.</x-pp.alert>
  @endif

  <div class="grid gap-6 xl:grid-cols-2">
    <section class="pp-card min-w-0" aria-label="Grupy reklam">
      <div class="pp-card-header"><h3 class="pp-card-title">Grupy reklam ({{ count($adGroups) }})</h3></div>
      @if ($adGroups === [])
        <p class="px-5 py-4 text-[13px] text-ink-mute">Brak grup reklam w danych.</p>
      @else
        <ul class="divide-y divide-line text-[13px]">
          @foreach ($adGroups as $g)
            <li><a class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 hover:bg-ink/[0.03]" href="{{ AppUrl::project($project->id, 'management/ad-groups/' . $g['id']) }}"><span class="min-w-0 font-semibold break-words text-ink">{{ $g['name'] }}</span><span class="flex items-center gap-2 text-ink-mute"><x-pp.status-badge :status="$g['status']" /> {{ (int) $g['keyword_count'] }} słów</span></a></li>
          @endforeach
        </ul>
      @endif
    </section>

    <section class="pp-card min-w-0" aria-label="Kierowanie i wykluczenia">
      <div class="pp-card-header"><h3 class="pp-card-title">Kierowanie i wykluczenia kampanii</h3>@if ($criteria)<span class="text-[12px] text-ink-mute">odczyt {{ Format::datetime($criteria['read_at']) }}</span>@endif</div>
      @if ($criteria)
        @if ($criteriaRows === [])
          <p class="px-5 py-4 text-[13px] text-ink-mute">Brak kryteriów kampanii (bez lokalizacji = cały świat).</p>
        @else
          <ul class="divide-y divide-line text-[13px]">
            @foreach ($criteriaRows as $cr)
              <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2">
                <span class="min-w-0 break-words">
                  <span class="pp-badge pp-badge-{{ $cr['negative'] ? 'warn' : 'neutral' }}">{{ $cr['negative'] ? 'Wykluczenie' : ($critTypes[$cr['type']] ?? $cr['type']) }}</span>
                  {{ $cr['keyword_text'] ?? $cr['geo_target_constant'] ?? $cr['language_constant'] ?? $cr['criterion_id'] }}
                  @if ($cr['match_type'])<span class="text-ink-mute">({{ OperationCatalog::MATCH_TYPES[$cr['match_type']] ?? $cr['match_type'] }})</span>@endif
                </span>
                @if ($editable && in_array($cr['type'], ['LOCATION', 'LANGUAGE', 'KEYWORD'], true))
                  <form method="post" action="{{ $action }}" data-confirm="Przygotować usunięcie tego kryterium? (szkic w Change Center)">
                    <x-pp.nonce /><input type="hidden" name="action" value="remove_criterion"><input type="hidden" name="resource_name" value="{{ $cr['resource_name'] }}">
                    <x-pp.button variant="ghost" size="sm">Usuń</x-pp.button>
                  </form>
                @endif
              </li>
            @endforeach
          </ul>
        @endif
      @else
        <p class="px-5 py-3 text-[13px] text-ink-mute">Nie odczytano jeszcze kierowania z Google Ads. Wykluczenia z synchronizacji:</p>
        @if ($negatives === [])
          <p class="px-5 pb-4 text-[13px] text-ink-mute">brak.</p>
        @else
          <p class="px-5 pb-4 text-[13px] break-words">{{ implode(', ', array_map(fn ($n) => $n['text'] . ' (' . (OperationCatalog::MATCH_TYPES[strtoupper((string) $n['match_type'])] ?? $n['match_type']) . ')', $negatives)) }}</p>
        @endif
      @endif
    </section>
  </div>

  @if ($editable)
    <section class="pp-card p-5" aria-labelledby="prepare">
      <h3 id="prepare" class="pp-card-title">Przygotuj zmianę</h3>
      <p class="mt-1 text-[13px] text-ink-soft">Każdy formularz dodaje operację do szkicu w Change Center. Nic nie zostanie wysłane do Google Ads.</p>
      <div class="mt-4 grid gap-5 lg:grid-cols-2">
        <form method="post" action="{{ $action }}" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4">
          <x-pp.nonce />
          <p class="font-semibold text-ink">Status</p>
          <div class="flex flex-wrap gap-2">
            @if ($campaign['status'] === 'enabled')
              <x-pp.button variant="secondary" size="sm" name="action" value="pause">Wstrzymaj kampanię</x-pp.button>
            @else
              <x-pp.button variant="secondary" size="sm" name="action" value="enable">Włącz kampanię</x-pp.button>
            @endif
          </div>
          @include('app.partials.changes.draft-select', ['id' => 'f-set-status'])
        </form>

        <form method="post" action="{{ $action }}" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4">
          <x-pp.nonce /><input type="hidden" name="action" value="rename">
          <x-pp.field name="name" label="Nowa nazwa" :value="$campaign['name']" required />
          @include('app.partials.changes.draft-select', ['id' => 'f-set-name'])
          <x-pp.button variant="secondary" size="sm">Przygotuj zmianę nazwy</x-pp.button>
        </form>

        <form method="post" action="{{ $action }}" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4">
          <x-pp.nonce /><input type="hidden" name="action" value="budget">
          <x-pp.field name="amount" label="Nowy budżet dzienny ({{ $project->currency }})" inputmode="decimal" :value="Format::moneyInput($campaign['budget_micros'] !== null ? (int) $campaign['budget_micros'] : null)" required help="Aktualny budżet zostanie odczytany z Google Ads. Podgląd pokaże różnicę i szacunek miesięczny (×30,4)." />
          <label class="flex items-start gap-2 text-[13px]"><input type="checkbox" name="shared_ack" value="1" class="mt-1"> <span>Jeśli budżet jest współdzielony – rozumiem, że zmiana obejmie wszystkie kampanie z tym budżetem.</span></label>
          @include('app.partials.changes.draft-select', ['id' => 'f-set-budget'])
          <x-pp.button variant="secondary" size="sm">Przygotuj zmianę budżetu</x-pp.button>
        </form>

        <form method="post" action="{{ $action }}" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4">
          <x-pp.nonce /><input type="hidden" name="action" value="bidding">
          <x-pp.field name="strategy" label="Strategia stawek" :options="OperationCatalog::BIDDING" :value="$campaign['bidding_strategy']" required />
          <div class="grid gap-3 sm:grid-cols-2">
            <x-pp.field name="target_cpa" label="Docelowy CPA ({{ $project->currency }})" inputmode="decimal" help="Maks. konwersji." />
            <x-pp.field name="ceiling" label="Limit CPC ({{ $project->currency }})" inputmode="decimal" help="Maks. kliknięć." />
          </div>
          <p class="text-[12.5px] text-warn">Zmiana strategii resetuje okres uczenia Smart Bidding – wymaga osobnego potwierdzenia przy zatwierdzeniu.</p>
          @include('app.partials.changes.draft-select', ['id' => 'f-set-bid'])
          <x-pp.button variant="secondary" size="sm">Przygotuj zmianę strategii</x-pp.button>
        </form>

        <form method="post" action="{{ $action }}" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4">
          <x-pp.nonce /><input type="hidden" name="action" value="network">
          <p class="font-semibold text-ink">Sieci</p>
          <label class="flex items-start gap-2 text-[13px]"><input type="checkbox" name="search_partners" value="1" class="mt-1" @checked($networks['search_partners'] ?? false)> <span>Partnerzy wyszukiwania Google</span></label>
          <label class="flex items-start gap-2 text-[13px]"><input type="checkbox" name="display" value="1" class="mt-1" @checked($networks['display'] ?? false)> <span>Sieć reklamowa (Display) – zwykle niezalecane w kampanii w wyszukiwarce</span></label>
          @include('app.partials.changes.draft-select', ['id' => 'f-set-net'])
          <x-pp.button variant="secondary" size="sm">Przygotuj zmianę sieci</x-pp.button>
        </form>

        <div class="space-y-3 rounded-[var(--radius-card)] border border-line p-4">
          <form method="post" action="{{ $action }}" class="flex flex-wrap items-end gap-2">
            <x-pp.nonce /><input type="hidden" name="action" value="location">
            <x-pp.field name="geo_id" label="Dodaj lokalizację (ID geo)" :options="['' => 'Wybierz…'] + BusinessContext::LOCATIONS" required class="min-w-0 flex-1" />
            @include('app.partials.changes.draft-select', ['id' => 'f-set-geo'])
            <x-pp.button variant="secondary" size="sm">Dodaj</x-pp.button>
          </form>
          <form method="post" action="{{ $action }}" class="flex flex-wrap items-end gap-2">
            <x-pp.nonce /><input type="hidden" name="action" value="language">
            <x-pp.field name="language" label="Dodaj język" :options="BusinessContext::LANGUAGES" :value="$project->context()->language()" required class="min-w-0 flex-1" />
            @include('app.partials.changes.draft-select', ['id' => 'f-set-lang'])
            <x-pp.button variant="secondary" size="sm">Dodaj</x-pp.button>
          </form>
        </div>

        <form method="post" action="{{ $action }}" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4">
          <x-pp.nonce /><input type="hidden" name="action" value="negatives">
          <x-pp.field name="phrases" label="Wykluczenia kampanii (jedno w wierszu)" type="textarea" rows="4" required help="Penny Pincher sprawdzi, czy wykluczenie nie zablokuje aktywnych słów kluczowych kampanii." />
          <x-pp.field name="match_type" label="Dopasowanie" :options="OperationCatalog::MATCH_TYPES" value="PHRASE" required />
          @include('app.partials.changes.draft-select', ['id' => 'f-set-neg'])
          <x-pp.button variant="secondary" size="sm">Przygotuj wykluczenia</x-pp.button>
        </form>

        <form method="post" action="{{ $action }}" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4">
          <x-pp.nonce /><input type="hidden" name="action" value="ad_group">
          <x-pp.field name="name" label="Nowa grupa reklam" required />
          <div class="grid gap-3 sm:grid-cols-2">
            <x-pp.field name="cpc" label="Maks. CPC ({{ $project->currency }})" inputmode="decimal" />
            <x-pp.field name="status" label="Status" :options="['ENABLED' => 'Aktywna', 'PAUSED' => 'Wstrzymana']" value="PAUSED" required />
          </div>
          @include('app.partials.changes.draft-select', ['id' => 'f-set-ag'])
          <x-pp.button variant="secondary" size="sm">Przygotuj grupę reklam</x-pp.button>
        </form>
      </div>
    </section>
  @endif
@endsection
