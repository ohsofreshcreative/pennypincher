{{-- Uprawnienia do zapisu (Google Ads Management). Zmienne: $project, $connection, $serverMode, $mode, $members, $canManageWrite, $isExecutor, $writeStatus. --}}
@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  $w = $writeStatus ?? null;
  $account = $w['account'] ?? null;
  $badge = fn (bool $ok, string $yesText, string $noText, string $noTone = 'neutral') => '<span class="pp-badge pp-badge-' . ($ok ? 'ok' : $noTone) . '">' . e($ok ? $yesText : $noText) . '</span>';
@endphp
<section id="write-access" class="pp-card p-5 text-[13px] leading-6" aria-labelledby="write-access-title">
  <h2 id="write-access-title" class="pp-card-title flex items-center gap-2"><x-pp.icon name="shield" /> Uprawnienia do zapisu</h2>
  <p class="mt-1 text-ink-soft">Wdrożenie wymaga jednocześnie: trybu serwera <code>write_enabled</code> i konta na allowliście zapisu (obie rzeczy w konfiguracji serwera – nie da się ich zmienić z aplikacji), zgody właściciela dla tego konta, zatwierdzenia zestawu przez administratora i uruchomienia przez administratora z uprawnieniem „wykonawca zmian”.</p>

  <ol class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4" aria-label="Etapy zmiany">
    <li class="rounded-[var(--radius-card)] border border-line p-3"><p class="font-semibold text-ink">1. Przygotowanie</p><p class="text-ink-mute">szkic w Change Center – nic nie wysłano</p></li>
    <li class="rounded-[var(--radius-card)] border border-line p-3"><p class="font-semibold text-ink">2. Zatwierdzenie</p><p class="text-ink-mute">administrator akceptuje – nadal nic nie wysłano</p></li>
    <li class="rounded-[var(--radius-card)] border border-line p-3"><p class="font-semibold text-ink">3. Wdrożenie</p><p class="text-ink-mute">wykonawca uruchamia – Google Ads API przyjmuje zmianę</p></li>
    <li class="rounded-[var(--radius-card)] border border-line p-3"><p class="font-semibold text-ink">4. Weryfikacja</p><p class="text-ink-mute">ponowny odczyt potwierdza stan na koncie</p></li>
  </ol>

  @if ($w)
    <dl class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
      <div class="rounded-[var(--radius-card)] border border-line p-3">
        <dt class="text-ink-mute">Konto Google Ads</dt>
        <dd class="font-semibold">@if ($w['customer_id'])<span class="pp-num">{{ $w['customer_formatted'] }}</span> <span class="font-normal break-words">{{ $account['name'] ?? $w['customer_name'] }}</span>@else Brak połączonego konta @endif</dd>
      </div>
      <div class="rounded-[var(--radius-card)] border border-line p-3">
        <dt class="text-ink-mute">Rodzaj konta</dt>
        <dd>
          @if ($account)
            {!! $badge(! $account['test_account'], 'Produkcyjne', 'Testowe (test account)', 'info') !!}
            @if ($account['manager'])<span class="pp-badge pp-badge-warn">Konto menedżera</span>@endif
            <span class="block text-ink-mute">odczyt {{ Format::datetime($w['account_read_at']) }}</span>
          @else
            <span class="text-ink-mute">nieznany – odczytaj dane konta</span>
          @endif
          @if ($w['customer_id'] && $canManageWrite)
            <form method="post" action="{{ AppUrl::project($project->id, 'management/write-access/check') }}" class="mt-1" data-busy>
              <x-pp.nonce /><x-pp.button variant="ghost" size="sm" icon="refresh" data-busy-label="Odczyt…">Odczytaj dane konta</x-pp.button>
            </form>
          @endif
        </dd>
      </div>
      <div class="rounded-[var(--radius-card)] border border-line p-3">
        <dt class="text-ink-mute">Allowlista zapisu (serwer)</dt>
        <dd>
          @if ($w['blocked'])
            <span class="pp-badge pp-badge-danger">Konto zablokowane dla zapisu</span>
          @elseif ($w['customer_id'])
            {!! $badge($w['allowlisted'], 'Na allowliście', 'Poza allowlistą', 'warn') !!}
          @endif
          <span class="block text-ink-mute">{{ $w['allowlist'] === [] ? 'Allowlista pusta – zapis niemożliwy na żadnym koncie.' : 'Dozwolone: ' . implode(', ', $w['allowlist']) }}</span>
        </dd>
      </div>
      <div class="rounded-[var(--radius-card)] border border-line p-3">
        <dt class="text-ink-mute">Tryb serwera</dt>
        <dd class="font-semibold">{{ $w['server_mode']->label() }}</dd>
        <dd class="text-ink-mute">efektywnie dla konta: {{ $w['effective_mode']->label() }}</dd>
      </div>
      <div class="rounded-[var(--radius-card)] border border-line p-3">
        <dt class="text-ink-mute">Zgoda właściciela</dt>
        <dd>{!! $badge($w['consent'], 'Włączona', 'Wyłączona') !!}@if ($w['consent_at'])<span class="block text-ink-mute">od {{ Format::datetime($w['consent_at']) }}</span>@endif</dd>
      </div>
      <div class="rounded-[var(--radius-card)] border border-line p-3">
        <dt class="text-ink-mute">Blokada emisji reklam</dt>
        <dd>{!! $badge(! $w['serving_allowed'], 'Włączona', 'Wyłączona – emisja dozwolona', 'warn') !!}</dd>
        <dd class="text-ink-mute">Kampanie, grupy i reklamy tylko jako wstrzymane; bez zmian budżetu aktywnych kampanii.</dd>
      </div>
      <div class="rounded-[var(--radius-card)] border border-line p-3 sm:col-span-2 lg:col-span-2">
        <dt class="text-ink-mute">Czy możesz wdrażać zmiany</dt>
        <dd>
          @if ($w['execute_blocker'] === null)
            <span class="pp-badge pp-badge-ok">Tak – po zatwierdzeniu zestawu</span>
          @else
            <span class="pp-badge pp-badge-neutral">Nie</span> <span class="text-ink-soft">{{ $w['execute_blocker'] }}</span>
          @endif
        </dd>
      </div>
      <div class="rounded-[var(--radius-card)] border border-line p-3">
        <dt class="text-ink-mute">Ostatnia operacja zapisu</dt>
        <dd>
          @if ($w['last_write'])
            <a class="underline" href="{{ AppUrl::project($project->id, 'changes/' . $w['last_write']['change_set_id']) }}">Zestaw #{{ $w['last_write']['change_set_id'] }}</a>
            · {{ ['ok' => 'przyjęta', 'partial' => 'częściowo', 'error' => 'błąd', 'ambiguous' => 'wynik nieznany'][$w['last_write']['outcome']] ?? $w['last_write']['outcome'] }}
            <span class="block break-all text-ink-mute">{{ Format::datetime($w['last_write']['created_at']) }}@if ($w['last_write']['request_id']) · request-id {{ $w['last_write']['request_id'] }}@endif</span>
          @else
            <span class="text-ink-mute">brak – żadna zmiana nie została wysłana</span>
          @endif
        </dd>
      </div>
    </dl>
    @if ($w['blocked_list'] !== [])
      <p class="mt-2 text-ink-mute">Konta zablokowane dla zapisu (PP_GOOGLE_ADS_BLOCKED_CUSTOMERS – tylko odczyt, także bez walidacji w API): <span class="pp-num">{{ implode(', ', $w['blocked_list']) }}</span>.</p>
    @endif
  @endif

  @if ($canManageWrite)
    @if ($connection && $connection['customer_id'] && ($connection['status'] ?? '') === 'active')
      @if ((int) ($connection['write_enabled'] ?? 0) === 1)
        <form method="post" action="{{ AppUrl::project($project->id, 'management/write-access') }}" class="mt-3">
          <x-pp.nonce /><input type="hidden" name="enabled" value="0">
          <x-pp.button variant="secondary" size="sm" icon="lock">Wyłącz zapis dla konta</x-pp.button>
        </form>
        <p class="mt-1 text-ink-mute">Wyłączenie działa od razu: zestawy w kolejce wracają do „Gotowy do wdrożenia”, a trwające wdrożenie zatrzymuje się przed kolejnym żądaniem.</p>
      @elseif (! $serverMode->canWrite())
        <p class="mt-3 text-ink-mute">Serwer nie pozwala na zapis – przełącznik zgody jest niedostępny.</p>
      @elseif ($w && $w['blocker'] !== null)
        <p class="mt-3 text-ink-mute"><x-pp.icon name="lock" :size="14" class="inline" /> {{ $w['blocker'] }} Zgody nie można włączyć.</p>
      @else
        <form method="post" action="{{ AppUrl::project($project->id, 'management/write-access') }}" class="mt-3 flex flex-wrap items-end gap-2">
          <x-pp.nonce /><input type="hidden" name="enabled" value="1">
          <x-pp.field name="confirm_customer" label="Wpisz ID konta, aby włączyć zapis" required autocomplete="off" class="min-w-0 sm:w-64" />
          <x-pp.button variant="danger" size="sm">Włącz zapis</x-pp.button>
        </form>
        <p class="mt-1 text-ink-mute">Zgoda nie wdraża niczego sama. Każda zmiana nadal wymaga przygotowania, zatwierdzenia i osobnego, ręcznego „Wdróż do Google Ads” przez wykonawcę.</p>
      @endif
    @endif

    <h3 class="mt-5 font-semibold text-ink">Wykonawcy zmian</h3>
    <p class="text-ink-mute">Uprawnienie mogą mieć tylko administratorzy i właściciele. Członkowie przygotowują szkice, administratorzy zatwierdzają.</p>
    <ul class="mt-2 divide-y divide-line rounded-[var(--radius-card)] border border-line">
      @foreach ($members as $member)
        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
          <span class="min-w-0 break-words"><span class="font-semibold text-ink">{{ $member['display_name'] ?: $member['user_login'] }}</span> <span class="text-ink-mute">· {{ \App\PennyPincher\Domain\Access\Role::tryFrom($member['role'])?->label() ?? $member['role'] }}</span></span>
          @if ($member['role'] === 'member')
            <span class="text-ink-mute">tylko szkice</span>
          @else
            <form method="post" action="{{ AppUrl::project($project->id, 'management/executor') }}">
              <x-pp.nonce /><input type="hidden" name="user" value="{{ $member['user_id'] }}"><input type="hidden" name="enabled" value="{{ (int) $member['can_execute_changes'] === 1 ? '0' : '1' }}">
              <x-pp.button variant="{{ (int) $member['can_execute_changes'] === 1 ? 'secondary' : 'ghost' }}" size="sm">{{ (int) $member['can_execute_changes'] === 1 ? 'Wykonawca – odbierz' : 'Nadaj „wykonawca”' }}</x-pp.button>
            </form>
          @endif
        </li>
      @endforeach
    </ul>
  @else
    <p class="mt-3 text-ink-mute">Zgodę na zapis i uprawnienie „wykonawca zmian” nadaje właściciel organizacji. Ty: {{ $isExecutor ? 'masz uprawnienie wykonawcy' : 'nie masz uprawnienia wykonawcy' }}.</p>
  @endif
</section>
