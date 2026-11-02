{{-- Ścieżka okruszków. Ostatni element to bieżąca strona (bez linku). --}}
@props(['items'])

<nav aria-label="Ścieżka" {{ $attributes->merge(['class' => 'min-w-0']) }}>
  <ol class="flex min-w-0 items-center gap-1.5 text-caption text-ink-mute">
    @foreach ($items as $item)
      <li @class(['flex min-w-0 items-center gap-1.5', 'shrink-0' => ! $loop->last])>
        @if (! $loop->first)
          <x-pp.icon name="chevron-right" :size="12" />
        @endif
        @if ($loop->last)
          <span class="truncate" aria-current="page">{{ $item['label'] }}</span>
        @elseif (! empty($item['url']))
          <a href="{{ $item['url'] }}" class="truncate hover:text-ink hover:underline">{{ $item['label'] }}</a>
        @else
          <span class="truncate">{{ $item['label'] }}</span>
        @endif
      </li>
    @endforeach
  </ol>
</nav>
