@props(['id', 'title'])

{{-- Otwieranie: przycisk z @click="$dispatch('open-modal', '{{ id }}')" --}}
<div x-data="{ open: false }" x-on:open-modal.window="if ($event.detail === '{{ $id }}') { open = true; $nextTick(() => $refs.panel.querySelector('input, select, textarea, button')?.focus()) }"
  x-on:keydown.escape.window="open = false" x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
  role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
  <div class="absolute inset-0 bg-ink/40" x-on:click="open = false"></div>
  <div x-ref="panel" class="pp-card relative w-full max-w-lg shadow-xl">
    <div class="pp-card-header">
      <h2 id="{{ $id }}-title" class="pp-card-title">{{ $title }}</h2>
      <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm -mr-2" x-on:click="open = false" aria-label="Zamknij"><x-pp.icon name="close" :size="16" /></button>
    </div>
    <div class="p-5">{{ $slot }}</div>
  </div>
</div>
