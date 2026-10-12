@props(['type' => 'info', 'title' => null])

@php
  $styles = [
    'success' => ['border-mint/30 bg-mint-soft text-mint-dark', 'check'],
    'error' => ['border-danger/30 bg-danger-soft text-danger', 'alert'],
    'warning' => ['border-warn/30 bg-warn-soft text-warn', 'alert'],
    'info' => ['border-info/25 bg-info-soft text-info', 'info'],
  ];
  [$classes, $icon] = $styles[$type] ?? $styles['info'];
@endphp

<div {{ $attributes->merge(['class' => 'flex gap-3 rounded-[var(--radius-card)] border px-4 py-3 text-sm leading-6 ' . $classes]) }} role="{{ $type === 'error' ? 'alert' : 'status' }}">
  <x-pp.icon :name="$icon" class="mt-0.5" />
  <div class="min-w-0 text-ink">
    @if ($title)<p class="font-semibold">{{ $title }}</p>@endif
    {{ $slot }}
  </div>
</div>
