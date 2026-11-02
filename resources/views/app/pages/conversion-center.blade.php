{{-- Centrum konwersji (pp2_conversions): Wyniki | Cele i zdarzenia | Diagnostyka | Historia. Sekcje = te same partiale co w Konwersjach i Conversion Management. --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Conversions\ConversionClass;
  use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
  use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
  use App\PennyPincher\Services\Conversions\ConversionCenterService;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $query = array_diff_key(request()->query(), ['tab' => true]);
  $metric = fn ($value) => $value === null ? 'Brak danych' : Format::conversions($value);
@endphp

@section('content')
  <nav class="-mx-1 flex gap-1 overflow-x-auto border-b border-line px-1" aria-label="Centrum konwersji">
    @foreach (ConversionCenterService::TABS as $key => $label)
      <a href="{{ AppUrl::project($project->id, 'conversions/center', ['tab' => $key] + $query) }}" @class(['-mb-px shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium', 'border-mint text-ink' => $tab === $key, 'border-transparent text-ink-mute hover:text-ink' => $tab !== $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
  </nav>

  @if ($tab === 'results')
    @include('app.partials.coverage', ['resolved' => $resolved, 'reportLabel' => 'raportu konwersji'])

    @include('app.partials.conversions.summary')

    <section class="pp-card min-w-0" aria-labelledby="pp-conv-campaigns" data-tab="results">
      <div class="pp-card-header">
        <div>
          <h2 id="pp-conv-campaigns" class="pp-card-title">Konwersje według kampanii</h2>
          <p class="mt-0.5 text-meta text-ink-mute">Okres {{ $period->range->label() }}. Wiersze kampanii nie są sumowane do wyniku konta – konto może zawierać konwersje spoza kampanii z danymi.</p>
        </div>
      </div>
      @if ($campaigns === [])
        <p class="px-5 py-4 text-sm text-ink-soft">Brak danych o konwersjach per kampania dla tego okresu.</p>
      @else
        <div class="pp-table-wrap">
          <table class="pp-table">
            <thead>
              <tr><th class="pp-col-sticky">Kampania</th><th class="pp-col-num">Konwersje Google Ads</th><th class="pp-col-num">Cele biznesowe</th><th class="pp-col-num">Leady</th><th class="pp-col-num">Potwierdzone leady</th><th class="pp-col-num">Potencjalne kontakty</th><th class="pp-col-num">Mikrokonwersje</th></tr>
            </thead>
            <tbody>
              @foreach ($campaigns as $c)
                <tr data-campaign="{{ $c['id'] }}">
                  <td class="pp-col-sticky max-w-[18rem] break-words"><a href="{{ AppUrl::project($project->id, 'campaigns/' . $c['id']) }}" class="font-medium text-ink hover:text-mint">{{ $c['name'] }}</a></td>
                  <td class="pp-col-num">{{ $metric($c['google']) }}</td>
                  <td class="pp-col-num">{{ $metric($c['goals']) }}</td>
                  <td class="pp-col-num">{{ $metric($c['leads']) }}</td>
                  <td class="pp-col-num">{{ $metric($c['confirmed']) }}</td>
                  <td class="pp-col-num">{{ $metric($c['potential']) }}</td>
                  <td class="pp-col-num">{{ $metric($c['micro']) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
      <p class="border-t border-line px-5 py-3 text-caption text-ink-mute">„Konwersje Google Ads” to kolumna „Konwersje” w modelu atrybucji ustawionym dla każdego działania w Google Ads (model widać w „Cele i zdarzenia” po odczycie z Google Ads). Pozostałe kolumny to klasyfikacja Penny Pincher tych samych działań – nie dodawaj ich do siebie.</p>
    </section>
  @elseif ($tab === 'events')
    @include('app.partials.conversions.cm-intro')

    @php $models = array_values(array_unique(array_filter(array_column($overview['actions'], 'attribution_model')))); @endphp
    <p class="text-meta text-ink-mute" data-tab="events">
      Modele atrybucji w Google Ads:
      {{ $models === [] ? 'nieznane – wymagają odczytu konfiguracji z Google Ads' : implode(', ', $models) }}.
      Liczba konwersji w tabeli działań dotyczy ostatnich 90 dni, niezależnie od okresu wybranego w nagłówku.
    </p>

    @include('app.partials.conversions.classification')

    @include('app.partials.conversions.actions')

    @include('app.partials.conversions.new-action')
  @elseif ($tab === 'diagnostics')
    <div class="space-y-6" data-tab="diagnostics">
      @include('app.partials.conversions.health')

      @include('app.partials.conversions.goals')
    </div>
    <p class="text-meta text-ink-mute">Odczyt z Google Ads: {{ $overview['api_read_at'] ? Format::datetime($overview['api_read_at']) : 'jeszcze nie wykonano' }}. Odczyt uruchomisz w zakładce <a href="{{ AppUrl::project($project->id, 'conversions/center', ['tab' => 'events'] + $query) }}" class="font-semibold text-mint hover:underline">Cele i zdarzenia</a>.</p>
  @else
    <section class="pp-card min-w-0" aria-labelledby="pp-conv-history-sets" data-tab="history">
      <div class="pp-card-header">
        <div>
          <h2 id="pp-conv-history-sets" class="pp-card-title">Zmiany konwersji i celów w Google Ads</h2>
          <p class="mt-0.5 text-meta text-ink-mute">Zestawy Change Center z operacjami na działaniach konwersji i celach. Pełna historia (zgoda, wdrożenie, wynik API) – w szczegółach zestawu.</p>
        </div>
      </div>
      @if ($history['sets'] === [])
        <p class="px-5 py-4 text-sm text-ink-soft">Nie przygotowano jeszcze zmian konwersji ani celów.</p>
      @else
        <ul class="divide-y divide-line">
          @foreach ($history['sets'] as $set)
            <li class="flex flex-wrap items-start gap-3 px-5 py-3" data-history-set="{{ $set['id'] }}">
              <span class="min-w-0 flex-1">
                <a href="{{ AppUrl::project($project->id, 'changes/' . $set['id']) }}" class="block text-sm font-medium text-ink hover:text-mint">#{{ $set['id'] }} {{ $set['title'] }}</a>
                <span class="text-caption text-ink-mute">
                  {{ implode(', ', array_map(fn ($t) => OperationCatalog::TYPES[$t]['label'] ?? $t, explode(',', (string) $set['types']))) }}
                  · utworzono <span class="pp-num">{{ Format::datetime($set['created_at']) }}</span>
                  @if ($set['deploy_finished_at']) · wdrożenie <span class="pp-num">{{ Format::datetime($set['deploy_finished_at']) }}</span>@endif
                </span>
              </span>
              <x-pp.status-badge :status="['ok' => 'done', 'warn' => 'partial', 'danger' => 'failed', 'info' => 'info', 'neutral' => 'removed'][ChangeStatus::TONES[$set['status']] ?? 'neutral'] ?? 'removed'" :label="ChangeStatus::LABELS[$set['status']] ?? $set['status']" />
            </li>
          @endforeach
        </ul>
      @endif
    </section>

    <section class="pp-card min-w-0" aria-labelledby="pp-conv-history-class">
      <div class="pp-card-header">
        <div>
          <h2 id="pp-conv-history-class" class="pp-card-title">Klasyfikacja w Penny Pincher</h2>
          <p class="mt-0.5 text-meta text-ink-mute">Ostatnia ręczna zmiana klasy każdego działania (Penny Pincher przechowuje tylko ostatnią). Nie zmienia ustawień w Google Ads.</p>
        </div>
      </div>
      @if ($history['classified'] === [])
        <p class="px-5 py-4 text-sm text-ink-soft">Żadne działanie nie zostało sklasyfikowane ręcznie – obowiązują propozycje z kategorii Google Ads.</p>
      @else
        <ul class="divide-y divide-line text-sm">
          @foreach ($history['classified'] as $row)
            <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2" data-history-class="{{ $row['id'] }}">
              <span class="min-w-0 break-words"><span class="font-medium text-ink">{{ $row['name'] }}</span> <span class="text-ink-mute">→ {{ ConversionClass::tryFrom((string) $row['business_class'])?->label() ?? '—' }}{{ (int) $row['business_goal'] === 1 ? ' · cel biznesowy' : '' }}</span></span>
              <span class="text-caption text-ink-mute">{{ $row['user_name'] ?: '—' }} · <span class="pp-num">{{ Format::datetime($row['classified_at']) }}</span></span>
            </li>
          @endforeach
        </ul>
      @endif
    </section>
  @endif
@endsection
