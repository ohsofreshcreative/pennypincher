{{-- Uruchomienie pełnej analizy okresu (audyt, Budget Leaks, słowa kluczowe, rekomendacje). Tylko dane z bazy. --}}
@php
  $variant = $variant ?? 'primary';
@endphp
<form method="post" action="{{ \App\PennyPincher\Support\AppUrl::project($project->id, 'analysis') }}" data-busy>
  <x-pp.nonce />
  @include('app.partials.period-fields', ['period' => $period])
  <input type="hidden" name="return" value="{{ $return }}">
  <x-pp.button :variant="$variant" :size="$size ?? null" icon="refresh" data-busy-label="Analiza…">{{ $label ?? 'Uruchom analizę okresu' }}</x-pp.button>
</form>
