@props(['label', 'value', 'hint' => null, 'tone' => null])

<div {{ $attributes->merge(['class' => 'min-w-0 px-5 py-4']) }}>
  <dt class="text-[13px] font-medium text-ink-mute">{{ $label }}</dt>
  <dd @class([
    'pp-num mt-1 truncate text-[22px] leading-7 font-semibold',
    'text-copper' => $tone === 'spend',
    'text-ink' => $tone === null,
  ])>{{ $value }}</dd>
  @if ($hint)
    <dd class="mt-0.5 text-[12.5px] leading-5 text-ink-mute">{{ $hint }}</dd>
  @endif
</div>
