@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Conversions\ConversionClass;
  use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $actions = $overview['actions'];
  $issues = $overview['issues'];
  $base = rtrim(AppUrl::project($project->id, 'conversion-management'), '/');
  $severity = ['critical' => ['danger', 'Krytyczne'], 'warning' => ['warn', 'Ostrzeżenie'], 'info' => ['info', 'Informacja']];
  $yesNo = fn ($v) => $v === null ? '—' : ($v ? 'Tak' : 'Nie');
  $customerGoals = $overview['goals']['customer'] ?? null;
  $campaignGoals = $overview['goals']['campaigns'] ?? null;
@endphp

@section('content')
  <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="max-w-3xl">
        <h2 class="pp-card-title">Conversion Management</h2>
        <p class="mt-1">Konfiguracja konwersji w Google Ads (typ, status, główna/dodatkowa, zliczanie, okna, wartość) obok <strong class="text-ink">klasyfikacji Penny Pincher</strong>. Klasyfikacja w <a class="font-semibold text-mint underline" href="{{ AppUrl::project($project->id, 'conversions') }}">Konwersjach</a> służy tylko analizie i nigdy nie zmienia Google Ads. Zmiany konfiguracji Google Ads przygotowujesz tutaj – trafiają do Change Center.</p>
      </div>
      @if ($canPrepare && $overview['connected'])
        <form method="post" action="{{ $base }}/read" data-busy>
          <x-pp.nonce />
          <x-pp.button variant="secondary" icon="refresh" data-busy-label="Odczyt…">Odczytaj konwersje i cele z Google Ads</x-pp.button>
        </form>
      @endif
    </div>
    <p class="mt-2 text-ink-mute">Odczyt z Google Ads: {{ $overview['api_read_at'] ? Format::datetime($overview['api_read_at']) : 'jeszcze nie wykonano (pola poniżej z synchronizacji)' }}.</p>
  </section>

  @include('app.partials.changes.mode-banner')

  @unless ($overview['connected'])
    <x-pp.alert type="warning">Projekt nie ma aktywnego połączenia z Google Ads – widzisz dane z importu. Zmiany konfiguracji wymagają połączonego konta i synchronizacji przez API.</x-pp.alert>
  @endunless

  {{-- Health Check --}}
  <section class="pp-card min-w-0" aria-labelledby="health">
    <div class="pp-card-header">
      <h3 id="health" class="pp-card-title flex items-center gap-2"><x-pp.icon name="target" /> Conversion Health Check</h3>
      <span class="text-[12.5px] text-ink-mute">Pomiar leadów: {{ $overview['measurement']['reliable'] ? 'wiarygodny' : 'niewiarygodny' }}</span>
    </div>
    @if ($issues === [])
      <p class="px-5 py-4 text-[13px] text-ink-soft">Nie wykryto problemów z konfiguracją konwersji. {{ $overview['api_read_at'] ? '' : 'Odczytaj konfigurację z Google Ads, aby sprawdzić też cele konta i kampanii.' }}</p>
    @else
      <ol class="divide-y divide-line">
        @foreach ($issues as $issue)
          <li class="px-5 py-4 text-[13px] leading-6">
            <p class="flex flex-wrap items-center gap-2">
              <span class="pp-badge pp-badge-{{ $severity[$issue['severity']][0] ?? 'neutral' }}">{{ $severity[$issue['severity']][1] ?? $issue['severity'] }}</span>
              <span class="font-semibold break-words text-ink">{{ $issue['title'] }}</span>
            </p>
            <p class="mt-1 text-ink-soft">{{ $issue['why'] }}</p>
            <p class="mt-1"><strong class="text-ink">Propozycja:</strong> {{ $issue['proposal'] }}</p>
            @if (! empty($issue['campaigns']))
              <p class="mt-1 text-ink-mute">Kampanie, których to dotyczy: {{ implode(', ', array_map(fn ($c) => $c['name'] . (isset($c['conversions']) ? ' (' . Format::conversions($c['conversions']) . ' konw.)' : ''), array_slice($issue['campaigns'], 0, 8))) }}{{ count($issue['campaigns']) > 8 ? '…' : '' }}</p>
            @endif
            @if (! empty($issue['bidding']))
              <p class="mt-1 text-warn">Zmiana celu wpływa na automatyczne strategie stawek – w Change Center wymaga osobnego potwierdzenia.</p>
            @endif
            <div class="mt-2">
              @if ($issue['operations'] !== [] && $canPrepare)
                <form method="post" action="{{ $base }}/health" data-busy>
                  <x-pp.nonce /><input type="hidden" name="issue" value="{{ $issue['key'] }}">
                  <x-pp.button variant="secondary" size="sm" icon="changes">Przygotuj zmianę</x-pp.button>
                </form>
              @elseif ($issue['not_editable'])
                <p class="text-ink-mute"><x-pp.icon name="lock" :size="14" class="inline" /> {{ $issue['not_editable'] }}</p>
              @endif
            </div>
          </li>
        @endforeach
      </ol>
    @endif
  </section>

  {{-- Lista działań --}}
  <section class="pp-card min-w-0" aria-label="Działania konwersji">
    <div class="pp-card-header"><h3 class="pp-card-title">Działania konwersji ({{ count($actions) }})</h3><span class="text-[12.5px] text-ink-mute">Konwersje: ostatnie 90 dni</span></div>
    @if ($actions === [])
      <x-pp.empty-state title="Brak działań konwersji" icon="target">Zsynchronizuj konto Google Ads albo zaimportuj raport konwersji.</x-pp.empty-state>
    @else
      <div class="pp-table-wrap">
        <table class="pp-table">
          <thead>
            <tr>
              <th class="pp-col-sticky">Działanie</th><th>Typ / źródło</th><th>Status</th><th>Główna</th><th>Zliczanie</th><th>Okna (klik/wyśw.)</th><th>Wartość</th>
              <th>Klasyfikacja PP</th><th class="pp-col-num">Konw. 90 dni</th><th>Edycja przez API</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($actions as $a)
              <tr>
                <td class="pp-col-sticky max-w-[16rem] break-words"><span class="font-semibold text-ink">{{ $a['name'] }}</span>
                  @if ($a['campaigns'] !== [])<span class="block text-[12px] text-ink-mute">{{ count($a['campaigns']) }} {{ Format::plural(count($a['campaigns']), 'kampania', 'kampanie', 'kampanii') }}</span>@endif</td>
                <td class="text-[12.5px] whitespace-nowrap">{{ $a['type'] ?: '—' }}<span class="block text-ink-mute">{{ OperationCatalog::CONVERSION_CATEGORIES[$a['category']] ?? ($a['category'] ?: '—') }}{{ $a['origin'] ? ' · ' . $a['origin'] : '' }}</span></td>
                <td class="text-[12.5px]">{{ $a['status'] ?: '—' }}</td>
                <td>{{ $yesNo($a['primary_for_goal']) }}</td>
                <td class="text-[12.5px] whitespace-nowrap">{{ ['ONE_PER_CLICK' => 'Jedna', 'MANY_PER_CLICK' => 'Każda'][$a['counting_type']] ?? ($a['counting_type'] ?: '—') }}</td>
                <td class="pp-num text-[12.5px] whitespace-nowrap">{{ $a['click_window'] ?? '—' }} / {{ $a['view_window'] ?? '—' }}</td>
                <td class="pp-num text-[12.5px] whitespace-nowrap">{{ $a['default_value'] !== null ? Format::number($a['default_value'], 2) : '—' }}{{ $a['always_use_default_value'] ? ' (zawsze)' : '' }}</td>
                <td class="text-[12.5px] whitespace-nowrap">{{ ConversionClass::tryFrom($a['effective_class'])?->label() ?? '—' }}<span class="block text-ink-mute">{{ $a['effective_goal'] ? 'cel biznesowy' : 'nie cel' }} · {{ $a['classification_source'] === 'manual' ? 'ręcznie' : 'sugestia' }}</span></td>
                <td class="pp-col-num">{{ $a['conversions_90'] !== null ? Format::conversions($a['conversions_90']) : '—' }}</td>
                <td class="min-w-[10rem] text-[12.5px]">
                  @if ($a['editable'] === [])
                    <span class="text-ink-mute">{{ $a['external_id'] ? 'tylko odczyt (' . ($a['api'] ? 'typ systemowy lub zarządzany poza kontem' : 'odczytaj z Google Ads') . ')' : 'brak ID Google Ads' }}</span>
                  @elseif ($canPrepare)
                    <details>
                      <summary class="cursor-pointer font-semibold text-mint">Przygotuj zmianę</summary>
                      <form method="post" action="{{ $base }}/{{ $a['id'] }}/update" class="mt-2 w-64 space-y-2">
                        <x-pp.nonce />
                        @if (in_array('primary_for_goal', $a['editable'], true))
                          <label class="pp-label" for="pg-{{ $a['id'] }}">Konwersja główna</label>
                          <select id="pg-{{ $a['id'] }}" name="primary_for_goal" class="pp-input pp-input-sm"><option value="__keep">bez zmian</option><option value="1">główna</option><option value="0">dodatkowa</option></select>
                          <label class="flex items-start gap-2"><input type="checkbox" name="confirm_goal_change" value="1" class="mt-1"> <span>Rozumiem wpływ na Smart Bidding</span></label>
                        @endif
                        @if (in_array('counting_type', $a['editable'], true))
                          <label class="pp-label" for="ct-{{ $a['id'] }}">Zliczanie</label>
                          <select id="ct-{{ $a['id'] }}" name="counting_type" class="pp-input pp-input-sm"><option value="__keep">bez zmian</option>@foreach (OperationCatalog::COUNTING as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        @endif
                        @if (in_array('name', $a['editable'], true))
                          <label class="pp-label" for="nm-{{ $a['id'] }}">Nazwa</label>
                          <input id="nm-{{ $a['id'] }}" name="name" class="pp-input pp-input-sm" placeholder="bez zmian">
                        @endif
                        @if (in_array('click_through_lookback_window_days', $a['editable'], true))
                          <label class="pp-label" for="cw-{{ $a['id'] }}">Okno po kliknięciu (1–90 dni)</label>
                          <input id="cw-{{ $a['id'] }}" name="click_through_lookback_window_days" type="number" min="1" max="90" class="pp-input pp-input-sm" placeholder="bez zmian">
                        @endif
                        @if (in_array('default_value', $a['editable'], true))
                          <label class="pp-label" for="dv-{{ $a['id'] }}">Wartość domyślna</label>
                          <input id="dv-{{ $a['id'] }}" name="default_value" inputmode="decimal" class="pp-input pp-input-sm" placeholder="bez zmian">
                        @endif
                        <x-pp.button variant="secondary" size="sm">Dodaj do szkicu</x-pp.button>
                      </form>
                    </details>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>

  <div class="grid gap-6 xl:grid-cols-2">
    {{-- Cele --}}
    <section class="pp-card min-w-0" aria-labelledby="goals">
      <div class="pp-card-header"><h3 id="goals" class="pp-card-title">Cele konwersji (optymalizacja stawek)</h3>@if ($overview['goals_read_at'])<span class="text-[12px] text-ink-mute">odczyt {{ Format::datetime($overview['goals_read_at']) }}</span>@endif</div>
      @if ($customerGoals === null)
        <p class="px-5 py-4 text-[13px] text-ink-mute">Cele konta i kampanii nie są synchronizowane – odczytaj je z Google Ads.</p>
      @else
        <ul class="divide-y divide-line text-[13px]">
          @foreach ($customerGoals as $goal)
            <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2">
              <span class="min-w-0 break-words"><span class="font-semibold text-ink">{{ OperationCatalog::CONVERSION_CATEGORIES[$goal['category']] ?? $goal['category'] }}</span> <span class="text-ink-mute">· {{ $goal['origin'] }} · konto</span></span>
              <span class="flex items-center gap-2">
                <span class="pp-badge pp-badge-{{ $goal['biddable'] ? 'ok' : 'neutral' }}">{{ $goal['biddable'] ? 'w optymalizacji' : 'poza optymalizacją' }}</span>
                @if ($canPrepare)
                  <form method="post" action="{{ $base }}/goal"><x-pp.nonce /><input type="hidden" name="resource_name" value="{{ $goal['resource_name'] }}"><input type="hidden" name="biddable" value="{{ $goal['biddable'] ? '0' : '1' }}"><x-pp.button variant="ghost" size="sm">{{ $goal['biddable'] ? 'Wyłącz' : 'Włącz' }}</x-pp.button></form>
                @endif
              </span>
            </li>
          @endforeach
        </ul>
        @php $overridden = array_values(array_filter((array) $campaignGoals, fn ($g) => collect($customerGoals)->first(fn ($c) => $c['category'] === $g['category'] && $c['origin'] === $g['origin'] && $c['biddable'] !== $g['biddable']) !== null)); @endphp
        @if ($overridden !== [])
          <p class="border-t border-line px-5 pt-3 text-[13px] font-semibold text-ink">Kampanie z innymi celami niż konto</p>
          <ul class="divide-y divide-line text-[13px]">
            @foreach ($overridden as $goal)
              <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2">
                <span class="min-w-0 break-words">{{ $goal['campaign_name'] }} <span class="text-ink-mute">· {{ OperationCatalog::CONVERSION_CATEGORIES[$goal['category']] ?? $goal['category'] }}</span></span>
                <span class="pp-badge pp-badge-{{ $goal['biddable'] ? 'ok' : 'neutral' }}">{{ $goal['biddable'] ? 'w optymalizacji' : 'poza' }}</span>
              </li>
            @endforeach
          </ul>
        @endif
      @endif
    </section>

    {{-- Nowe działanie --}}
    @if ($canPrepare && $overview['connected'])
      <section class="pp-card p-5" aria-labelledby="new-conversion">
        <h3 id="new-conversion" class="pp-card-title">Nowe działanie konwersji (strona internetowa)</h3>
        <p class="mt-1 text-[13px] text-ink-soft">Penny Pincher tworzy tylko konwersje typu „strona internetowa” (WEBPAGE). Po utworzeniu trzeba zainstalować tag Google (gtag.js) lub skonfigurować go w Google Tag Manager – bez tagu konwersja nie będzie rejestrowana. Fragment zdarzenia pobierzesz w Google Ads → Cele → Konwersje.</p>
        <form method="post" action="{{ $base }}/create" class="mt-3 grid gap-3 sm:grid-cols-2">
          <x-pp.nonce />
          <x-pp.field name="name" label="Nazwa" required class="sm:col-span-2" />
          <x-pp.field name="category" label="Kategoria" :options="OperationCatalog::CONVERSION_CATEGORIES" value="SUBMIT_LEAD_FORM" required />
          <x-pp.field name="counting_type" label="Zliczanie" :options="OperationCatalog::COUNTING" value="ONE_PER_CLICK" required />
          <x-pp.field name="click_window" label="Okno po kliknięciu (dni)" type="number" min="1" max="90" value="30" />
          <x-pp.field name="view_window" label="Okno po wyświetleniu (dni)" type="number" min="1" max="30" value="1" />
          <x-pp.field name="default_value" label="Wartość domyślna" inputmode="decimal" />
          <label class="flex items-start gap-2 text-[13px]"><input type="checkbox" name="always_use_default_value" value="1" class="mt-1"> <span>Zawsze używaj wartości domyślnej</span></label>
          <label class="flex items-start gap-2 text-[13px] sm:col-span-2"><input type="checkbox" name="primary_for_goal" value="1" class="mt-1"> <span>Konwersja główna (używana w optymalizacji stawek) – zalecane tylko dla rzeczywistych leadów</span></label>
          <div class="sm:col-span-2"><x-pp.button variant="secondary" icon="plus">Przygotuj konwersję (szkic)</x-pp.button></div>
        </form>
      </section>
    @endif
  </div>
@endsection
