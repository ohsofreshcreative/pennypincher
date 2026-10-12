@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Monitoring\AlertDetector;
  use App\PennyPincher\Repositories\AlertRepository;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $statusTones = ['new' => 'new', 'acknowledged' => 'reviewed', 'muted' => 'dismissed', 'resolved' => 'done'];
  $back = http_build_query(array_filter(request()->query(), 'is_scalar'));
  $eventLabels = ['detected' => 'Wykryty', 'status' => 'Zmiana statusu', 'reopened' => 'Ponownie otwarty', 'inactive' => 'Warunek ustał', 'unmuted' => 'Koniec wyciszenia'];
  $links = [
    'cpc_spike' => 'campaigns', 'ctr_drop' => 'campaigns', 'spend_no_conversions' => 'campaigns', 'no_activity' => 'campaigns',
    'pacing' => '', 'leads_drop' => 'conversions', 'measurement' => 'conversions', 'sync_failed' => 'google-ads', 'stale_data' => 'google-ads', 'audit' => 'audits',
  ];
@endphp

@section('content')
  <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="max-w-3xl">
        <h2 class="pp-card-title">Centrum powiadomień</h2>
        <p class="mt-1">Po każdej udanej synchronizacji Google Ads Penny Pincher sprawdza dane: skoki CPC, spadki CTR, wydatki bez konwersji, zatrzymane kampanie, tempo budżetu, spadek leadów, jakość pomiaru, synchronizację i nowe krytyczne problemy audytu.</p>
        <p class="mt-1">Aby ograniczyć fałszywe alarmy, porównujemy ostatnie 7 dni z 4 poprzednimi tygodniami (nie dzień z dniem), stosujemy progi minimalnej liczby kliknięć i kosztu, pomijamy wstrzymane kampanie i okresy z niepełnymi danymi.</p>
        <p class="mt-1 text-ink-mute">Ostatnie sprawdzenie: {{ $lastRun ? Format::datetime($lastRun['created_at']) . ' (' . ['sync' => 'po synchronizacji', 'manual' => 'ręcznie', 'cli' => 'z konsoli'][$lastRun['trigger_type']] . ')' : 'jeszcze nie' }}</p>
      </div>
      @if ($canEdit)
        <form method="post" action="{{ AppUrl::project($project->id, 'monitoring/check') }}" data-busy><x-pp.nonce /><x-pp.button icon="refresh" data-busy-label="Sprawdzanie…">Sprawdź teraz</x-pp.button></form>
      @endif
    </div>
  </section>

  <section class="pp-card" aria-label="Statusy alertów">
    <div class="grid grid-cols-2 divide-line sm:grid-cols-4 sm:divide-x">
      @foreach (AlertRepository::STATUSES as $value => $label)
        <a href="{{ AppUrl::project($project->id, 'monitoring', ['status' => $value]) }}" @class(['block hover:bg-ink/[0.03]', 'bg-mint-soft/60' => $filters['status'] === $value])>
          <dl><x-pp.stat-card :label="$label" :value="$counts[$value] ?? 0" /></dl>
        </a>
      @endforeach
    </div>
  </section>

  <div class="grid gap-6 2xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
    <section class="pp-card min-w-0" aria-label="Alerty">
      <x-pp.filter-bar :action="AppUrl::project($project->id, 'monitoring')">
        <div class="w-full sm:w-40">
          <label for="f-status" class="pp-label">Status</label>
          <select id="f-status" name="status" class="pp-input pp-input-sm"><option value="">Otwarte (bez rozwiązanych)</option>@foreach (AlertRepository::STATUSES as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="w-full sm:w-36">
          <label for="f-sev" class="pp-label">Ważność</label>
          <select id="f-sev" name="severity" class="pp-input pp-input-sm"><option value="">Wszystkie</option>@foreach (AlertRepository::SEVERITIES as $v => $l)<option value="{{ $v }}" @selected($filters['severity'] === $v)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="w-full sm:w-52">
          <label for="f-type" class="pp-label">Rodzaj</label>
          <select id="f-type" name="type" class="pp-input pp-input-sm"><option value="">Wszystkie</option>@foreach (AlertDetector::TYPES as $v => $l)<option value="{{ $v }}" @selected($filters['type'] === $v)>{{ $l }}</option>@endforeach</select>
        </div>
      </x-pp.filter-bar>

      @if ($alerts->items === [])
        <x-pp.empty-state title="Brak alertów" icon="bell">{{ $lastRun ? 'Ostatnie sprawdzenie nie wykryło problemów wymagających uwagi.' : 'Monitoring uruchomi się po najbliższej synchronizacji Google Ads albo po kliknięciu „Sprawdź teraz”.' }}</x-pp.empty-state>
      @else
        <ul class="divide-y divide-line">
          @foreach ($alerts->items as $alert)
            @php $link = $links[$alert['type']] ?? ''; @endphp
            <li id="alert-{{ $alert['id'] }}" class="scroll-mt-24" x-data="{ open: {{ $open === (int) $alert['id'] ? 'true' : 'false' }} }">
              <button type="button" class="flex w-full items-start gap-3 px-5 py-4 text-left hover:bg-ink/[0.02]" x-on:click="open = !open" x-bind:aria-expanded="open">
                <span class="min-w-0 flex-1">
                  <span class="flex flex-wrap items-center gap-2">
                    <x-pp.status-badge :status="$alert['severity']" :label="AlertRepository::SEVERITIES[$alert['severity']]" />
                    <x-pp.status-badge :status="$statusTones[$alert['status']]" :label="AlertRepository::STATUSES[$alert['status']]" />
                    @unless ($alert['condition_active'])<span class="pp-badge pp-badge-neutral">warunek ustał</span>@endunless
                    <span class="text-[12px] text-ink-mute">{{ AlertDetector::TYPES[$alert['type']] ?? $alert['type'] }} · wykryto {{ (int) $alert['occurrences'] }}× · ostatnio {{ Format::datetime($alert['last_detected_at']) }}</span>
                  </span>
                  <span class="mt-1.5 block font-semibold text-ink">{{ $alert['title'] }}</span>
                </span>
                <x-pp.icon name="chevron-down" class="mt-0.5 text-ink-mute transition-transform" x-bind:class="open && 'rotate-180'" />
              </button>
              <div x-show="open" x-cloak class="space-y-4 px-5 pb-5 text-sm leading-6">
                <p class="text-ink-soft">{{ $alert['message'] }}</p>
                @if ($alert['evidence'])
                  <dl class="divide-y divide-line rounded-[var(--radius-control)] border border-line">
                    @foreach ($alert['evidence'] as $pair)
                      @if (is_array($pair) && count($pair) >= 2)
                        <div class="grid gap-1 px-3 py-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] sm:gap-4"><dt class="text-ink-mute">{{ $pair[0] }}</dt><dd class="pp-num text-ink">{{ $pair[1] }}</dd></div>
                      @endif
                    @endforeach
                  </dl>
                @endif
                <p class="text-[12.5px] text-ink-mute">
                  Okres: {{ Format::period($alert['period_from'], $alert['period_to']) }} · pierwsze wykrycie: {{ Format::datetime($alert['first_detected_at']) }}
                  @if ($alert['status'] === 'muted' && $alert['muted_until']) · wyciszony do {{ Format::datetime($alert['muted_until']) }}@endif
                </p>
                <div class="flex flex-wrap gap-3 text-[13px]">
                  @if ($alert['campaign_id'])<a href="{{ AppUrl::project($project->id, 'campaigns/' . $alert['campaign_id']) }}" class="font-semibold text-mint underline">Kampania: {{ $campaignOptions[(int) $alert['campaign_id']] ?? '#' . $alert['campaign_id'] }}</a>
                    <a href="{{ AppUrl::project($project->id, 'management/campaigns/' . $alert['campaign_id']) }}" class="text-mint underline">Przygotuj zmianę kampanii</a>@endif
                  @if ($link !== '' && ! $alert['campaign_id'])<a href="{{ AppUrl::project($project->id, $link) }}" class="font-semibold text-mint underline">Przejdź do szczegółów</a>@endif
                  <a href="{{ AppUrl::project($project->id, 'recommendations') }}" class="text-mint underline">Rekomendacje</a>
                </div>
                @if ($canEdit)
                  <form method="post" action="{{ AppUrl::project($project->id, 'monitoring/' . $alert['id'] . '/status') }}" class="flex flex-wrap items-end gap-2 border-t border-line pt-4">
                    <x-pp.nonce /><input type="hidden" name="back" value="{{ $back }}">
                    @if ($alert['status'] !== 'acknowledged')<button type="submit" name="status" value="acknowledged" class="pp-btn pp-btn-secondary pp-btn-sm">Potwierdź</button>@endif
                    @if ($alert['status'] !== 'resolved')<button type="submit" name="status" value="resolved" class="pp-btn pp-btn-secondary pp-btn-sm">Rozwiązany</button>@endif
                    @if ($alert['status'] !== 'new')<button type="submit" name="status" value="new" class="pp-btn pp-btn-ghost pp-btn-sm">Przywróć jako nowy</button>@endif
                    <span class="flex items-end gap-1">
                      <label for="mute-{{ $alert['id'] }}" class="sr-only">Wycisz na</label>
                      <select id="mute-{{ $alert['id'] }}" name="mute_days" class="pp-input pp-input-sm w-32"><option value="7">7 dni</option><option value="30">30 dni</option><option value="">bezterminowo</option></select>
                      <button type="submit" name="status" value="muted" class="pp-btn pp-btn-ghost pp-btn-sm">Wycisz</button>
                    </span>
                    <div class="w-full"><label for="an-{{ $alert['id'] }}" class="sr-only">Notatka</label><input id="an-{{ $alert['id'] }}" name="note" class="pp-input pp-input-sm" maxlength="1000" placeholder="Notatka do zmiany statusu (opcjonalnie)"></div>
                  </form>
                @endif
                @if ($open === (int) $alert['id'] && $events)
                  <div>
                    <h4 class="text-[13px] font-semibold text-ink">Historia</h4>
                    <ol class="mt-2 space-y-1 border-l border-line pl-4 text-[12.5px] text-ink-soft">
                      @foreach ($events as $event)
                        <li><span class="pp-num text-ink-mute">{{ Format::datetime($event['created_at']) }}</span> · {{ $eventLabels[$event['event']] ?? $event['event'] }}@if ($event['from_status'] || $event['to_status']): {{ AlertRepository::STATUSES[$event['from_status']] ?? '—' }} → {{ AlertRepository::STATUSES[$event['to_status']] ?? '—' }}@endif @if ($event['user_name'])· {{ $event['user_name'] }}@endif @if ($event['note'])– {{ $event['note'] }}@endif</li>
                      @endforeach
                    </ol>
                  </div>
                @elseif ($open !== (int) $alert['id'])
                  <a href="{{ AppUrl::project($project->id, 'monitoring', array_filter(request()->query(), 'is_scalar') + ['open' => $alert['id']]) }}#alert-{{ $alert['id'] }}" class="text-[12.5px] text-mint underline">Pokaż historię</a>
                @endif
              </div>
            </li>
          @endforeach
        </ul>
        <x-pp.pagination :paginator="$alerts" label="alertów" />
      @endif
    </section>

    <section class="pp-card min-w-0" id="change-effects" aria-labelledby="change-effects-title">
      <div class="pp-card-header"><h2 id="change-effects-title" class="pp-card-title">Efekty wdrożonych zmian</h2><a class="text-[12.5px] text-mint underline" href="{{ AppUrl::project($project->id, 'changes', ['status' => 'applied']) }}">Change Center</a></div>
      @if ($changeEffects === [])
        <p class="px-5 py-4 text-[13px] text-ink-mute">W ostatnich 60 dniach nie wdrożono zmian przez Change Center.</p>
      @else
        <ul class="divide-y divide-line text-[13px] leading-6">
          @foreach ($changeEffects as $row)
            @php $e = $row['effects']; @endphp
            <li class="px-5 py-3">
              <a class="font-semibold break-words text-ink hover:text-mint" href="{{ AppUrl::project($project->id, 'changes/' . $row['set']['id']) }}#effects">#{{ $row['set']['id'] }} {{ $row['set']['title'] }}</a>
              <span class="block text-ink-mute">Wdrożono {{ Format::date($e['deployed_on']) }}@if ($e['campaigns'] !== []) · {{ implode(', ', array_slice($e['campaigns'], 0, 3)) }}@endif</span>
              @if ($e['available'])
                @php $cpaB = (float) $e['before']['conversions'] > 0 ? $e['before']['cost_micros'] / $e['before']['conversions'] : null; $cpaA = (float) $e['after']['conversions'] > 0 ? $e['after']['cost_micros'] / $e['after']['conversions'] : null; @endphp
                <span class="block text-ink-soft">Koszt {{ Format::money((int) $e['before']['cost_micros'], $project->currency, 0) }} → {{ Format::money((int) $e['after']['cost_micros'], $project->currency, 0) }} · konwersje {{ Format::conversions($e['before']['conversions']) }} → {{ Format::conversions($e['after']['conversions']) }}@if ($cpaB && $cpaA) · CPA {{ Format::money((int) round($cpaB), $project->currency, 0) }} → {{ Format::money((int) round($cpaA), $project->currency, 0) }}@endif</span>
                <span class="block text-[12px] text-ink-mute">Porównanie, nie dowód przyczynowości – sezonowość i inne zmiany też wpływają na wynik.</span>
              @else
                <span class="block text-ink-mute">{{ $e['message'] }}</span>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
    </section>

    <section class="pp-card p-5" id="notifications" aria-labelledby="notif">
      <h2 id="notif" class="pp-card-title">Powiadomienia e-mail</h2>
      <p class="mt-1 text-[13px] leading-6 text-ink-soft">Domyślnie wyłączone. Po włączeniu dostaniesz najwyżej jeden zbiorczy e-mail na dobę z nowymi alertami tego projektu. Wyciszone i potwierdzone alerty nie są wysyłane, a ten sam alert nie trafia do skrzynki dwa razy.</p>
      <form method="post" action="{{ AppUrl::project($project->id, 'monitoring/preferences') }}" class="mt-4 space-y-3">
        <x-pp.nonce />
        <label class="flex items-start gap-2.5 text-sm"><input type="checkbox" name="enabled" value="1" class="mt-1 accent-mint" @checked($preferences['enabled'])> <span>Wysyłaj mi powiadomienia o nowych alertach</span></label>
        <div>
          <label for="min-sev" class="pp-label">Minimalna ważność</label>
          <select id="min-sev" name="min_severity" class="pp-input pp-input-sm w-56"><option value="critical" @selected($preferences['min_severity'] === 'critical')>Tylko krytyczne</option><option value="warning" @selected($preferences['min_severity'] === 'warning')>Krytyczne i ostrzeżenia</option></select>
        </div>
        @if ($preferences['last_sent'])<p class="text-[12px] text-ink-mute">Ostatnia wiadomość: {{ Format::datetime(gmdate('Y-m-d H:i:s', $preferences['last_sent'])) }}</p>@endif
        <x-pp.button variant="secondary" size="sm">Zapisz ustawienia</x-pp.button>
      </form>
    </section>
  </div>
@endsection
