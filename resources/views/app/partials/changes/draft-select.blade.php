{{-- Wybór szkicu, do którego trafi przygotowana operacja. Zmienna: $drafts. --}}
@if (! empty($drafts))
  <div>
    <label class="pp-label" for="{{ $id ?? 'f-change-set' }}">Dodaj do szkicu</label>
    <select id="{{ $id ?? 'f-change-set' }}" name="change_set_id" class="pp-input pp-input-sm">
      <option value="">Nowy szkic</option>
      @foreach ($drafts as $draft)
        <option value="{{ $draft['id'] }}">#{{ $draft['id'] }} {{ \Illuminate\Support\Str::limit($draft['title'], 50) }}</option>
      @endforeach
    </select>
  </div>
@endif
