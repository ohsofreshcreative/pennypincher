@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
  use App\PennyPincher\Services\Changes\ChangeCenterService;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $total = array_sum($counts);
  $attention = ($counts[ChangeStatus::REVIEW] ?? 0) + ($counts[ChangeStatus::READY] ?? 0) + ($counts[ChangeStatus::RECONCILE] ?? 0) + ($counts[ChangeStatus::FAILED] ?? 0);
@endphp

@section('content')
  <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="max-w-3xl">
        <h2 class="pp-card-title">Change Center</h2>
        <p class="mt-1">Każda zmiana konta Google Ads przechodzi tę samą drogę: <strong class="text-ink">szkic → przegląd → zatwierdzenie → walidacja → świadome wdrożenie → weryfikacja → monitoring efektów</strong>. Kontroler aplikacji niczego nie wysyła do Google Ads – robi to wyłącznie worker, po zatwierdzeniu konkretnej wersji zestawu przez administratora i uruchomieniu wdrożenia przez uprawnionego wykonawcę.</p>
        <p class="mt-1">Szkice tworzysz w <a class="font-semibold text-mint underline" href="{{ AppUrl::project($project->id, 'management') }}">Google Ads Management</a>, <a class="font-semibold text-mint underline" href="{{ AppUrl::project($project->id, 'conversion-management') }}">Conversion Management</a> albo przyciskiem „Przygotuj zmianę” w rekomendacjach, Action Plan, wykluczeniach i Campaign Plannerze.</p>
      </div>
      @if ($canPrepare && $mode->canPrepare())
        <form method="post" action="{{ AppUrl::project($project->id, 'changes') }}" class="flex w-full flex-wrap items-end gap-2 sm:w-auto">
          <x-pp.nonce />
          <x-pp.field name="title" label="Nowy pusty szkic" required value="Zmiany {{ wp_date('d.m.Y') }}" class="min-w-0 flex-1 sm:w-64" />
          <x-pp.button variant="secondary" icon="plus">Utwórz</x-pp.button>
        </form>
      @endif
    </div>
  </section>

  @include('app.partials.changes.mode-banner')

  @include('app.partials.changes.legend')

  @if ($attention > 0)
    <x-pp.alert type="warning" title="Wymaga uwagi">
      @foreach ([ChangeStatus::REVIEW, ChangeStatus::READY, ChangeStatus::RECONCILE, ChangeStatus::FAILED] as $s)
        @if (($counts[$s] ?? 0) > 0)
          <a class="mr-3 inline-block font-semibold underline" href="{{ AppUrl::project($project->id, 'changes', ['status' => $s]) }}">{{ ChangeStatus::LABELS[$s] }}: {{ $counts[$s] }}</a>
        @endif
      @endforeach
    </x-pp.alert>
  @endif

  <section class="pp-card min-w-0" aria-label="Zestawy zmian">
    <div class="pp-card-header flex-wrap gap-3">
      <h2 class="pp-card-title">Zestawy zmian <span class="pp-num font-normal text-ink-mute">({{ $total }})</span></h2>
      <form method="get" class="flex flex-wrap items-center gap-2">
        <label for="f-status" class="sr-only">Status</label>
        <select id="f-status" name="status" class="pp-input pp-input-sm w-48" data-autosubmit>
          <option value="">Wszystkie statusy</option>
          @foreach (ChangeStatus::LABELS as $value => $label)
            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }} ({{ $counts[$value] ?? 0 }})</option>
          @endforeach
        </select>
        <label for="f-source" class="sr-only">Źródło</label>
        <select id="f-source" name="source" class="pp-input pp-input-sm w-48" data-autosubmit>
          <option value="">Wszystkie źródła</option>
          @foreach (ChangeCenterService::SOURCES as $value => $label)
            <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
          @endforeach
        </select>
        <noscript><x-pp.button variant="secondary" size="sm">Pokaż</x-pp.button></noscript>
      </form>
    </div>

    @if ($sets->items === [])
      <x-pp.empty-state title="Brak zestawów zmian" icon="changes">
        Nie przygotowano jeszcze żadnej zmiany{{ $filters['status'] || $filters['source'] ? ' dla wybranych filtrów' : '' }}. Zmiany przygotujesz z rekomendacji, Action Plan, Campaign Plannera albo w Google Ads Management.
      </x-pp.empty-state>
    @else
      <ul class="divide-y divide-line">
        @foreach ($sets->items as $set)
          <li>
            <a href="{{ AppUrl::project($project->id, 'changes/' . $set['id']) }}" class="flex flex-wrap items-start gap-3 px-5 py-4 hover:bg-ink/[0.03]">
              <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-center gap-2">
                  <span class="font-semibold break-words text-ink">#{{ $set['id'] }} {{ $set['title'] }}</span>
                  <span class="pp-badge pp-badge-{{ ChangeStatus::TONES[$set['status']] ?? 'neutral' }}">{{ ChangeStatus::LABELS[$set['status']] ?? $set['status'] }}</span>
                  @if ($set['risk_level'] !== 'low')
                    <span class="pp-badge pp-badge-{{ $set['risk_level'] === 'high' ? 'danger' : 'warn' }}">Ryzyko: {{ ChangeStatus::RISKS[$set['risk_level']] }}</span>
                  @endif
                </span>
                <span class="mt-0.5 block text-[13px] text-ink-mute">
                  {{ ChangeCenterService::SOURCES[$set['source_type']] ?? $set['source_type'] }}@if ($set['source_label']) · {{ $set['source_label'] }}@endif ·
                  {{ (int) $set['operation_count'] }} {{ Format::plural((int) $set['operation_count'], 'operacja', 'operacje', 'operacji') }} ·
                  konto {{ $set['customer_id'] ?: '—' }} · {{ $set['author_name'] ?: '—' }} · {{ Format::datetime($set['updated_at']) }}
                </span>
              </span>
              @if ($set['budget_impact_micros'] !== null && (int) $set['budget_impact_micros'] !== 0)
                <span class="pp-num text-right text-[13px] {{ (int) $set['budget_impact_micros'] > 0 ? 'text-warn' : 'text-ink-soft' }}">
                  {{ (int) $set['budget_impact_micros'] > 0 ? '+' : '' }}{{ Format::money((int) $set['budget_impact_micros'], $project->currency) }} / dzień
                </span>
              @endif
            </a>
          </li>
        @endforeach
      </ul>
      <x-pp.pagination :paginator="$sets" label="zestawów" />
    @endif
  </section>
@endsection
