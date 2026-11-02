{{-- Plan działania › Kolejka (pp2_action_plan): rekomendacje, zadania, zmiany na koncie i alerty wg istniejących statusów. --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\ActionPlan\Inbox;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $badge = ['ok' => 'done', 'warn' => 'partial', 'danger' => 'failed', 'info' => 'info', 'neutral' => 'removed'];
  $hints = [
    'review' => 'Nowe i do sprawdzenia rekomendacje oraz szkice zmian czekające na przegląd.',
    'accepted' => 'Zaakceptowane rekomendacje, zadania do zrobienia i zatwierdzone zmiany, które czekają na świadome wdrożenie.',
    'in_progress' => 'Zadania w trakcie i zablokowane, wdrożenia w kolejce oraz zmiany, które wymagają uzgodnienia albo zakończyły się błędem.',
    'done' => 'Rekomendacje i zadania oznaczone przez Ciebie jako wykonane oraz zmiany wdrożone w Google Ads.',
    'rejected' => 'Odrzucone rekomendacje i zadania oraz anulowane zestawy zmian.',
    'monitoring' => 'Otwarte alerty monitoringu. Efekty wdrożonych zmian i pełna historia są w Monitoringu i w Zmianach na koncie.',
  ];
@endphp

@section('content')
  <nav class="-mx-1 flex gap-1 overflow-x-auto border-b border-line px-1" aria-label="Kolejka planu działania">
    @foreach (Inbox::TABS as $key => $label)
      <a href="{{ AppUrl::project($project->id, 'action-plan/inbox', ['tab' => $key]) }}" @class(['-mb-px shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium', 'border-mint text-ink' => $tab === $key, 'border-transparent text-ink-mute hover:text-ink' => $tab !== $key]) @if ($tab === $key) aria-current="page" @endif>
        {{ $label }} <span class="pp-num text-ink-mute" data-count="{{ $key }}">({{ $counts[$key] }})</span>
      </a>
    @endforeach
  </nav>

  <section class="pp-card min-w-0" aria-labelledby="pp-inbox-title">
    <div class="pp-card-header">
      <div>
        <h2 id="pp-inbox-title" class="pp-card-title">{{ Inbox::TABS[$tab] }}</h2>
        <p class="mt-0.5 text-meta text-ink-mute">{{ $hints[$tab] }} Status zmieniasz w module, z którego pochodzi pozycja.</p>
      </div>
    </div>

    @if ($items === [])
      <p class="px-5 py-4 text-sm text-ink-soft">Brak pozycji w tej zakładce.</p>
    @else
      <ul class="divide-y divide-line">
        @foreach ($items as $item)
          <li class="flex flex-wrap items-start gap-3 px-5 py-3" data-inbox="{{ $item['kind'] }}">
            <span class="w-32 shrink-0 text-caption font-medium text-ink-mute">{{ Inbox::KINDS[$item['kind']] }}</span>
            <span class="min-w-0 flex-1">
              <a href="{{ $item['url'] }}" class="block text-sm font-medium text-ink hover:text-mint">{{ $item['title'] }}</a>
              <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-caption text-ink-mute">
                @if ($item['updated_at'])<span class="pp-num">{{ Format::datetime($item['updated_at']) }}</span>@endif
                @if ($item['change'])
                  <a href="{{ $item['change']['url'] }}" class="hover:text-mint">Zmiana na koncie #{{ $item['change']['id'] }}: {{ $item['change']['label'] }}</a>
                @endif
                @if ($item['trace'])
                  <a href="{{ $item['trace'] }}" class="font-semibold text-mint hover:underline">Ścieżka rekomendacji</a>
                @endif
              </span>
              @if ($item['note'])<span class="mt-0.5 block text-caption text-ink-soft">{{ $item['note'] }}</span>@endif
            </span>
            @if (in_array($item['priority'], ['high', 'medium', 'low', 'critical', 'warning', 'info'], true))
              <x-pp.status-badge :status="$item['priority']" />
            @endif
            <x-pp.status-badge :status="$badge[$item['tone']] ?? 'removed'" :label="$item['status_label']" />
          </li>
        @endforeach
      </ul>
      @if (count($items) >= \App\PennyPincher\Services\ActionPlan\InboxService::LIMIT)
        <p class="border-t border-line px-5 py-3 text-caption text-ink-mute">Pokazano pierwsze {{ \App\PennyPincher\Services\ActionPlan\InboxService::LIMIT }} pozycji każdego rodzaju – pełne listy z filtrami są w modułach.</p>
      @endif
    @endif
  </section>

  <section class="pp-card p-5" aria-label="Moduły planu działania">
    <p class="text-sm text-ink-soft">Pełne widoki z filtrami, historią i akcjami:</p>
    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2 text-meta font-semibold">
      <a href="{{ AppUrl::project($project->id, 'recommendations') }}" class="text-mint hover:underline">Rekomendacje</a>
      <a href="{{ AppUrl::project($project->id, 'action-plan') }}" class="text-mint hover:underline">Zadania (Action Plan)</a>
      <a href="{{ AppUrl::project($project->id, 'changes') }}" class="text-mint hover:underline">Zmiany na koncie (Change Center)</a>
      <a href="{{ AppUrl::project($project->id, 'monitoring') }}" class="text-mint hover:underline">Monitoring i efekty zmian</a>
    </div>
    <p class="mt-3 text-caption text-ink-mute">Zmiany w Google Ads trafiają na konto wyłącznie przez Change Center: podgląd „przed / po” → zatwierdzenie → świadome polecenie wdrożenia z potwierdzeniem → wynik API i weryfikacja odczytem.</p>
  </section>
@endsection
