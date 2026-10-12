@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Planner\PlanStatus;
  use App\PennyPincher\Domain\Projects\BusinessContext;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $statusTones = ['draft' => 'info', 'review' => 'to_review', 'approved' => 'accepted', 'archived' => 'archived'];
@endphp

@section('content')
  <section class="pp-card p-5 text-[13px] leading-6 text-ink-soft">
    <h2 class="pp-card-title">Campaign Planner</h2>
    <p class="mt-1">Przygotuj nowe kampanie: kampanie → grupy reklam → słowa kluczowe z dopasowaniem, wykluczenia, teksty reklam i podział budżetu. Plan to dokument roboczy – <strong class="text-ink">nic nie trafia automatycznie na konto Google Ads</strong>. Gotowy plan wyeksportujesz do CSV lub dokumentu do druku.</p>
    <p class="mt-1">Najszybciej: w <a href="{{ AppUrl::project($project->id, 'keywords', ['tab' => 'clusters']) }}" class="font-semibold text-mint underline">Keyword Opportunities → Klastry</a> wybierz „Dodaj klaster do planu”.</p>
  </section>

  <div class="grid gap-6 2xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
    <section class="pp-card min-w-0" aria-label="Plany">
      <div class="pp-card-header">
        <h2 class="pp-card-title">Plany kampanii</h2>
        <form method="get" class="flex items-center gap-2">
          <label for="f-status" class="sr-only">Status</label>
          <select id="f-status" name="status" class="pp-input pp-input-sm w-44" data-autosubmit>
            <option value="">Aktywne (bez archiwum)</option>
            @foreach (PlanStatus::LABELS as $value => $label)
              <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
          </select>
          <noscript><x-pp.button variant="secondary" size="sm">Pokaż</x-pp.button></noscript>
        </form>
      </div>
      @if ($plans->items === [])
        <x-pp.empty-state title="Brak planów" icon="planner">Utwórz pierwszy plan albo dodaj frazy z Keyword Opportunities.</x-pp.empty-state>
      @else
        <ul class="divide-y divide-line">
          @foreach ($plans->items as $plan)
            <li>
              <a href="{{ AppUrl::project($project->id, 'planner/' . $plan['id']) }}" class="flex flex-wrap items-start gap-3 px-5 py-4 hover:bg-ink/[0.03]">
                <span class="min-w-0 flex-1">
                  <span class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-ink">{{ $plan['name'] }}</span>
                    <x-pp.status-badge :status="$statusTones[$plan['status']] ?? 'info'" :label="PlanStatus::LABELS[$plan['status']] ?? $plan['status']" />
                  </span>
                  <span class="mt-0.5 block text-[13px] text-ink-mute">
                    {{ (int) $plan['campaign_count'] }} {{ Format::plural((int) $plan['campaign_count'], 'kampania', 'kampanie', 'kampanii') }} ·
                    {{ (int) $plan['keyword_count'] }} {{ Format::plural((int) $plan['keyword_count'], 'słowo', 'słowa', 'słów') }} ·
                    {{ $plan['author_name'] ?: '—' }} · {{ Format::datetime($plan['updated_at']) }}
                  </span>
                </span>
                <span class="pp-num text-right text-[13px] text-ink-soft">
                  {{ $plan['monthly_budget_micros'] !== null ? Format::money($plan['monthly_budget_micros'], $plan['currency'], 0) . ' / mies.' : 'bez budżetu' }}
                  @if ($plan['monthly_budget_micros'] !== null)
                    <span class="block text-[12px] text-ink-mute">przydzielono {{ Format::money((int) $plan['allocated_micros'], $plan['currency'], 0) }}</span>
                  @endif
                </span>
              </a>
            </li>
          @endforeach
        </ul>
        <x-pp.pagination :paginator="$plans" label="planów" />
      @endif
    </section>

    @if ($canEdit)
      <section class="pp-card p-5" aria-labelledby="new-plan">
        <h2 id="new-plan" class="pp-card-title">Nowy plan</h2>
        <form method="post" action="{{ AppUrl::project($project->id, 'planner') }}" class="mt-3 space-y-4">
          <x-pp.nonce />
          <x-pp.field name="name" label="Nazwa planu" required :value="'Plan kampanii ' . wp_date('m.Y')" />
          <x-pp.field name="goal" label="Cel biznesowy" :options="['' => 'Wybierz…'] + BusinessContext::GOALS" :value="$project->context()->get('primary_goal')" />
          <x-pp.field name="service" label="Usługa" :value="$project->context()->priorityTexts()[0] ?? ''" />
          <x-pp.field name="audience" label="Grupa docelowa" type="textarea" rows="2" :value="$project->targetCustomer" />
          <div class="grid gap-4 sm:grid-cols-2">
            <x-pp.field name="location" label="Lokalizacja" :value="$project->context()->get('target_locations')" />
            <x-pp.field name="language" label="Język" required :options="BusinessContext::LANGUAGES" :value="$project->context()->language()" />
          </div>
          <x-pp.field name="monthly_budget" label="Budżet miesięczny ({{ $project->currency }})" inputmode="decimal" :value="Format::moneyInput($project->monthlyBudgetMicros)" help="Domyślnie budżet projektu. Kwota planistyczna – nie zmienia budżetów w Google Ads." />
          <x-pp.button icon="plus">Utwórz szkic</x-pp.button>
        </form>
      </section>
    @endif
  </div>
@endsection
