@props(['variant' => 'primary', 'href' => null, 'size' => null, 'icon' => null, 'type' => 'submit'])

@php
  $classes = 'pp-btn pp-btn-' . $variant . ($size === 'sm' ? ' pp-btn-sm' : '');
@endphp

@if ($href)
  <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)<x-pp.icon :name="$icon" :size="$size === 'sm' ? 16 : 18" />@endif
    {{ $slot }}
  </a>
@else
  <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)<x-pp.icon :name="$icon" :size="$size === 'sm' ? 16 : 18" />@endif
    {{ $slot }}
  </button>
@endif
