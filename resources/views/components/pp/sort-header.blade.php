@props(['key', 'label', 'sort', 'direction', 'numeric' => false])

@php
  $active = $sort === $key;
  $nextDirection = $active && $direction === 'desc' ? 'asc' : 'desc';
  $url = add_query_arg(['sort' => $key, 'dir' => $nextDirection, 'page' => null]);
@endphp

<th @class(['pp-col-num' => $numeric]) aria-sort="{{ $active ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}" {{ $attributes }}>
  <a href="{{ esc_url($url) }}" @class(['inline-flex items-center gap-1 hover:text-ink', 'text-ink' => $active])>
    {{ $label }}
    <x-pp.icon :name="$active ? ($direction === 'asc' ? 'sort-asc' : 'sort-desc') : 'sort'" :size="14" @class(['opacity-40' => ! $active]) />
  </a>
</th>
