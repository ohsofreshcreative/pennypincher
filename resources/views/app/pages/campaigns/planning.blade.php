{{-- Kampania › Planowanie: zmiany tej kampanii w Google Ads (przez Change Center) i plany kampanii w aplikacji. --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Planner\PlanStatus;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $campaignId = (int) $campaign['id'];
  $canManage = $connection && $connection['customer_id'] && $campaign['external_id'];
@endphp

@section('content')
  <x-pp.campaign-tabs :project="$project" :campaign-id="$campaignId" :campaign-name="$campaign['name']" active="planning" />

  <section class="pp-card p-5" aria-labelledby="pp-campaign-manage">
    <h2 id="pp-campaign-manage" class="pp-card-title">Zmiany tej kampanii w Google Ads</h2>
    @if ($canManage)
      <p class="mt-1 text-sm text-ink-soft">Odczyt aktualnych ustawień kampanii z Google Ads i przygotowanie zmian. Każda zmiana trafia do Change Center jako szkic i wymaga zatwierdzenia – nic nie jest wysyłane bezpośrednio.</p>
      <div class="mt-3">
        <x-pp.button :href="AppUrl::project($project->id, 'management/campaigns/' . $campaignId)" icon="sliders" variant="secondary" size="sm">Zarządzaj kampanią</x-pp.button>
      </div>
    @elseif (! $campaign['external_id'])
      <p class="mt-1 text-sm text-ink-soft">Kampania nie ma identyfikatora Google Ads w danych (pochodzi z importu bez identyfikatora), więc nie można przygotować dla niej zmian na koncie.</p>
    @else
      <p class="mt-1 text-sm text-ink-soft">Zarządzanie kampanią wymaga połączenia z kontem Google Ads.</p>
      <div class="mt-3">
        <x-pp.button :href="AppUrl::project($project->id, 'google-ads')" icon="link" variant="secondary" size="sm">Połącz Google Ads</x-pp.button>
      </div>
    @endif
  </section>

  <section class="pp-card min-w-0" aria-labelledby="pp-campaign-plans">
    <div class="pp-card-header">
      <div>
        <h2 id="pp-campaign-plans" class="pp-card-title">Plany kampanii w aplikacji</h2>
        <p class="mt-0.5 text-meta text-ink-mute">Plany istnieją wyłącznie w Penny Pincher i nie są powiązane z istniejącymi kampaniami – tu widać wszystkie plany projektu.</p>
      </div>
      <a href="{{ AppUrl::project($project->id, 'planner') }}" class="text-meta font-semibold text-mint hover:underline">Campaign Planner</a>
    </div>
    @if ($plans->items === [])
      <p class="px-5 py-4 text-sm text-ink-soft">Brak planów. Nowy plan utworzysz w Campaign Planner.</p>
    @else
      <ul class="divide-y divide-line">
        @foreach ($plans->items as $plan)
          <li>
            <a href="{{ AppUrl::project($project->id, 'planner/' . $plan['id']) }}" class="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-ink/[0.03]">
              <span class="min-w-0 flex-1 text-sm font-medium text-ink">{{ $plan['name'] }}</span>
              <span class="pp-num text-caption text-ink-mute">{{ Format::datetime($plan['updated_at']) }}</span>
              <x-pp.status-badge status="info" :label="PlanStatus::LABELS[$plan['status']] ?? $plan['status']" />
            </a>
          </li>
        @endforeach
      </ul>
    @endif
  </section>
@endsection
