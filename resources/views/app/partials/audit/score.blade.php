@php
  use App\PennyPincher\Domain\Audit\AuditScore;

  /** @var array|null $run */
  $score = $run['score'] ?? null;
  $tone = AuditScore::tone($score);
  $ring = ['success' => 'text-mint', 'warning' => 'text-warn', 'danger' => 'text-danger', 'neutral' => 'text-ink-mute'][$tone];
  $size = $size ?? 'lg';
@endphp

<div class="flex items-center gap-4">
  <div @class(['relative grid shrink-0 place-items-center', 'size-24' => $size === 'lg', 'size-16' => $size !== 'lg'])>
    <svg viewBox="0 0 36 36" class="absolute inset-0 size-full -rotate-90" aria-hidden="true">
      <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor" stroke-width="3" class="text-ink/10" />
      @if ($score !== null)
        <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="{{ $ring }}" stroke-dasharray="{{ $score }} 100" pathLength="100" />
      @endif
    </svg>
    <span @class(['pp-num font-semibold text-ink', 'text-[28px]' => $size === 'lg', 'text-[20px]' => $size !== 'lg'])>{{ $score ?? '—' }}</span>
  </div>
  <div class="min-w-0">
    <p class="text-[13px] font-medium text-ink-mute">Audit Score</p>
    <p class="font-semibold text-ink">{{ AuditScore::label($score) }}</p>
    @if ($score === null && ! empty($run['score_breakdown']['reason']))
      <p class="mt-1 text-[13px] leading-5 text-ink-soft">{{ $run['score_breakdown']['reason'] }}</p>
    @endif
  </div>
</div>
