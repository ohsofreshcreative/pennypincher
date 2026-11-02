{{-- Zarządzanie › Przegląd i konfiguracja (pp2_management): onboarding integracji, ustawienia projektu / organizacji / serwera. --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $steps = $overview['steps'];
  $server = $overview['server'];
  $org = $overview['organization'];
  $connection = $overview['connection'];
  $canManage = $overview['canManage'];
  $next = collect($steps)->first(fn ($s) => ! $s['done'] && empty($s['optional']));
@endphp

@section('content')
  <section class="pp-card min-w-0" aria-labelledby="pp-onboarding">
    <div class="pp-card-header">
      <div>
        <h2 id="pp-onboarding" class="pp-card-title">Konfiguracja integracji</h2>
        <p class="mt-0.5 text-meta text-ink-mute">
          @if ($next)
            Następny krok: <strong class="text-ink">{{ $next['label'] }}</strong>.
          @else
            Wszystkie wymagane kroki są wykonane.
          @endif
          Stan odczytany z aplikacji – ta strona nie łączy się z Google Ads.
        </p>
      </div>
      @if ($canManage && $connection)
        <form method="post" action="{{ AppUrl::project($project->id, 'google-ads/diagnose') }}" data-busy>
          <x-pp.nonce />
          <input type="hidden" name="return" value="administration">
          <x-pp.button variant="secondary" icon="refresh" data-busy-label="Diagnostyka…">Diagnostyka połączenia</x-pp.button>
        </form>
      @endif
    </div>
    <ol class="divide-y divide-line">
      @foreach ($steps as $i => $step)
        <li class="flex flex-wrap items-start gap-3 px-5 py-3" data-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
          <span class="pp-num w-6 shrink-0 text-meta text-ink-mute">{{ $i + 1 }}.</span>
          <span class="min-w-0 flex-1">
            <a href="{{ $step['url'] }}" class="block text-sm font-medium text-ink hover:text-mint">{{ $step['label'] }}</a>
            <span class="block text-caption text-ink-soft">{{ $step['detail'] }}</span>
          </span>
          <x-pp.status-badge :status="$step['done'] ? 'done' : (! empty($step['optional']) ? 'removed' : 'partial')" :label="$step['done'] ? 'Gotowe' : (! empty($step['optional']) ? 'Opcjonalne' : 'Do zrobienia')" />
        </li>
      @endforeach
    </ol>
    <p class="border-t border-line px-5 py-3 text-caption text-ink-mute">Diagnostyka wykonuje tylko odczyty Google Ads API i pokazuje wynik bez tokenów. Nie rozłącza konta i nie usuwa autoryzacji; jeśli Google odrzuci autoryzację, połączenie zostanie oznaczone „Wymaga ponownej autoryzacji” – tak samo jak przy synchronizacji.</p>
    @if ($diagnostics && $canManage)
      @include('app.partials.google-ads.diagnostics')
    @endif
  </section>

  <div class="grid gap-6 xl:grid-cols-3">
    <section class="pp-card p-5" aria-labelledby="pp-settings-project" data-scope="project">
      <p class="text-caption font-medium text-ink-mute">Dotyczy tylko tego projektu</p>
      <h2 id="pp-settings-project" class="pp-card-title">Ustawienia projektu</h2>
      <dl class="mt-3 space-y-1 text-meta">
        <div><dt class="inline text-ink-mute">Nazwa:</dt> <dd class="inline">{{ $project->name }}</dd></div>
        <div><dt class="inline text-ink-mute">Waluta i strefa czasowa:</dt> <dd class="inline">{{ $project->currency }}, {{ $project->timezone }}</dd></div>
        <div><dt class="inline text-ink-mute">Budżet miesięczny:</dt> <dd class="inline pp-num">{{ $project->monthlyBudgetMicros !== null ? Format::money($project->monthlyBudgetMicros, $project->currency, 0) : '—' }}</dd></div>
        <div><dt class="inline text-ink-mute">Kontekst biznesowy:</dt> <dd class="inline">{{ $overview['filledContext'] }} z {{ count(\App\PennyPincher\Domain\Projects\BusinessContext::FIELDS) }} pól</dd></div>
        <div><dt class="inline text-ink-mute">Połączenie Google Ads:</dt> <dd class="inline">{{ $connection ? ($connection['customer_name'] ?: ($connection['customer_id'] ? 'konto ' . $connection['customer_id'] : 'konto nie wybrane')) : 'brak' }}</dd></div>
        <div><dt class="inline text-ink-mute">Tryb zmian dla projektu:</dt> <dd class="inline">{{ $server['project_mode']->label() }}</dd></div>
      </dl>
      <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-meta font-semibold">
        <a href="{{ AppUrl::project($project->id, 'settings') }}" class="text-mint hover:underline">Ustawienia projektu</a>
        <a href="{{ AppUrl::project($project->id, 'google-ads') }}" class="text-mint hover:underline">Integracja Google Ads</a>
        <a href="{{ AppUrl::project($project->id, 'management') }}" class="text-mint hover:underline">Zarządzanie kontem</a>
        <a href="{{ AppUrl::project($project->id, 'imports') }}" class="text-mint hover:underline">Importy</a>
      </div>
      @unless ($canManage)
        <p class="mt-3 text-caption text-ink-mute">Ustawienia projektu zmienia właściciel lub administrator organizacji.</p>
      @endunless
    </section>

    <section class="pp-card p-5" aria-labelledby="pp-settings-org" data-scope="organization">
      <p class="text-caption font-medium text-ink-mute">Dotyczy wszystkich projektów organizacji</p>
      <h2 id="pp-settings-org" class="pp-card-title">Organizacja: {{ $org['name'] }}</h2>
      <dl class="mt-3 space-y-1 text-meta">
        <div><dt class="inline text-ink-mute">Twoja rola:</dt> <dd class="inline">{{ $project->role?->label() ?? '—' }}</dd></div>
        <div><dt class="inline text-ink-mute">Członkowie:</dt> <dd class="inline pp-num">{{ $org['members'] }}</dd></div>
        <div><dt class="inline text-ink-mute">Wykonawcy zmian Google Ads:</dt> <dd class="inline pp-num">{{ $org['executors'] }}</dd></div>
      </dl>
      <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-meta font-semibold">
        <a href="{{ AppUrl::to('projects') }}" class="text-mint hover:underline">Projekty</a>
        @if ($org['can_manage'])
          <a href="{{ AppUrl::to('organizations/' . $org['id'] . '/members') }}" class="text-mint hover:underline">Członkowie i uprawnienia</a>
        @endif
      </div>
    </section>

    <section class="pp-card p-5" aria-labelledby="pp-settings-server" data-scope="server">
      <p class="text-caption font-medium text-ink-mute">Globalne dla instalacji – tylko odczyt</p>
      <h2 id="pp-settings-server" class="pp-card-title">Serwer</h2>
      <dl class="mt-3 space-y-1 text-meta">
        <div><dt class="inline text-ink-mute">Google Ads API:</dt> <dd class="inline pp-num">{{ $server['api_version'] }}</dd></div>
        <div><dt class="inline text-ink-mute">Tryb zapisu serwera:</dt> <dd class="inline">{{ $server['write_mode']->label() }}</dd></div>
        <div><dt class="inline text-ink-mute">Szyfrowanie tokenów:</dt> <dd class="inline">{{ $server['encryption'] ? 'skonfigurowane' : 'brak klucza' }}</dd></div>
        <div><dt class="inline text-ink-mute">Brakujące ustawienia:</dt> <dd class="inline">{{ $server['missing'] === [] ? 'brak' : implode(', ', $server['missing']) }}</dd></div>
      </dl>
      <p class="mt-3 text-caption text-ink-mute">Te wartości ustawia administrator serwera w wp-config.php. Aplikacja nie pokazuje ani nie zapisuje sekretów.</p>
    </section>
  </div>
@endsection
