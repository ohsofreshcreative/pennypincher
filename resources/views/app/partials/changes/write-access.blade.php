{{-- Zgoda na zapis i wykonawcy (Google Ads Management). Zmienne: $project, $connection, $serverMode, $mode, $members, $canManageWrite. --}}
@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
@endphp
<section id="write-access" class="pp-card p-5 text-[13px] leading-6" aria-labelledby="write-access-title">
  <h2 id="write-access-title" class="pp-card-title flex items-center gap-2"><x-pp.icon name="shield" /> Uprawnienia do zapisu</h2>
  <p class="mt-1 text-ink-soft">Wdrożenie wymaga jednocześnie: trybu serwera <code>write_enabled</code> (stała <code>PP_GOOGLE_ADS_WRITE_MODE</code> w konfiguracji – nie da się jej zmienić z aplikacji), zgody właściciela dla tego konta Google Ads, zatwierdzenia zestawu przez administratora i uruchomienia przez administratora z uprawnieniem „wykonawca zmian”.</p>
  <dl class="mt-3 grid gap-2 sm:grid-cols-3">
    <div class="rounded-[var(--radius-card)] border border-line p-3"><dt class="text-ink-mute">Serwer</dt><dd class="font-semibold">{{ $serverMode->label() }}</dd></div>
    <div class="rounded-[var(--radius-card)] border border-line p-3"><dt class="text-ink-mute">Zgoda dla konta</dt><dd class="font-semibold">@if ($connection && $connection['customer_id']){{ (int) ($connection['write_enabled'] ?? 0) === 1 ? 'Włączona' : 'Wyłączona' }} <span class="pp-num font-normal text-ink-mute">({{ $connection['customer_id'] }})</span>@else Brak połączonego konta @endif</dd>
      @if (! empty($connection['write_enabled_at']))<dd class="text-ink-mute">od {{ Format::datetime($connection['write_enabled_at']) }}</dd>@endif</div>
    <div class="rounded-[var(--radius-card)] border border-line p-3"><dt class="text-ink-mute">Tryb efektywny</dt><dd class="font-semibold">{{ $mode->label() }}</dd></div>
  </dl>

  @if ($canManageWrite)
    @if ($connection && $connection['customer_id'] && ($connection['status'] ?? '') === 'active')
      @if ((int) ($connection['write_enabled'] ?? 0) === 1)
        <form method="post" action="{{ AppUrl::project($project->id, 'management/write-access') }}" class="mt-3">
          <x-pp.nonce /><input type="hidden" name="enabled" value="0">
          <x-pp.button variant="secondary" size="sm" icon="lock">Wyłącz zapis dla konta</x-pp.button>
        </form>
      @elseif ($serverMode->canWrite())
        <form method="post" action="{{ AppUrl::project($project->id, 'management/write-access') }}" class="mt-3 flex flex-wrap items-end gap-2">
          <x-pp.nonce /><input type="hidden" name="enabled" value="1">
          <x-pp.field name="confirm_customer" label="Wpisz ID konta, aby włączyć zapis" required autocomplete="off" class="min-w-0 sm:w-64" />
          <x-pp.button variant="danger" size="sm">Włącz zapis</x-pp.button>
        </form>
        <p class="mt-1 text-ink-mute">Zalecenie: pierwszą kontrolowaną zmianę wykonaj na osobnym koncie testowym Google Ads, nie na koncie produkcyjnym.</p>
      @else
        <p class="mt-3 text-ink-mute">Serwer nie pozwala na zapis – przełącznik zgody jest niedostępny.</p>
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
