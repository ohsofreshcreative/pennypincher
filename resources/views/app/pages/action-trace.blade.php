{{-- Ścieżka rekomendacji: decyzja → zadanie → zgoda → wdrożenie (wynik API, weryfikacja) → monitoring efektów. Tylko odczyt. --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
  use App\PennyPincher\Domain\Recommendations\RecommendationGenerator;
  use App\PennyPincher\Repositories\ActionItemRepository;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $rec = $trace['recommendation'];
  $user = fn ($id) => $id ? (get_userdata((int) $id)->display_name ?? 'Użytkownik #' . (int) $id) : '—';
  $recLabel = fn ($s) => $s ? (RecommendationGenerator::STATUSES[$s] ?? $s) : '—';
  $eventNames = ['created' => 'Utworzono', 'status' => 'Zmiana statusu', 'note' => 'Notatka', 'deployed' => 'Wdrożono zmiany', 'obsolete' => 'Nieaktualne', 'restored' => 'Ponownie aktualne', 'blocked' => 'Zablokowane (zależność)', 'unblocked' => 'Odblokowane'];
  $eventName = fn ($e) => $eventNames[$e] ?? $e;
  $outcomes = ['ok' => 'OK', 'error' => 'Błąd', 'partial' => 'Częściowo', 'ambiguous' => 'Wynik nieznany'];
  $kinds = ['api_validation' => 'Walidacja API (bez zapisu)', 'execute' => 'Wysłanie zmian', 'reconcile' => 'Uzgodnienie stanu', 'verify' => 'Weryfikacja odczytem', 'read' => 'Odczyt stanu'];
@endphp

@section('content')
  <a href="{{ AppUrl::project($project->id, 'action-plan/inbox') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-mint hover:underline">
    <x-pp.icon name="chevron-left" :size="16" />Kolejka planu działania
  </a>

  <ol class="space-y-4" aria-label="Etapy rekomendacji">
    {{-- 1. Rekomendacja i decyzja użytkownika --}}
    <li class="pp-card p-5" data-step="recommendation">
      <p class="text-caption font-medium text-ink-mute">1. Rekomendacja i decyzja</p>
      <h2 class="mt-1 pp-card-title">{{ $rec['title'] }}</h2>
      <p class="mt-1 text-sm text-ink-soft">
        Źródło: {{ implode(' + ', array_map(fn ($s) => RecommendationGenerator::SOURCES[$s] ?? $s, $rec['sources'])) }}
        · okres {{ Format::period($rec['date_from'] ?? null, $rec['date_to'] ?? null) }}
        · status: <strong class="font-semibold text-ink">{{ $recLabel($rec['status']) }}</strong>@if ($rec['is_obsolete']) (nieaktualna)@endif
      </p>
      <a href="{{ AppUrl::project($project->id, 'recommendations', ['obsolete' => 'all', 'open' => $rec['id']]) }}#rec-{{ $rec['id'] }}" class="mt-2 inline-block text-meta font-semibold text-mint hover:underline">Dowody i decyzja w Rekomendacjach</a>
      @if (\App\PennyPincher\Support\Feature::enabled(\App\PennyPincher\Support\Feature::ASSISTANT))
        <a href="{{ AppUrl::project($project->id, 'assistant', ['recommendation' => $rec['id']]) }}" class="ml-4 mt-2 inline-block text-meta font-semibold text-mint hover:underline">Wyjaśnienie asystenta</a>
      @endif
      @if ($trace['events'] !== [])
        <ul class="mt-3 space-y-1 border-t border-line pt-3 text-caption text-ink-soft">
          @foreach ($trace['events'] as $e)
            <li><span class="pp-num text-ink-mute">{{ Format::datetime($e['created_at']) }}</span> · {{ $e['user_name'] ?: 'System' }} · {{ $eventName($e['event']) }}@if ($e['to_status']): {{ $recLabel($e['from_status'] ?? null) }} → {{ $recLabel($e['to_status']) }}@endif @if ($e['note']) – {{ $e['note'] }}@endif</li>
          @endforeach
        </ul>
      @endif
    </li>

    {{-- 2. Zadania Action Plan --}}
    <li class="pp-card p-5" data-step="actions">
      <p class="text-caption font-medium text-ink-mute">2. Zadania w planie działania</p>
      @if ($trace['items'] === [])
        <p class="mt-1 text-sm text-ink-soft">Rekomendacja nie trafiła do zadań planu działania.</p>
      @else
        <ul class="mt-2 space-y-2">
          @foreach ($trace['items'] as $item)
            <li class="text-sm">
              <a href="{{ AppUrl::project($project->id, 'action-plan', ['status' => $item['status'], 'obsolete' => 'all', 'open' => $item['id']]) }}#action-{{ $item['id'] }}" class="font-medium text-ink hover:text-mint">{{ $item['title'] }}</a>
              <span class="text-ink-mute">– {{ ActionItemRepository::STATUSES[$item['status']] ?? $item['status'] }}</span>
              @foreach (array_slice($item['events'], 0, 5) as $e)
                <span class="block text-caption text-ink-soft"><span class="pp-num text-ink-mute">{{ Format::datetime($e['created_at']) }}</span> · {{ $e['user_name'] ?: 'System' }} · {{ $eventName($e['event']) }}@if ($e['to_status']) → {{ ActionItemRepository::STATUSES[$e['to_status']] ?? $e['to_status'] }}@endif</span>
              @endforeach
            </li>
          @endforeach
        </ul>
      @endif
    </li>

    {{-- 3–4. Zgoda i wykonanie w Google Ads (Change Center) --}}
    <li class="pp-card p-5" data-step="changes">
      <p class="text-caption font-medium text-ink-mute">3. Zgoda i wykonanie w Google Ads</p>
      @if ($trace['changes'] === [])
        <p class="mt-1 text-sm text-ink-soft">Nie przygotowano zmian na koncie. Wykonanie ręczne potwierdza status „Wykonana” ustawiony przez użytkownika.</p>
      @else
        @foreach ($trace['changes'] as $c)
          @php $set = $c['set']; $ops = $c['operations']; @endphp
          <div class="mt-3 border-t border-line pt-3 first:mt-1 first:border-t-0 first:pt-0" data-change="{{ $set['id'] }}">
            <a href="{{ AppUrl::project($project->id, 'changes/' . $set['id']) }}" class="font-semibold text-ink hover:text-mint">#{{ $set['id'] }} {{ $set['title'] }}</a>
            <span class="text-sm text-ink-mute">– {{ ChangeStatus::LABELS[$set['status']] ?? $set['status'] }}</span>
            <dl class="mt-2 grid gap-x-6 gap-y-1 text-caption sm:grid-cols-2">
              <div><dt class="inline text-ink-mute">Zatwierdził:</dt> <dd class="inline">{{ $user($set['approved_by']) }}@if ($set['approved_at']), {{ Format::datetime($set['approved_at']) }}@endif</dd></div>
              <div><dt class="inline text-ink-mute">Polecenie wdrożenia:</dt> <dd class="inline">{{ $user($set['deploy_requested_by']) }}@if ($set['deploy_requested_at']), {{ Format::datetime($set['deploy_requested_at']) }}@endif</dd></div>
              <div><dt class="inline text-ink-mute">Zakończenie:</dt> <dd class="inline">{{ $set['deploy_finished_at'] ? Format::datetime($set['deploy_finished_at']) : '—' }}</dd></div>
              <div><dt class="inline text-ink-mute">Weryfikacja odczytem:</dt> <dd class="inline">{{ $set['verified_at'] ? Format::datetime($set['verified_at']) : '—' }}</dd></div>
            </dl>
            <ul class="mt-2 space-y-1 text-caption">
              @foreach ($ops as $op)
                <li><span class="text-ink">{{ $op['label'] }}</span> <span class="text-ink-mute">– {{ ChangeStatus::OP_LABELS[$op['status']] ?? $op['status'] }}</span>@if ($op['error_message']) <span class="text-ink-soft">({{ $op['error_message'] }})</span>@endif</li>
              @endforeach
            </ul>
            @if ($c['attempts'] !== [])
              <p class="mt-2 text-caption font-medium text-ink-mute">Kontakt z Google Ads API</p>
              <ul class="space-y-0.5 text-caption text-ink-soft">
                @foreach (array_slice($c['attempts'], 0, 8) as $a)
                  <li><span class="pp-num text-ink-mute">{{ Format::datetime($a['created_at']) }}</span> · {{ $kinds[$a['kind']] ?? $a['kind'] }}: {{ $outcomes[$a['outcome']] ?? $a['outcome'] }}@if ($a['request_id']) · request-id {{ $a['request_id'] }}@endif @if ($a['error_code']) · {{ $a['error_code'] }}@endif</li>
                @endforeach
              </ul>
            @endif
            <p class="mt-2 text-caption font-medium text-ink-mute">4. Wynik i monitoring efektów</p>
            @php $e = $c['effects']; @endphp
            @if ($e['available'])
              <p class="text-caption text-ink-soft">Koszt {{ Format::money((int) $e['before']['cost_micros'], $project->currency, 0) }} → {{ Format::money((int) $e['after']['cost_micros'], $project->currency, 0) }} · konwersje {{ Format::conversions($e['before']['conversions']) }} → {{ Format::conversions($e['after']['conversions']) }}. Porównanie, nie dowód przyczynowości.</p>
            @else
              <p class="text-caption text-ink-soft">{{ $e['message'] }}</p>
            @endif
          </div>
        @endforeach
      @endif
    </li>

    {{-- 5. Alerty monitoringu powiązane z rekomendacją --}}
    <li class="pp-card p-5" data-step="monitoring">
      <p class="text-caption font-medium text-ink-mute">5. Monitoring</p>
      @if ($trace['alerts'] === [])
        <p class="mt-1 text-sm text-ink-soft">Brak alertów powiązanych z tą rekomendacją. Efekty wszystkich wdrożonych zmian: <a href="{{ AppUrl::project($project->id, 'monitoring') }}" class="font-semibold text-mint hover:underline">Monitoring</a>.</p>
      @else
        <ul class="mt-2 space-y-1 text-sm">
          @foreach ($trace['alerts'] as $a)
            <li><a href="{{ AppUrl::project($project->id, 'monitoring', ['open' => $a['id']]) }}#alert-{{ $a['id'] }}" class="font-medium text-ink hover:text-mint">{{ $a['title'] }}</a> <span class="text-ink-mute">– {{ \App\PennyPincher\Repositories\AlertRepository::STATUSES[$a['status']] ?? $a['status'] }}, {{ Format::datetime($a['last_detected_at']) }}</span></li>
          @endforeach
        </ul>
      @endif
    </li>
  </ol>
@endsection
