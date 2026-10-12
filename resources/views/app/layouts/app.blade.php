@php
  use App\PennyPincher\Support\AppUrl;

  $section = $section ?? null;
  $nav = $project
    ? [
        ['overview', 'Przegląd', 'overview', AppUrl::project($project->id)],
        ['campaigns', 'Kampanie', 'campaigns', AppUrl::project($project->id, 'campaigns')],
        ['search-terms', 'Wyszukiwane hasła', 'search', AppUrl::project($project->id, 'search-terms')],
        ['audits', 'Audyt', 'audit', AppUrl::project($project->id, 'audits')],
        ['budget-leaks', 'Budget Leaks', 'leak', AppUrl::project($project->id, 'budget-leaks')],
        ['recommendations', 'Rekomendacje', 'idea', AppUrl::project($project->id, 'recommendations')],
        ['keywords', 'Keyword Opportunities', 'keyword', AppUrl::project($project->id, 'keywords')],
        ['conversions', 'Konwersje', 'target', AppUrl::project($project->id, 'conversions')],
        ['planner', 'Campaign Planner', 'planner', AppUrl::project($project->id, 'planner')],
        ['action-plan', 'Action Plan', 'list', AppUrl::project($project->id, 'action-plan')],
        ['monitoring', 'Monitoring', 'bell', AppUrl::project($project->id, 'monitoring')],
        ['changes', 'Change Center', 'changes', AppUrl::project($project->id, 'changes')],
        ['management', 'Google Ads Management', 'sliders', AppUrl::project($project->id, 'management')],
        ['conversion-management', 'Conversion Management', 'flag', AppUrl::project($project->id, 'conversion-management')],
        ['google-ads', 'Google Ads', 'link', AppUrl::project($project->id, 'google-ads')],
        ['imports', 'Importy', 'import', AppUrl::project($project->id, 'imports')],
      ]
    : [];
  $groupedProjects = collect($projects)->groupBy('organizationName');
@endphp

@extends('app.layouts.document')

