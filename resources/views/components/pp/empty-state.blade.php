@props(['title', 'icon' => 'import'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-start gap-4 px-6 py-10 sm:px-10 sm:py-14']) }}>
  <span class="grid size-11 place-items-center rounded-full bg-copper-soft text-copper">
    <x-pp.icon :name="$icon" :size="22" />
  </span>
  <div class="max-w-xl">
    <h2 class="text-lg font-semibold text-ink">{{ $title }}</h2>
    <div class="mt-1.5 text-ink-soft">{{ $slot }}</div>
  </div>
  @isset($actions)
    <div class="flex flex-wrap gap-3">{{ $actions }}</div>
  @endisset
</div>
