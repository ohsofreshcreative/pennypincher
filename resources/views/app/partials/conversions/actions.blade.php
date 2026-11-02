{{-- Conversion Management: wspólna sekcja strony konfiguracji i Centrum konwersji. --}}
@php
  use App\PennyPincher\Domain\Conversions\ConversionClass;
  use App\PennyPincher\Domain\Conversions\ConversionFieldPolicy;
  use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $actions = $overview['actions'];
  $issues = $overview['issues'];
  $base = rtrim(AppUrl::project($project->id, 'conversion-management'), '/');
  $severity = ['critical' => ['danger', 'Krytyczne'], 'warning' => ['warn', 'Ostrzeżenie'], 'info' => ['info', 'Informacja']];
  $yesNo = fn ($v) => $v === null ? '—' : ($v ? 'Tak' : 'Nie');
  $customerGoals = $overview['goals']['customer'] ?? null;
  $campaignGoals = $overview['goals']['campaigns'] ?? null;
@endphp

  {{-- Lista działań --}}
  <section class="pp-card min-w-0" aria-label="Działania konwersji">
    <div class="pp-card-header"><h3 class="pp-card-title">Działania konwersji ({{ count($actions) }})</h3><span class="text-caption text-ink-mute">Konwersje: ostatnie 90 dni</span></div>
    @if ($actions === [])
      <x-pp.empty-state title="Brak działań konwersji" icon="target">Zsynchronizuj konto Google Ads albo zaimportuj raport konwersji.</x-pp.empty-state>
    @else
      <div class="pp-table-wrap">
        <table class="pp-table">
          <thead>
            <tr>
              <th class="pp-col-sticky">Działanie</th><th>Typ / źródło</th><th>Status</th><th>Główna</th><th>Zliczanie</th><th>Okna (klik/wyśw.)</th><th>Wartość</th>
              <th>Klasyfikacja PP</th><th class="pp-col-num">Konw. 90 dni</th><th>Edycja przez API</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($actions as $a)
              <tr>
                <td class="pp-col-sticky max-w-[16rem] break-words"><span class="font-semibold text-ink">{{ $a['name'] }}</span>
                  @if ($a['campaigns'] !== [])<span class="block text-caption text-ink-mute">{{ count($a['campaigns']) }} {{ Format::plural(count($a['campaigns']), 'kampania', 'kampanie', 'kampanii') }}</span>@endif</td>
                <td class="text-caption whitespace-nowrap">{{ $a['type'] ?: '—' }}<span class="block text-ink-mute">{{ OperationCatalog::CONVERSION_CATEGORIES[$a['category']] ?? ($a['category'] ?: '—') }}{{ $a['origin'] ? ' · ' . $a['origin'] : '' }}</span></td>
                <td class="text-caption">{{ $a['status'] ?: '—' }}</td>
                <td>{{ $yesNo($a['primary_for_goal']) }}</td>
                <td class="text-caption whitespace-nowrap">{{ ['ONE_PER_CLICK' => 'Jedna', 'MANY_PER_CLICK' => 'Każda'][$a['counting_type']] ?? ($a['counting_type'] ?: '—') }}</td>
                <td class="pp-num text-caption whitespace-nowrap">{{ $a['click_window'] ?? '—' }} / {{ $a['view_window'] ?? '—' }}</td>
                <td class="pp-num text-caption whitespace-nowrap">{{ $a['default_value'] !== null ? Format::number($a['default_value'], 2) : '—' }}{{ $a['always_use_default_value'] ? ' (zawsze)' : '' }}</td>
                <td class="text-caption whitespace-nowrap">{{ ConversionClass::tryFrom($a['effective_class'])?->label() ?? '—' }}<span class="block text-ink-mute">{{ $a['effective_goal'] ? 'cel biznesowy' : 'nie cel' }} · {{ $a['classification_source'] === 'manual' ? 'ręcznie' : 'sugestia' }}</span></td>
                <td class="pp-col-num">{{ $a['conversions_90'] !== null ? Format::conversions($a['conversions_90']) : '—' }}</td>
                <td class="min-w-[10rem] text-caption">
                  @php $readOnly = array_keys(array_filter($a['field_policy'] ?? [], fn ($p) => ! in_array($p['status'], [ConversionFieldPolicy::EDITABLE, ConversionFieldPolicy::CONFIRM], true))); @endphp
                  @if ($a['editable'] === [])
                    <span class="text-ink-mute"><x-pp.icon name="lock" :size="14" class="inline" /> Tylko odczyt: {{ $a['locked_reason'] ?? 'Google Ads API nie pozwala edytować tego działania.' }}</span>
                  @elseif ($canPrepare)
                    <details>
                      <summary class="cursor-pointer font-semibold text-mint">Przygotuj zmianę</summary>
                      <form method="post" action="{{ $base }}/{{ $a['id'] }}/update" class="mt-2 w-64 space-y-2">
                        <x-pp.nonce />@isset($returnTo)<input type="hidden" name="return" value="{{ $returnTo }}">@endisset
                        @if (in_array('primary_for_goal', $a['editable'], true))
                          <label class="pp-label" for="pg-{{ $a['id'] }}">Konwersja główna</label>
                          <select id="pg-{{ $a['id'] }}" name="primary_for_goal" class="pp-input pp-input-sm"><option value="__keep">bez zmian</option><option value="1">główna</option><option value="0">dodatkowa</option></select>
                          <label class="flex items-start gap-2"><input type="checkbox" name="confirm_goal_change" value="1" class="mt-1"> <span>Rozumiem wpływ na Smart Bidding</span></label>
                        @endif
                        @if (in_array('counting_type', $a['editable'], true))
                          <label class="pp-label" for="ct-{{ $a['id'] }}">Zliczanie</label>
                          <select id="ct-{{ $a['id'] }}" name="counting_type" class="pp-input pp-input-sm"><option value="__keep">bez zmian</option>@foreach (OperationCatalog::COUNTING as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        @endif
                        @if (in_array('name', $a['editable'], true))
                          <label class="pp-label" for="nm-{{ $a['id'] }}">Nazwa</label>
                          <input id="nm-{{ $a['id'] }}" name="name" class="pp-input pp-input-sm" placeholder="bez zmian">
                        @endif
                        @if (in_array('click_through_lookback_window_days', $a['editable'], true))
                          <label class="pp-label" for="cw-{{ $a['id'] }}">Okno po kliknięciu (1–90 dni)</label>
                          <input id="cw-{{ $a['id'] }}" name="click_through_lookback_window_days" type="number" min="1" max="90" class="pp-input pp-input-sm" placeholder="bez zmian">
                        @endif
                        @if (in_array('default_value', $a['editable'], true))
                          <label class="pp-label" for="dv-{{ $a['id'] }}">Wartość domyślna</label>
                          <input id="dv-{{ $a['id'] }}" name="default_value" inputmode="decimal" class="pp-input pp-input-sm" placeholder="bez zmian">
                        @endif
                        @if ($readOnly !== [])
                          <p class="text-caption text-ink-mute">Tylko do odczytu dla typu {{ $a['type'] }}: {{ implode(', ', array_map(fn ($f) => ConversionFieldPolicy::LABELS[$f] ?? $f, $readOnly)) }}.</p>
                        @endif
                        <x-pp.button variant="secondary" size="sm">Dodaj do szkicu</x-pp.button>
                      </form>
                    </details>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>
