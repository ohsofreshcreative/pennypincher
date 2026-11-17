@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Feature;
  use App\PennyPincher\Support\Navigation;

  $section = $section ?? null;
  $newNavigation = Feature::enabled(Feature::NAVIGATION);
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
        ['guide', 'Instrukcja', 'file', AppUrl::project($project->id, 'guide')],
      ]
    : [];
  $groupedProjects = collect($projects)->groupBy('organizationName');

  // Nawigacja 2.0: okruszki Projekt › Sekcja › Podwidok › szczegół, a na stronie startowej podwidoku nagłówek z nazwą z menu.
  $heading = $pageTitle ?? 'Penny Pincher';
  $trail = [];
  if ($newNavigation && $project && ($navGroup = Navigation::groupOf($section))) {
    $navDefinition = Navigation::groups()[$navGroup];
    $navItem = $navDefinition['items'][$section];
    $navItemUrl = empty($navItem['global']) ? AppUrl::project($project->id, $navItem['path']) : AppUrl::to($navItem['path']);
    $isLanding = str_ends_with(trim((string) wp_parse_url($navItemUrl, PHP_URL_PATH), '/'), trim(request()->path(), '/'));
    $trail[] = ['label' => $project->name, 'url' => AppUrl::project($project->id)];
    $trail[] = ['label' => $navDefinition['label'], 'url' => AppUrl::project($project->id, Navigation::resolve($navGroup))];
    if (count($navDefinition['items']) > 1) {
      $trail[] = ['label' => $navItem['label'], 'url' => $navItemUrl];
    }
    if ($isLanding) {
      $heading = count($navDefinition['items']) > 1 ? $navItem['label'] : $navDefinition['label'];
    } else {
      $trail[] = ['label' => $heading, 'url' => null];
    }
  }
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
          <label for="pp-project-switch" class="mb-1.5 block px-1 text-caption font-medium text-sidebar-text/70">Projekt</label>
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

      @if ($newNavigation)
        @include('app.partials.navigation')
      @else
      <nav class="flex-1 overflow-y-auto px-3 pb-6">
        <ul class="space-y-0.5">
          @foreach ($nav as [$key, $label, $icon, $url])
            <li>
              <a href="{{ $url }}" class="pp-nav-link" @if ($section === $key) aria-current="page" @endif>
                <x-pp.icon :name="$icon" />
                {{ $label }}
                @if ($key === 'monitoring' && ($alertCount ?? 0) > 0)
                  <span class="pp-num ml-auto rounded-full bg-copper px-1.5 text-2xs leading-5 font-semibold text-white" title="Nowe alerty">{{ $alertCount > 99 ? '99+' : $alertCount }}<span class="sr-only"> nowych alertów</span></span>
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
      @endif

      <div class="border-t border-sidebar-line px-4 py-4">
        <div class="flex items-center gap-3">
          <span class="grid size-9 shrink-0 place-items-center rounded-full bg-white/10 text-meta font-semibold text-white" aria-hidden="true">
            {{ mb_strtoupper(mb_substr($currentUser->display_name ?: $currentUser->user_login, 0, 1)) }}
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-white">{{ $currentUser->display_name ?: $currentUser->user_login }}</p>
            <p class="truncate text-caption text-sidebar-text/70">{{ $currentUser->user_email }}</p>
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
            @if ($trail)
              <div class="flex items-center gap-2">
                <x-pp.breadcrumbs :items="$trail" />
                @if ($project->isDemo)<span class="pp-badge pp-badge-demo">DEMO</span>@endif
              </div>
            @elseif ($project)
              <p class="flex items-center gap-2 truncate text-caption text-ink-mute">
                <span class="truncate">{{ $project->organizationName }} / {{ $project->name }}</span>
                @if ($project->isDemo)<span class="pp-badge pp-badge-demo">DEMO</span>@endif
              </p>
            @endif
            <h1 class="truncate text-xl leading-7 font-semibold text-ink">{{ $heading }}</h1>
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

  @if ($guide = \App\PennyPincher\Support\PageGuide::for($section, request()->path()))
    {{-- Instrukcja strony: treść w App\PennyPincher\Support\PageGuide. --}}
    <button type="button" class="pp-btn pp-btn-primary fixed bottom-5 right-5 z-30 rounded-full p-3 shadow-lg" x-data x-on:click="$dispatch('open-modal', 'pp-page-guide')" aria-label="Instrukcja: {{ $guide['title'] }}" title="Instrukcja tej strony" data-page-guide>
      <x-pp.icon name="file" :size="20" />
    </button>
    <x-pp.modal id="pp-page-guide" :title="'Jak korzystać: ' . $guide['title']">
      <div class="space-y-4 text-sm text-ink-soft">
        <p>{{ $guide['intro'] }}</p>
        <div>
          <h3 class="font-semibold text-ink">Krok po kroku</h3>
          <ol class="mt-1 list-decimal space-y-1 pl-5">
            @foreach ($guide['steps'] as $step)<li>{{ $step }}</li>@endforeach
          </ol>
        </div>
        @if (! empty($guide['notes']))
          <div>
            <h3 class="font-semibold text-ink">Dobrze wiedzieć</h3>
            <ul class="mt-1 list-disc space-y-1 pl-5">
              @foreach ($guide['notes'] as $note)<li>{{ $note }}</li>@endforeach
            </ul>
          </div>
        @endif
        @if (! empty($project))
          <p><a class="text-mint-dark underline" href="{{ AppUrl::project($project->id, 'guide') }}">Pełna instrukcja: od czego zacząć →</a></p>
        @endif
      </div>
    </x-pp.modal>
  @endif

  @if (! empty($project) && Feature::enabled(Feature::AI_CHAT) && app(\App\PennyPincher\Services\Assistant\AiChatService::class)->enabled())
    {{-- „Zapytaj Claude” (pp2_ai_chat): historia rozmowy w sessionStorage karty (przetrwa zmianę podstrony, bez zrzutów);
         zrzuty ekranu zmniejszane w przeglądarce i wysyłane tylko z bieżącym pytaniem; odpowiedzi przez AiChatController. --}}
    <div
      x-data="{
        open: false, busy: false, question: '', error: '', messages: [], images: [], dragging: false, copied: null,
        key: 'pp-ai-chat-{{ $project->id }}',
        init() {
          try { const s = JSON.parse(sessionStorage.getItem(this.key) || '{}'); this.messages = Array.isArray(s.messages) ? s.messages : []; this.open = !!s.open; } catch (e) {}
          this.$watch('open', (v) => { this.save(); if (v) this.$nextTick(() => { this.scroll(); this.$refs.input && this.$refs.input.focus(); }); });
          this.$nextTick(() => this.scroll());
        },
        scroll() { this.$refs.log && (this.$refs.log.scrollTop = this.$refs.log.scrollHeight); },
        save() { try { sessionStorage.setItem(this.key, JSON.stringify({ open: this.open, messages: this.messages.slice(-20).map((m) => ({ role: m.role, text: m.text, shots: m.shots || (m.images ? m.images.length : 0) })) })); } catch (e) {} },
        reset() { this.messages = []; this.images = []; this.error = ''; this.save(); },
        ask(text) { this.question = text; this.send(); },
        copy(text, i) { navigator.clipboard?.writeText(text).then(() => { this.copied = i; setTimeout(() => { this.copied = null; }, 1500); }); },
        md(src) {
          const esc = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
          const inline = (t) => esc(t)
            .replace(/\*\*(.+?)\*\*/g, '<strong class=\x22font-semibold text-ink\x22>$1</strong>')
            .replace(/`([^`]+)`/g, '<code class=\x22rounded bg-paper px-1 py-0.5 font-mono text-xs text-ink\x22>$1</code>')
            .replace(/„([^”\n]{1,60})”/g, '<span class=\x22whitespace-nowrap rounded-md bg-mint-soft px-1.5 py-0.5 font-medium text-mint-dark\x22>$1</span>');
          const cls = { ul: 'list-disc space-y-1 pl-5 marker:text-mint', ol: 'list-decimal space-y-1.5 pl-5 marker:font-semibold marker:text-mint' };
          let html = ''; const stack = [];
          const close = (depth) => { while (stack.length && stack[stack.length - 1].depth >= depth) html += '</li></' + stack.pop().type + '>'; };
          for (const line of String(src).split('\n')) {
            const item = line.match(/^(\s*)([-*•]|\d+[.)])\s+(.*)$/);
            const head = line.match(/^#{1,4}\s+(.*)$/);
            if (item) {
              const depth = item[1].length >= 2 ? 1 : 0; const type = /\d/.test(item[2]) ? 'ol' : 'ul';
              close(depth + 1);
              const top = stack[stack.length - 1];
              if (top && top.depth === depth && top.type !== type) close(depth);
              if (!stack.length || stack[stack.length - 1].depth < depth) { html += '<' + type + ' class=\x22' + cls[type] + (depth ? ' mt-1' : '') + '\x22>'; stack.push({ type, depth }); }
              else html += '</li>';
              html += '<li class=\x22pl-1\x22>' + inline(item[3]);
            } else if (!line.trim()) { close(0); }
            else if (head) { close(0); html += '<p class=\x22pt-1 font-semibold text-ink\x22>' + inline(head[1]) + '</p>'; }
            else if (stack.length && /^\s/.test(line)) { html += '<br>' + inline(line.trim()); }
            else { close(0); html += '<p>' + inline(line) + '</p>'; }
          }
          close(0);
          return html;
        },
        addFiles(list) {
          this.error = '';
          for (const file of Array.from(list || [])) {
            if (!file.type.startsWith('image/')) { this.error = 'Można dodać tylko obrazy (PNG, JPG, WebP).'; continue; }
            if (this.images.length >= 3) { this.error = 'Maksymalnie 3 zrzuty na pytanie.'; break; }
            const img = new Image(); const url = URL.createObjectURL(file);
            img.onload = () => {
              const scale = Math.min(1, 1568 / Math.max(img.width, img.height));
              const canvas = document.createElement('canvas');
              canvas.width = Math.round(img.width * scale); canvas.height = Math.round(img.height * scale);
              canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
              URL.revokeObjectURL(url);
              if (this.images.length < 3) this.images.push(canvas.toDataURL('image/jpeg', 0.85));
            };
            img.onerror = () => { URL.revokeObjectURL(url); this.error = 'Nie udało się odczytać obrazu.'; };
            img.src = url;
          }
        },
        paste(e) { const files = Array.from(e.clipboardData?.files || []).filter((f) => f.type.startsWith('image/')); if (files.length) { e.preventDefault(); this.addFiles(files); } },
        async send() {
          const q = this.question.trim();
          if ((!q && !this.images.length) || this.busy) return;
          const text = q || 'Co widać na tym zrzucie i co powinienem zrobić?';
          const shots = this.images; this.images = [];
          this.error = ''; this.busy = true;
          const history = JSON.stringify(this.messages.map((m) => ({ role: m.role, text: m.text })));
          this.messages.push({ role: 'user', text, images: shots }); this.question = '';
          this.$nextTick(() => this.scroll());
          const body = new FormData();
          body.append('{{ \App\PennyPincher\Http\Middleware\VerifyNonce::FIELD }}', '{{ wp_create_nonce(\App\PennyPincher\Http\Middleware\VerifyNonce::ACTION) }}');
          body.append('question', text); body.append('history', history);
          shots.forEach((src) => body.append('images[]', src));
          body.append('section', @js($section ?? '')); body.append('path', @js(request()->path()));
          const fail = (msg) => { this.messages.pop(); this.question = q; this.images = shots; this.error = msg; };
          try {
            const res = await fetch(@js(AppUrl::project($project->id, 'ai-chat')), { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (data.answer) { this.messages.push({ role: 'assistant', text: data.answer }); }
            else { fail(data.error || 'Nie udało się uzyskać odpowiedzi (HTTP ' + res.status + ').'); }
          } catch (e) { fail('Brak połączenia. Spróbuj ponownie.'); }
          this.busy = false; this.save();
          this.$nextTick(() => this.scroll());
        }
      }"
      class="fixed bottom-20 right-5 z-30 flex flex-col items-end gap-3"
      data-ai-chat
    >
      <section
        x-show="open" x-cloak
        x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-3 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="translate-y-3 opacity-0"
        class="relative flex h-[min(44rem,calc(100vh-7rem))] w-[min(27rem,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl"
        aria-label="Zapytaj Claude"
        x-on:dragover.prevent="dragging = true" x-on:dragleave.self="dragging = false" x-on:drop.prevent="dragging = false; addFiles($event.dataTransfer.files)"
      >
        <header class="flex items-center gap-3 bg-sidebar px-4 py-3 text-white">
          <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-mint"><x-pp.icon name="idea" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="font-semibold leading-tight">Zapytaj Claude</h2>
            <p class="text-xs text-sidebar-text">Doradca Penny Pincher · widzi ten ekran</p>
          </div>
          <button type="button" class="rounded-full px-2.5 py-1 text-xs text-sidebar-text hover:bg-white/10" x-show="messages.length" x-on:click="reset()" title="Wyczyść rozmowę">Nowa rozmowa</button>
          <button type="button" class="rounded-full p-1.5 text-sidebar-text hover:bg-white/10" x-on:click="open = false" aria-label="Zamknij czat"><x-pp.icon name="close" :size="18" /></button>
        </header>

        <div x-ref="log" class="flex-1 space-y-4 overflow-y-auto bg-paper px-4 py-5 text-sm">
          <div x-show="messages.length === 0" class="space-y-4">
            <p class="text-ink-soft">Cześć! Zapytaj, co zrobić na tym ekranie albo jak poprawić kampanię. Możesz też dodać zrzut ekranu (przycisk <strong>+</strong>, wklejenie Ctrl/Cmd+V albo przeciągnięcie). Niczego nie zmieniam na koncie – podpowiadam, co kliknąć.</p>
            <div class="flex flex-wrap gap-2">
              <template x-for="hint in ['Co mam zrobić na tym ekranie?', 'Poprowadź mnie krok po kroku do uruchomienia kampanii', 'Co tu jest najważniejsze do poprawy?']">
                <button type="button" class="rounded-full border border-line bg-surface px-3 py-1.5 text-left text-ink-soft hover:border-mint hover:text-mint" x-on:click="ask(hint)" x-text="hint"></button>
              </template>
            </div>
          </div>
          <template x-for="(m, i) in messages" :key="i">
            <div class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
              <div class="max-w-[85%] space-y-2">
                <div x-show="m.images && m.images.length" class="flex flex-wrap justify-end gap-1.5">
                  <template x-for="src in (m.images || [])"><img :src="src" alt="Zrzut ekranu" class="h-20 rounded-lg border border-line object-cover"></template>
                </div>
                <p x-show="!m.images && m.shots" class="text-right text-xs text-ink-mute" x-text="'Załączono zrzutów: ' + m.shots"></p>
                <p x-show="m.role === 'user'" class="whitespace-pre-line rounded-2xl rounded-br-md bg-mint px-3.5 py-2.5 leading-relaxed text-white" x-text="m.text"></p>
                <div x-show="m.role === 'assistant'" class="group relative">
                  <div class="space-y-2.5 rounded-2xl rounded-bl-md border border-line bg-surface px-4 py-3 leading-relaxed text-ink-soft shadow-sm" x-html="m.role === 'assistant' ? md(m.text) : ''"></div>
                  <button type="button" class="mt-1 px-1 text-2xs text-ink-mute opacity-0 transition hover:text-mint group-hover:opacity-100 focus:opacity-100" x-on:click="copy(m.text, i)" x-text="copied === i ? 'Skopiowano' : 'Kopiuj'"></button>
                </div>
              </div>
            </div>
          </template>
          <div x-show="busy" class="flex">
            <span class="flex items-center gap-1 rounded-2xl rounded-bl-md border border-line bg-surface px-4 py-3" aria-label="Claude pisze">
              <span class="size-1.5 animate-bounce rounded-full bg-ink-mute"></span>
              <span class="size-1.5 animate-bounce rounded-full bg-ink-mute [animation-delay:150ms]"></span>
              <span class="size-1.5 animate-bounce rounded-full bg-ink-mute [animation-delay:300ms]"></span>
            </span>
          </div>
          <p x-show="error" class="rounded-lg bg-danger-soft px-3 py-2 text-danger" x-text="error"></p>
        </div>

        <form class="border-t border-line bg-surface p-3" x-on:submit.prevent="send()">
          <div x-show="images.length" class="mb-2 flex gap-2">
            <template x-for="(src, i) in images" :key="i">
              <div class="relative">
                <img :src="src" alt="Zrzut do wysłania" class="size-16 rounded-lg border border-line object-cover">
                <button type="button" class="absolute -right-1.5 -top-1.5 rounded-full bg-ink p-0.5 text-white" x-on:click="images.splice(i, 1)" aria-label="Usuń zrzut"><x-pp.icon name="close" :size="12" /></button>
              </div>
            </template>
          </div>
          <div class="flex items-end gap-2 rounded-xl border border-line-strong bg-surface px-2 py-1.5 focus-within:border-mint focus-within:ring-2 focus-within:ring-mint/20">
            <input x-ref="file" type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" x-on:change="addFiles($event.target.files); $event.target.value = ''">
            <button type="button" class="rounded-lg p-2 text-ink-mute hover:bg-paper hover:text-mint" x-on:click="$refs.file.click()" aria-label="Dodaj zrzut ekranu" title="Dodaj zrzut ekranu"><x-pp.icon name="plus" :size="18" /></button>
            <label for="pp-ai-question" class="sr-only">Pytanie</label>
            <textarea
              id="pp-ai-question" x-ref="input" x-model="question" rows="1"
              class="max-h-40 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm shadow-none outline-none focus:border-0 focus:shadow-none focus:outline-none focus:ring-0 focus-visible:outline-none"
              placeholder="Napisz pytanie albo wklej zrzut…"
              x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
              x-on:paste="paste($event)"
              x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send(); }"
            ></textarea>
            <button type="submit" class="rounded-lg bg-mint p-2 text-white hover:bg-mint-dark disabled:opacity-40" x-bind:disabled="busy || (!question.trim() && !images.length)" aria-label="Wyślij"><x-pp.icon name="chevron-right" :size="18" /></button>
          </div>
          <p class="mt-1.5 px-1 text-2xs text-ink-mute">Enter – wyślij · Shift+Enter – nowa linia · do 3 zrzutów</p>
        </form>

        <div x-show="dragging" class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl border-2 border-dashed border-mint bg-mint-soft/90 font-semibold text-mint">Upuść zrzut ekranu</div>
      </section>
      <button type="button" class="flex size-12 items-center justify-center rounded-full bg-mint text-white shadow-lg transition hover:scale-105 hover:bg-mint-dark" x-on:click="open = !open" :aria-expanded="open" aria-label="Zapytaj Claude" title="Zapytaj Claude">
        <span x-show="!open"><x-pp.icon name="idea" :size="22" /></span>
        <span x-show="open" x-cloak><x-pp.icon name="close" :size="22" /></span>
      </button>
    </div>
  @endif
@endsection
