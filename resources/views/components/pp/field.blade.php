@props(['name', 'label', 'type' => 'text', 'value' => null, 'errors' => [], 'help' => null, 'required' => false, 'options' => null, 'disabled' => false, 'rows' => 4])

@php
  $id = 'f-' . str_replace(['[', ']', '.'], '-', $name);
  $error = $errors[$name] ?? null;
  $describedBy = trim(($help ? $id . '-help ' : '') . ($error ? $id . '-error' : ''));
@endphp

<div {{ $attributes->only('class') }}>
  <label for="{{ $id }}" class="pp-label">
    {{ $label }}
    @unless ($required)<span class="font-normal text-ink-mute">(opcjonalnie)</span>@endunless
  </label>

  @if ($options !== null)
    <select id="{{ $id }}" name="{{ $name }}" class="pp-input" @required($required) @disabled($disabled)
      @if ($error) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
      @foreach ($options as $optionValue => $optionLabel)
        <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
      @endforeach
    </select>
  @elseif ($type === 'textarea')
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" class="pp-input" @required($required) @disabled($disabled)
      @if ($error) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>{{ $value }}</textarea>
  @else
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" class="pp-input" @required($required) @disabled($disabled)
      @if ($error) aria-invalid="true" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
      {{ $attributes->except('class') }}>
  @endif

  @if ($help)<p id="{{ $id }}-help" class="pp-help">{{ $help }}</p>@endif
  @if ($error)<p id="{{ $id }}-error" class="pp-error">{{ $error }}</p>@endif
</div>
