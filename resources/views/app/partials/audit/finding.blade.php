@php
  use App\PennyPincher\Domain\Audit\Category;
  use App\PennyPincher\Domain\Audit\Dimension;
  use App\PennyPincher\Repositories\AuditRepository;
  use App\PennyPincher\Support\AppUrl;

  $dimension = Dimension::tryFrom($finding['score_dimension']);
@endphp

<article id="finding-{{ $finding['id'] }}" class="pp-card scroll-mt-24" x-data="{ open: {{ $open ?? false ? 'true' : 'false' }} }">
  <button type="button" class="flex w-full items-start gap-3 px-5 py-4 text-left" x-on:click="open = !open" x-bind:aria-expanded="open">
    <div class="min-w-0 flex-1">
      <div class="flex flex-wrap items-center gap-2">
        <x-pp.status-badge :status="$finding['severity']" />
        @if ($finding['status'] !== 'new')<x-pp.status-badge :status="$finding['status']" />@endif
        <span class="text-[12px] text-ink-mute">{{ $finding['rule_id'] }} · {{ Category::LABELS[$finding['category']] ?? $finding['category'] }}</span>
      </div>
      <h3 class="mt-1.5 font-semibold text-ink">{{ $finding['title'] }}</h3>
    </div>
    <span class="pp-num shrink-0 text-[13px] text-ink-mute" title="Wpływ na Audit Score">
      @if ($finding['penalty'] > 0)−{{ rtrim(rtrim(number_format($finding['penalty'], 1, ',', ''), '0'), ',') }} pkt @else bez wpływu @endif
    </span>
    <x-pp.icon name="chevron-down" class="mt-0.5 text-ink-mute transition-transform" x-bind:class="open && 'rotate-180'" />
  </button>

  <div x-show="open" x-cloak class="space-y-4 border-t border-line px-5 py-4 text-sm leading-6">
    <p class="text-ink-soft">{{ $finding['description'] }}</p>

    @if ($finding['evidence'])
      <div>
        <h4 class="text-[13px] font-semibold text-ink">Dowody</h4>
        <dl class="mt-2 divide-y divide-line rounded-[var(--radius-control)] border border-line">
          @foreach ($finding['evidence'] as [$label, $value])
            <div class="grid gap-1 px-3 py-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] sm:gap-4">
              <dt class="text-ink-mute">{{ $label }}</dt>
              <dd class="pp-num break-words text-ink">{{ $value }}</dd>
            </div>
          @endforeach
        </dl>
      </div>
    @endif

    <div class="rounded-[var(--radius-control)] bg-mint-soft px-3 py-2.5">
      <h4 class="text-[13px] font-semibold text-mint-dark">Rekomendacja</h4>
      <p class="mt-0.5 text-ink">{{ $finding['recommendation'] }}</p>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-[12.5px] text-ink-mute">
        Obszar: {{ $dimension?->label() ?? '—' }}.
        Zmiany wprowadzasz samodzielnie w Google Ads – Penny Pincher nie modyfikuje konta.
      </p>
      @if ($canRun)
        <form method="post" action="{{ AppUrl::project($projectId, 'audits/findings/' . $finding['id'] . '/status') }}" class="flex flex-wrap gap-2">
          <x-pp.nonce />
          @foreach (AuditRepository::STATUSES as $value => $label)
            @if ($value !== $finding['status'])
              <button type="submit" name="status" value="{{ $value }}" class="pp-btn pp-btn-sm {{ $value === 'dismissed' ? 'pp-btn-ghost' : 'pp-btn-secondary' }}">
                {{ ['new' => 'Przywróć jako nowy', 'reviewed' => 'Oznacz jako przejrzany', 'dismissed' => 'Pomiń'][$value] }}
              </button>
            @endif
          @endforeach
        </form>
      @endif
    </div>
  </div>
</article>
