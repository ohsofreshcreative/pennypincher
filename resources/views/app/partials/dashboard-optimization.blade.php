@php
  use App\PennyPincher\Domain\Leaks\Leak;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $o = $optimization;
  $period = fn (?array $run) => $run ? ['period' => $run['range_preset'] === 'custom' ? 'custom' : $run['range_preset']] + ($run['range_preset'] === 'custom' ? ['from' => $run['date_from'], 'to' => $run['date_to']] : []) : [];
@endphp

<section aria-label="Optymalizacja" class="grid gap-4 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
  <div class="pp-card min-w-0">
    <div class="pp-card-header">
      <h2 class="pp-card-title">Największe problemy budżetowe</h2>
      <a href="{{ AppUrl::project($project->id, 'budget-leaks', $period($o['leak_run'])) }}" class="text-[13px] font-semibold text-mint hover:underline">Budget Leaks →</a>
    </div>
    @if (! $o['leak_run'])
      <p class="px-5 py-4 text-sm text-ink-soft">Analiza Budget Leaks nie była jeszcze uruchamiana. Otwórz Budget Leaks i uruchom analizę wybranego okresu.</p>
    @elseif ($o['top_leaks'] === [])
      <p class="px-5 py-4 text-sm text-ink-soft">W ostatniej analizie ({{ Format::period($o['leak_run']['date_from'], $o['leak_run']['date_to']) }}) nie wykryto wycieków budżetu.</p>
    @else
      <ul class="divide-y divide-line">
        @foreach ($o['top_leaks'] as $leak)
          <li>
            <a href="{{ AppUrl::project($project->id, 'budget-leaks', $period($o['leak_run'])) }}#leak-{{ $leak['id'] }}" class="flex items-start gap-3 px-5 py-3 hover:bg-ink/[0.03]">
              <x-pp.status-badge :status="$leak['priority']" :label="Leak::PRIORITIES[$leak['priority']]" class="mt-0.5" />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-ink">{{ $leak['title'] }}</span>
                <span class="block truncate text-[12px] text-ink-mute">{{ Leak::CATEGORIES[$leak['category']] ?? '' }}</span>
              </span>
              <span class="pp-num shrink-0 text-sm font-semibold text-copper">{{ Format::money($leak['cost_micros'], $project->currency, 0) }}</span>
            </a>
          </li>
        @endforeach
      </ul>
      <p class="pp-num border-t border-line px-5 py-2.5 text-[12px] text-ink-mute">Okres: {{ Format::period($o['leak_run']['date_from'], $o['leak_run']['date_to']) }} · łącznie {{ $o['leak_count'] }} problemów</p>
    @endif
  </div>

  <div class="pp-card grid grid-cols-2 divide-x divide-y divide-line">
    <a href="{{ AppUrl::project($project->id, 'budget-leaks', $period($o['leak_run'])) }}" class="block hover:bg-ink/[0.03]">
      <dl><x-pp.stat-card label="Potencjał optymalizacji" :value="$o['savings'] && $o['savings']['total'] > 0 ? Format::money($o['savings']['total'], $project->currency, 0) : ($o['leak_run'] ? '0' : 'Brak analizy')" :hint="$o['savings'] && $o['savings']['total'] > 0 ? 'SZACUNEK miesięczny' : null" tone="spend" /></dl>
    </a>
    <a href="{{ AppUrl::project($project->id, 'recommendations', ['status' => 'new']) }}" class="block hover:bg-ink/[0.03]">
      <dl><x-pp.stat-card label="Nowe rekomendacje" :value="$o['new_recommendations']" :hint="$o['open_recommendations'] . ' otwartych'" /></dl>
    </a>
    <a href="{{ AppUrl::project($project->id, 'keywords', $period($o['keyword_run'])) }}" class="block hover:bg-ink/[0.03]">
      <dl><x-pp.stat-card label="Nowe możliwości fraz" :value="$o['opportunities'] ?? 'Brak analizy'" :hint="$o['opportunities'] !== null ? 'Opportunity Score ≥ 50' : null" /></dl>
    </a>
    <a href="{{ AppUrl::project($project->id, 'keywords', ['tab' => 'negatives'] + $period($o['keyword_run'])) }}" class="block hover:bg-ink/[0.03]">
      <dl><x-pp.stat-card label="Propozycje wykluczeń" :value="$o['negatives'] ?? 'Brak analizy'" /></dl>
    </a>
  </div>
</section>
