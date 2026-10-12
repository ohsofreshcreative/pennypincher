@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $triggers = ['manual' => 'ręcznie', 'sync' => 'po synchronizacji', 'cli' => 'z konsoli'];
@endphp

        <section class="pp-card" aria-labelledby="pp-audit-history">
          <div class="pp-card-header">
            <h2 id="pp-audit-history" class="pp-card-title">Historia audytów</h2>
          </div>
          <ul class="divide-y divide-line">
            @forelse ($history as $item)
              <li>
                <a href="{{ AppUrl::project($project->id, 'audits/' . $item['id']) }}" @class(['flex items-center gap-3 px-5 py-3 hover:bg-ink/[0.03]', 'bg-mint-soft/60' => $run && (int) $item['id'] === (int) $run['id']])>
                  <span class="pp-num w-9 text-lg font-semibold text-ink">{{ $item['score'] ?? '—' }}</span>
                  <span class="min-w-0 flex-1">
                    <span class="pp-num block text-sm text-ink">{{ Format::datetime($item['created_at']) }}</span>
                    <span class="pp-num block text-[12px] text-ink-mute">Okres: {{ Format::period($item['date_from'], $item['date_to']) }}</span>
                    <span class="block text-[12px] text-ink-mute">{{ (int) $item['critical_count'] }} kryt. · {{ (int) $item['warning_count'] }} ostrz. · {{ (int) $item['info_count'] }} inf. · {{ $triggers[$item['trigger_type']] ?? $item['trigger_type'] }}</span>
                  </span>
                </a>
              </li>
            @empty
              <li class="px-5 py-4 text-sm text-ink-mute">Brak wcześniejszych audytów.</li>
            @endforelse
          </ul>
        </section>
