{{-- Asystent (pp2_assistant): wyjaśnia istniejące wyniki i wskazuje następny krok. Tylko odczyt – bez formularzy zapisu. --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Assistant\Explainer;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $link = function (array $step, array $rec, ?array $change) use ($project) {
    return match ($step['key']) {
      'change' => AppUrl::project($project->id, 'changes/' . (int) $change['id']),
      'monitoring' => AppUrl::project($project->id, 'monitoring'),
      'action' => AppUrl::project($project->id, 'action-plan'),
      default => AppUrl::project($project->id, 'recommendations', ['obsolete' => 'all', 'open' => $rec['id']]) . '#rec-' . $rec['id'],
    };
  };
@endphp

@section('content')
  <x-pp.alert type="info" title="Asystent wyjaśnia, nie decyduje">
    Wyjaśnienia powstają z wyników reguł Penny Pincher ({{ Explainer::ENGINE }}) – asystent nie liczy nowych wartości i nie wymyśla dowodów.
    Działa tylko do odczytu: decyzje podejmujesz w Rekomendacjach, a zmiany w Google Ads trafiają na konto wyłącznie przez Change Center po Twoim zatwierdzeniu.
  </x-pp.alert>

  @if ($explained)
    @php $rec = $explained['recommendation']; $e = $explained['explanation']; @endphp
    <a href="{{ AppUrl::project($project->id, 'assistant') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-mint hover:underline"><x-pp.icon name="chevron-left" :size="16" />Podsumowanie asystenta</a>
    <article class="pp-card p-5" aria-labelledby="pp-explain-title" data-explain="{{ $rec['id'] }}">
      <h2 id="pp-explain-title" class="pp-card-title">{{ $rec['title'] }}</h2>
      <h3 class="mt-4 text-sm font-semibold text-ink">Co wykryto</h3>
      <p class="mt-1 text-sm text-ink-soft">{{ $e['what'] }}</p>
      <h3 class="mt-4 text-sm font-semibold text-ink">Na podstawie czego</h3>
      <dl class="mt-1 grid gap-x-6 gap-y-1 text-meta sm:grid-cols-2">
        @foreach ($e['basis'] as $label => $value)
          <div><dt class="inline text-ink-mute">{{ $label }}:</dt> <dd class="inline">{{ $value }}</dd></div>
        @endforeach
      </dl>
      @if ($e['evidence'] !== [])
        <ul class="mt-2 space-y-0.5 text-meta text-ink-soft" data-evidence>
          @foreach ($e['evidence'] as $pair)
            <li><span class="text-ink">{{ $pair[0] ?? '' }}</span>@if (isset($pair[1])): {{ $pair[1] }}@endif</li>
          @endforeach
        </ul>
      @endif
      @if ($e['estimate'])
        <p class="mt-2 text-meta"><span class="pp-badge pp-badge-warn">Szacunek</span> {{ $e['estimate'] }}</p>
      @endif
      <h3 class="mt-4 text-sm font-semibold text-ink">Proponowane działanie</h3>
      <p class="mt-1 text-sm text-ink-soft">{{ $e['action'] }}</p>
      @if ($e['caveats'] !== [])
        <ul class="mt-3 space-y-1 text-caption text-ink-mute">
          @foreach ($e['caveats'] as $caveat)<li>{{ $caveat }}</li>@endforeach
        </ul>
      @endif
      <h3 class="mt-4 text-sm font-semibold text-ink">Następny krok</h3>
      <ul class="mt-1 space-y-1 text-sm">
        @foreach ($e['next'] as $step)
          <li data-next="{{ $step['key'] }}">
            <a href="{{ $link($step, $rec, $explained['change']) }}" class="font-semibold text-mint hover:underline">{{ $step['label'] }}</a>
            @if ($step['consent'])<span class="pp-badge pp-badge-info ml-1">wymaga Twojej zgody</span>@endif
          </li>
        @endforeach
      </ul>
    </article>
  @else
    <section class="pp-card min-w-0" aria-labelledby="pp-assistant-today">
      <div class="pp-card-header">
        <div>
          <h2 id="pp-assistant-today" class="pp-card-title">Co warto zrobić dzisiaj</h2>
          <p class="mt-0.5 text-meta text-ink-mute">
            Do oceny: <span class="pp-num">{{ $digest['to_review'] }}</span> · zaakceptowane do wykonania: <span class="pp-num">{{ $digest['accepted'] }}</span>.
            Ostatnia analiza: {{ $digest['freshness']['analysis_at'] ? Format::datetime($digest['freshness']['analysis_at']) : 'brak' }}@if ($digest['freshness']['connected']) · synchronizacja: {{ $digest['freshness']['sync_at'] ? Format::datetime($digest['freshness']['sync_at']) : 'jeszcze nie wykonano' }}@endif.
          </p>
        </div>
      </div>
      @if ($digest['top'] === [])
        <p class="px-5 py-4 text-sm text-ink-soft" data-empty>
          @if (! $digest['freshness']['analysis_at'])
            Brak analizy – uruchom ją w <a href="{{ AppUrl::project($project->id, 'audits') }}" class="font-semibold text-mint hover:underline">Optymalizacji</a>, a asystent wyjaśni wyniki.
          @else
            Brak nowych rekomendacji do oceny.
          @endif
        </p>
      @else
        <ol class="divide-y divide-line">
          @foreach ($digest['top'] as $item)
            @php $rec = $item['recommendation']; $e = $item['explanation']; @endphp
            <li class="px-5 py-3" data-top="{{ $rec['id'] }}">
              <a href="{{ AppUrl::project($project->id, 'assistant', ['recommendation' => $rec['id']]) }}" class="text-sm font-medium text-ink hover:text-mint">{{ $rec['title'] }}</a>
              <span class="block text-caption text-ink-mute">{{ $e['basis']['Źródło'] }} · pewność: {{ $e['basis']['Pewność'] }}@if ($e['estimate']) · szacunek: {{ $e['estimate'] }}@endif</span>
              <span class="block text-caption text-ink-soft">{{ $e['next'][0]['label'] }}</span>
            </li>
          @endforeach
        </ol>
      @endif
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
      <section class="pp-card min-w-0" aria-labelledby="pp-assistant-consent">
        <div class="pp-card-header"><h2 id="pp-assistant-consent" class="pp-card-title">Czeka na Twoją zgodę</h2></div>
        @if ($digest['consent'] === [])
          <p class="px-5 py-4 text-sm text-ink-soft">Żadna zmiana na koncie nie czeka na zatwierdzenie ani wdrożenie.</p>
        @else
          <ul class="divide-y divide-line text-sm">
            @foreach ($digest['consent'] as $set)
              <li class="px-5 py-2" data-consent="{{ $set['id'] }}"><a href="{{ AppUrl::project($project->id, 'changes/' . $set['id']) }}" class="font-medium text-ink hover:text-mint">#{{ $set['id'] }} {{ $set['title'] }}</a> <span class="text-ink-mute">– {{ \App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus::LABELS[$set['status']] ?? $set['status'] }}</span></li>
            @endforeach
          </ul>
        @endif
      </section>

      <section class="pp-card min-w-0" aria-labelledby="pp-assistant-alerts">
        <div class="pp-card-header"><h2 id="pp-assistant-alerts" class="pp-card-title">Alerty monitoringu</h2></div>
        @if ($digest['alerts'] === [])
          <p class="px-5 py-4 text-sm text-ink-soft">Brak otwartych alertów.</p>
        @else
          <ul class="divide-y divide-line text-sm">
            @foreach ($digest['alerts'] as $alert)
              <li class="flex items-center justify-between gap-2 px-5 py-2"><a href="{{ AppUrl::project($project->id, 'monitoring', ['open' => $alert['id']]) }}#alert-{{ $alert['id'] }}" class="font-medium text-ink hover:text-mint">{{ $alert['title'] }}</a> <x-pp.status-badge :status="$alert['severity']" /></li>
            @endforeach
          </ul>
        @endif
      </section>
    </div>

    <p class="text-caption text-ink-mute">Asystent nie ma własnego harmonogramu. Wyniki odświeża istniejące zadanie <code>pp:sync:work</code> (synchronizacja → analiza → monitoring) albo ręczne uruchomienie analizy.</p>
  @endif
@endsection
