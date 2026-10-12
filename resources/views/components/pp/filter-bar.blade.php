@props(['action'])

<form method="get" action="{{ $action }}" role="search" {{ $attributes->merge(['class' => 'flex flex-wrap items-end gap-3 border-b border-line px-5 py-4']) }}>
  {{ $slot }}
  <div class="flex gap-2">
    <x-pp.button variant="secondary" size="sm">Filtruj</x-pp.button>
    <x-pp.button variant="ghost" size="sm" :href="$action">Wyczyść</x-pp.button>
  </div>
</form>