@section('body')
  <div class="min-h-screen lg:grid lg:grid-cols-[264px_minmax(0,1fr)]" x-data="{ nav: false }">
    {{-- Sidebar --}}
    <div class="fixed inset-0 z-40 bg-ink/50 lg:hidden" x-show="nav" x-cloak x-on:click="nav = false"></div>

    <aside class="fixed inset-y-0 left-0 z-50 flex w-[280px] max-w-[85vw] -translate-x-full flex-col bg-sidebar text-sidebar-text transition-transform lg:sticky lg:top-0 lg:h-screen lg:w-auto lg:max-w-none lg:translate-x-0"
      x-bind:class="nav && '!translate-x-0'" aria-label="Nawigacja aplikacji">
      <div class="flex items-center justify-between gap-2 px-5 pt-5 pb-4">
        <a href="{{ AppUrl::to() }}" class="flex items-center gap-2.5 text-white">
          @include('app.partials.coin')
          <span class="pp-display text-[17px] leading-none">Penny Pincher</span>
        </a>
        <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm text-sidebar-text hover:bg-white/10 hover:text-white lg:hidden" x-on:click="nav = false" aria-label="Zamknij menu">
          <x-pp.icon name="close" />
        </button>
      </div>

      @if ($projects)
        <form method="get" action="{{ AppUrl::to('switch') }}" class="px-4 pb-4">
          <label for="pp-project-switch" class="mb-1.5 block px-1 text-[12px] font-medium text-sidebar-text/70">Projekt</label>
          <select id="pp-project-switch" name="project" data-autosubmit
            class="block min-h-10 w-full appearance-none rounded-[var(--radius-control)] border border-sidebar-line bg-white/5 px-3 text-sm text-white focus:border-copper focus:outline-none">
            @unless ($project)
              <option value="">Wybierz projekt…</option>
            @endunless
            @foreach ($groupedProjects as $organizationName => $organizationProjects)
              <optgroup label="{{ $organizationName }}" class="text-ink">
                @foreach ($organizationProjects as $item)
                  <option value="{{ $item->id }}" @selected($project && $project->id === $item->id) class="text-ink">
                    {{ $item->name }}{{ $item->status === 'archived' ? ' (archiwum)' : '' }}
                  </option>
                @endforeach
              </optgroup>
            @endforeach
          </select>
          @if ($section && $section !== 'overview')
            <input type="hidden" name="section" value="{{ $section }}">
          @endif
          <noscript><button class="mt-2 text-sm underline">Przełącz</button></noscript>
        </form>
      @endif

      <nav class="flex-1 overflow-y-auto px-3 pb-6">
        <ul class="space-y-0.5">
          @foreach ($nav as [$key, $label, $icon, $url])
            <li>
              <a href="{{ $url }}" class="pp-nav-link" @if ($section === $key) aria-current="page" @endif>
                <x-pp.icon :name="$icon" />
                {{ $label }}
                @if ($key === 'monitoring' && ($alertCount ?? 0) > 0)
                  <span class="pp-num ml-auto rounded-full bg-copper px-1.5 text-[11px] leading-5 font-semibold text-white" title="Nowe alerty">{{ $alertCount > 99 ? '99+' : $alertCount }}<span class="sr-only"> nowych alertów</span></span>
                @endif
              </a>
            </li>
          @endforeach
        </ul>

        <ul class="mt-4 space-y-0.5 border-t border-sidebar-line pt-4">
          <li>
            <a href="{{ AppUrl::to('projects') }}" class="pp-nav-link" @if ($section === 'projects') aria-current="page" @endif>
              <x-pp.icon name="projects" />
              Projekty
            </a>
          </li>
          @if ($project)
            <li>
              <a href="{{ AppUrl::project($project->id, 'settings') }}" class="pp-nav-link" @if ($section === 'settings') aria-current="page" @endif>
                <x-pp.icon name="settings" />
                Ustawienia
              </a>
            </li>
          @endif
        </ul>

      </nav>

      <div class="border-t border-sidebar-line px-4 py-4">
        <div class="flex items-center gap-3">
          <span class="grid size-9 shrink-0 place-items-center rounded-full bg-white/10 text-[13px] font-semibold text-white" aria-hidden="true">
            {{ mb_strtoupper(mb_substr($currentUser->display_name ?: $currentUser->user_login, 0, 1)) }}
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-white">{{ $currentUser->display_name ?: $currentUser->user_login }}</p>
            <p class="truncate text-[12px] text-sidebar-text/70">{{ $currentUser->user_email }}</p>
          </div>
          <form method="post" action="{{ AppUrl::to('logout') }}">
            <x-pp.nonce />
            <button type="submit" class="pp-btn pp-btn-ghost pp-btn-sm text-sidebar-text hover:bg-white/10 hover:text-white" title="Wyloguj" aria-label="Wyloguj">
              <x-pp.icon name="logout" :size="17" />
            </button>
          </form>
        </div>
      </div>
    </aside>

    {{-- Workspace --}}
    <div class="min-w-0">
      <header class="sticky top-0 z-30 border-b border-line bg-paper/90 backdrop-blur">
        <div class="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-6 lg:px-8">
          <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm -ml-2 lg:hidden" x-on:click="nav = true" aria-label="Otwórz menu">
            <x-pp.icon name="menu" />
          </button>

          <div class="min-w-0 flex-1">
            @if ($project)
              <p class="flex items-center gap-2 truncate text-[12.5px] text-ink-mute">
                <span class="truncate">{{ $project->organizationName }} / {{ $project->name }}</span>
                @if ($project->isDemo)<span class="pp-badge pp-badge-demo">DEMO</span>@endif
              </p>
            @endif
            <h1 class="truncate text-xl leading-7 font-semibold text-ink">{{ $pageTitle ?? 'Penny Pincher' }}</h1>
          </div>

          @isset($range)
            <x-pp.date-range :range="$range" />
          @endisset
          @isset($period)
            <x-pp.period-select :period="$period" />
          @endisset
        </div>
      </header>

      <main class="space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        @if ($project && $project->isDemo)
          <x-pp.alert type="warning">To projekt demonstracyjny z fikcyjnymi danymi. Nie jest powiązany z żadnym prawdziwym kontem Google Ads.</x-pp.alert>
        @endif

        @if ($flash)
          <x-pp.alert :type="$flash['type']">{{ $flash['message'] }}</x-pp.alert>
        @endif

        @yield('content')
      </main>
    </div>
  </div>
@endsection
