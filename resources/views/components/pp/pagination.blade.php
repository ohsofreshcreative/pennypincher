@props(['paginator', 'label' => 'pozycji'])

@if ($paginator->total > 0)
  <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3 text-sm" aria-label="Paginacja">
    <p class="pp-num text-ink-mute">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} z {{ number_format($paginator->total, 0, ',', ' ') }} {{ $label }}</p>

    @if ($paginator->lastPage > 1)
      <ul class="flex items-center gap-1">
        <li>
          @if ($paginator->page > 1)
            <a href="{{ esc_url(add_query_arg('page', $paginator->page - 1)) }}" class="pp-btn pp-btn-ghost pp-btn-sm" aria-label="Poprzednia strona"><x-pp.icon name="chevron-left" :size="16" /></a>
          @endif
        </li>
        @foreach ($paginator->window() as $page)
          <li>
            @if ($page === null)
              <span class="px-2 text-ink-mute">…</span>
            @elseif ($page === $paginator->page)
              <span class="pp-btn pp-btn-sm pp-num border-line-strong bg-paper" aria-current="page">{{ $page }}</span>
            @else
              <a href="{{ esc_url(add_query_arg('page', $page)) }}" class="pp-btn pp-btn-ghost pp-btn-sm pp-num">{{ $page }}</a>
            @endif
          </li>
        @endforeach
        <li>
          @if ($paginator->page < $paginator->lastPage)
            <a href="{{ esc_url(add_query_arg('page', $paginator->page + 1)) }}" class="pp-btn pp-btn-ghost pp-btn-sm" aria-label="Następna strona"><x-pp.icon name="chevron-right" :size="16" /></a>
          @endif
        </li>
      </ul>
    @endif
  </nav>
@endif
