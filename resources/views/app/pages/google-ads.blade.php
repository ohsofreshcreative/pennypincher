@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $status = $connection['status'] ?? null;
  $base = AppUrl::project($project->id, 'google-ads');
  $configured = $missingConfig === [] && $encryptionReady;
  $openRun = collect($runs)->first(fn ($run) => in_array($run['status'], ['queued', 'running'], true));
  $typeLabels = ['backfill' => 'Historia (12 mies.)', 'incremental' => 'Przyrostowa', 'manual' => 'Ręczna'];
  $runStatusLabels = ['completed' => 'Zakończona', 'failed' => 'Błąd', 'queued' => 'W kolejce', 'running' => 'W toku', 'cancelled' => 'Anulowana'];
@endphp

@section('content')
  @unless ($configured)
    <x-pp.alert type="warning" title="Integracja nie jest jeszcze skonfigurowana na serwerze">
      Brakuje ustawień w <code>wp-config.php</code>:
      @foreach ($missingConfig as $name)<code class="whitespace-nowrap">{{ $name }}</code>{{ $loop->last ? '.' : ',' }} @endforeach
      Instrukcja krok po kroku (projekt Google Cloud, ekran zgody OAuth, klient OAuth) jest w pliku <code>docs/GOOGLE_ADS.md</code>.
      Adres przekierowania do wpisania w Google Cloud: <code class="break-all">{{ $redirectUri }}</code>
    </x-pp.alert>
  @endunless

  <div class="grid gap-6 2xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
    <section class="pp-card" aria-labelledby="pp-connection">
      <div class="pp-card-header">
        <h2 id="pp-connection" class="pp-card-title">Połączenie z Google Ads</h2>
        @if ($connection)
          <x-pp.status-badge :status="$status" />
        @else
          <x-pp.status-badge status="other" label="Nie połączono" />
        @endif
      </div>

      <div class="space-y-5 p-5">
        @if (! $connection)
          <p class="text-sm leading-6 text-ink-soft">
            Połącz projekt z kontem reklamowym, żeby automatycznie pobierać kampanie, słowa kluczowe, wyszukiwane hasła, konwersje i ich ustawienia.
            Penny Pincher ma dostęp <strong>tylko do odczytu danych</strong> – nie wprowadza żadnych zmian w kampaniach.
          </p>
        @else
          <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-ink-mute">Konto reklamowe</dt>
              <dd class="font-medium text-ink">
                @if ($connection['customer_id'])
                  {{ $connection['customer_name'] ?: '—' }}
                  <span class="pp-num block text-[12.5px] font-normal text-ink-mute">{{ preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '$1-$2-$3', $connection['customer_id']) }}</span>
                @else
                  nie wybrano
                @endif
              </dd>
            </div>
            <div>
              <dt class="text-ink-mute">Konto Google</dt>
              <dd class="truncate font-medium text-ink">{{ $connection['google_email'] ?: '—' }}</dd>
            </div>
            <div>
              <dt class="text-ink-mute">Ostatnia udana synchronizacja</dt>
              <dd class="pp-num font-medium text-ink">{{ $lastSync ? Format::datetime($lastSync['finished_at']) : 'jeszcze nie' }}</dd>
            </div>
            <div>
              <dt class="text-ink-mute">Waluta / strefa czasowa konta</dt>
              <dd class="font-medium text-ink">{{ $connection['currency'] ?: '—' }} / {{ $connection['time_zone'] ?: '—' }}</dd>
            </div>
          </dl>

          @if ($status === 'reauth_required')
            <x-pp.alert type="error" title="Wymagana ponowna autoryzacja">Google odrzucił zapisaną autoryzację (np. cofnięto dostęp). Połącz konto ponownie – wybrane konto reklamowe i dane zostaną zachowane.</x-pp.alert>
          @elseif ($status === 'error' && $connection['last_error'])
            <x-pp.alert type="error">{{ $connection['last_error'] }}</x-pp.alert>
          @endif
        @endif

        @if ($project->isDemo)
          <p class="text-sm text-ink-mute">Projektu demonstracyjnego nie można połączyć z prawdziwym kontem. Utwórz własny projekt.</p>
        @elseif ($canManage)
          @if ($status === 'pending_account')
            <div>
              <h3 class="text-sm font-semibold text-ink">Wybierz konto reklamowe</h3>
              @if ($accountsError)
                <x-pp.alert type="error" class="mt-3" :title="$accountsError['title']">
                  {{ $accountsError['message'] }}
                  @if ($accountsError['kind'] === 'auth')
                    Użyj przycisku „Połącz ponownie” poniżej.
                  @else
                    Szczegóły zapytań pokaże „Diagnostyka połączenia”.
                  @endif
                </x-pp.alert>
              @elseif ($accounts === [])
                <x-pp.alert type="warning" class="mt-3" title="Brak kont reklamowych">
                  Google nie zwrócił żadnego konta reklamowego dla konta {{ $connection['google_email'] ?: 'Google' }}.
                  Sprawdź, czy ten adres ma dostęp w Google Ads (Administracja → Dostęp i bezpieczeństwo) – bezpośrednio albo przez konto menedżera – albo połącz inne konto Google.
                </x-pp.alert>
              @else
                <form method="post" action="{{ $base }}account" class="mt-3 space-y-3" data-busy>
                  <x-pp.nonce />
                  <fieldset class="max-h-80 space-y-2 overflow-y-auto">
                    <legend class="sr-only">Konta reklamowe</legend>
                    @foreach ($accounts as $account)
                      <label class="flex cursor-pointer items-start gap-3 rounded-[var(--radius-control)] border border-line px-3 py-2.5 hover:border-ink-mute has-[:checked]:border-mint">
                        <input type="radio" name="customer_id" value="{{ $account['customer_id'] }}" class="mt-1" required @checked(count($accounts) === 1)>
                        <span class="min-w-0 text-sm">
                          <span class="block font-medium text-ink">{{ $account['name'] ?: 'Bez nazwy' }}@if ($account['test']) <span class="pp-badge pp-badge-neutral">konto testowe</span>@endif</span>
                          <span class="pp-num block text-[12.5px] text-ink-mute">{{ preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '$1-$2-$3', $account['customer_id']) }} · {{ $account['currency'] ?? '—' }}@if ($account['login_customer_id']) · przez konto menedżera {{ $account['login_customer_id'] }}@endif</span>
                        </span>
                      </label>
                    @endforeach
                  </fieldset>
                  <x-pp.button data-busy-label="Zapisywanie…">Wybierz i pobierz dane</x-pp.button>
                </form>
              @endif
              @if ($skippedAccounts !== [] && ! $accountsError)
                <details class="mt-3 text-[13px] text-ink-mute">
                  <summary class="cursor-pointer">Pominięte konta ({{ count($skippedAccounts) }})</summary>
                  <ul class="mt-2 space-y-1">
                    @foreach ($skippedAccounts as $skipped)
                      <li><span class="pp-num">{{ $skipped['customer_id'] }}</span>: {{ $skipped['message'] }}</li>
                    @endforeach
                  </ul>
                </details>
              @endif
            </div>
          @endif

          <div class="flex flex-wrap gap-3 border-t border-line pt-5">
            @if (! $connection || $status === 'reauth_required' || $status === 'pending_account')
              <form method="post" action="{{ $base }}connect">
                <x-pp.nonce />
                <x-pp.button icon="link" :variant="$connection ? 'secondary' : 'primary'" :disabled="! $configured">{{ $connection ? 'Połącz ponownie' : 'Połącz z Google Ads' }}</x-pp.button>
              </form>
            @endif

            @if ($status === 'active')
              <form method="post" action="{{ $base }}sync" data-busy>
                <x-pp.nonce />
                <x-pp.button icon="refresh" data-busy-label="Synchronizacja…">{{ $openRun ? 'Kontynuuj synchronizację' : 'Synchronizuj teraz' }}</x-pp.button>
              </form>
            @endif

            @if ($connection && $status !== 'disconnected')
              <form method="post" action="{{ $base }}diagnose" data-busy>
                <x-pp.nonce />
                <x-pp.button variant="secondary" data-busy-label="Sprawdzanie…">Diagnostyka połączenia</x-pp.button>
              </form>
            @endif

            @if ($connection)
              <details class="group">
                <summary class="pp-btn pp-btn-ghost list-none">Rozłącz</summary>
                <form method="post" action="{{ $base }}disconnect" class="mt-3 max-w-sm space-y-3 rounded-[var(--radius-control)] border border-line p-3 text-sm">
                  <x-pp.nonce />
                  <p class="text-ink-soft">Cofniemy autoryzację w Google i usuniemy zapisane tokeny. Zsynchronizowane dane i audyty zostaną w projekcie.</p>
                  <x-pp.button variant="danger" size="sm">Tak, rozłącz</x-pp.button>
                </form>
              </details>
            @endif
          </div>
        @elseif (! $connection)
          <p class="text-sm text-ink-mute">Połączenie może skonfigurować właściciel lub administrator organizacji.</p>
        @endif
      </div>

      @if ($diagnostics && $canManage)
        <div id="pp-diagnostics" class="border-t border-line p-5">
          <h3 class="text-sm font-semibold text-ink">Diagnostyka połączenia <span class="pp-num font-normal text-ink-mute">{{ Format::datetime($diagnostics['at']) }}</span></h3>
          <p class="mt-1 text-[12.5px] text-ink-mute">Tylko odczyt. Bez tokenów i sekretów.</p>
          <div class="pp-table-wrap mt-3">
            <table class="pp-table text-[13px]">
              <thead><tr><th>Zapytanie</th><th>HTTP</th><th>Kod Google</th><th>Wynik</th><th class="pp-col-num">Konta</th></tr></thead>
              <tbody>
                @foreach ($diagnostics['rows'] as $row)
                  <tr>
                    <td class="pp-num break-all">{{ $row['endpoint'] }}</td>
                    <td class="pp-num">{{ $row['status'] ?? '—' }}</td>
                    <td class="pp-num {{ $row['code'] ? 'text-danger' : '' }}">{{ $row['code'] ?? '—' }}</td>
                    <td class="min-w-[220px]">{{ $row['message'] ?? '—' }}</td>
                    <td class="pp-col-num">{{ $row['count'] ?? '—' }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      @endif

      <div class="border-t border-line p-5 text-[13px] leading-6 text-ink-mute">
        <p>Zakres danych: kampanie (status, typ, budżet, strategia stawek, sieci, lokalizacje), grupy reklam, słowa kluczowe, wyszukiwane hasła, działania konwersji (kategoria, konwersja główna/dodatkowa, sposób zliczania) oraz dzienne metryki z ostatnich 12 miesięcy.</p>
        <p class="mt-2">Dane z API mają pierwszeństwo przed importami za te same dni – wydatki nie są liczone podwójnie. Historia importów pozostaje dostępna. Google Ads API {{ $apiVersion }}.</p>
      </div>
    </section>

    <section class="pp-card min-w-0" aria-labelledby="pp-sync-history">
      <div class="pp-card-header">
        <h2 id="pp-sync-history" class="pp-card-title">Historia synchronizacji</h2>
        @if ($openRun)
          <span class="text-[13px] text-ink-mute">Synchronizacja jest dokańczana w tle przez zadanie cron.</span>
        @endif
      </div>

      @if ($runs === [])
        <x-pp.empty-state title="Brak synchronizacji" icon="refresh">Po wybraniu konta reklamowego pobierzemy historię z 12 miesięcy, a potem codziennie dane z ostatnich 30 dni.</x-pp.empty-state>
      @else
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead>
              <tr>
                <th>Przebieg</th>
                <th>Okres</th>
                <th>Stan</th>
                <th>Postęp</th>
                <th class="pp-col-num">Rekordy</th>
                <th><span class="sr-only">Akcje</span></th>
              </tr>
            </thead>
            <tbody>
              @foreach ($runs as $run)
                @php $percent = $run['steps_total'] > 0 ? (int) floor($run['step_index'] / $run['steps_total'] * 100) : 0; @endphp
                <tr>
                  <td class="whitespace-nowrap">
                    <span class="font-medium text-ink">{{ $typeLabels[$run['type']] ?? $run['type'] }}</span>
                    <span class="pp-num block text-[12px] text-ink-mute">#{{ $run['id'] }}, {{ Format::datetime($run['created_at']) }}</span>
                  </td>
                  <td class="pp-num whitespace-nowrap text-ink-soft">{{ Format::period($run['date_from'], $run['date_to']) }}</td>
                  <td class="max-w-[280px]">
                    <x-pp.status-badge :status="$run['status']" :label="$runStatusLabels[$run['status']] ?? null" />
                    @if ($run['status'] === 'queued' && $run['next_attempt_at'])
                      <span class="block text-[12px] text-ink-mute">Ponowienie (próba {{ $run['attempts'] + 1 }}) po {{ Format::datetime($run['next_attempt_at']) }}</span>
                    @endif
                    @if ($run['last_error'] && $run['status'] !== 'completed')
                      <span class="mt-1 block text-[12.5px] leading-5 text-danger">{{ $run['last_error'] }}</span>
                    @endif
                  </td>
                  <td class="min-w-[140px]">
                    <div class="h-1.5 overflow-hidden rounded-full bg-ink/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="Postęp synchronizacji">
                      <div class="h-full rounded-full {{ $run['status'] === 'failed' ? 'bg-danger' : 'bg-mint' }}" style="width: {{ $percent }}%"></div>
                    </div>
                    <span class="pp-num text-[12px] text-ink-mute">{{ $run['step_index'] }} z {{ $run['steps_total'] }} kroków</span>
                  </td>
                  <td class="pp-col-num">{{ Format::number($run['records_imported']) }}</td>
                  <td class="text-right">
                    @if ($run['status'] === 'failed' && $canManage)
                      <form method="post" action="{{ $base }}sync/{{ $run['id'] }}/retry">
                        <x-pp.nonce />
                        <x-pp.button variant="secondary" size="sm" icon="refresh">Ponów</x-pp.button>
                      </form>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>
  </div>
@endsection
