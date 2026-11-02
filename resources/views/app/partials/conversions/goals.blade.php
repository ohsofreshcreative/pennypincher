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

    {{-- Cele --}}
    <section class="pp-card min-w-0" aria-labelledby="goals">
      <div class="pp-card-header"><h3 id="goals" class="pp-card-title">Cele konwersji (optymalizacja stawek)</h3>@if ($overview['goals_read_at'])<span class="text-caption text-ink-mute">odczyt {{ Format::datetime($overview['goals_read_at']) }}</span>@endif</div>
      @if ($customerGoals === null)
        <p class="px-5 py-4 text-meta text-ink-mute">Cele konta i kampanii nie są synchronizowane – odczytaj je z Google Ads.</p>
      @else
        <ul class="divide-y divide-line text-meta">
          @foreach ($customerGoals as $goal)
            <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2">
              <span class="min-w-0 break-words"><span class="font-semibold text-ink">{{ OperationCatalog::CONVERSION_CATEGORIES[$goal['category']] ?? $goal['category'] }}</span> <span class="text-ink-mute">· {{ $goal['origin'] }} · konto</span></span>
              <span class="flex items-center gap-2">
                <span class="pp-badge pp-badge-{{ $goal['biddable'] ? 'ok' : 'neutral' }}">{{ $goal['biddable'] ? 'w optymalizacji' : 'poza optymalizacją' }}</span>
                @if ($canPrepare)
                  <form method="post" action="{{ $base }}/goal"><x-pp.nonce />@isset($returnTo)<input type="hidden" name="return" value="{{ $returnTo }}">@endisset<input type="hidden" name="resource_name" value="{{ $goal['resource_name'] }}"><input type="hidden" name="biddable" value="{{ $goal['biddable'] ? '0' : '1' }}"><x-pp.button variant="ghost" size="sm">{{ $goal['biddable'] ? 'Wyłącz' : 'Włącz' }}</x-pp.button></form>
                @endif
              </span>
            </li>
          @endforeach
        </ul>
        @php $overridden = array_values(array_filter((array) $campaignGoals, fn ($g) => collect($customerGoals)->first(fn ($c) => $c['category'] === $g['category'] && $c['origin'] === $g['origin'] && $c['biddable'] !== $g['biddable']) !== null)); @endphp
        @if ($overridden !== [])
          <p class="border-t border-line px-5 pt-3 text-meta font-semibold text-ink">Kampanie z innymi celami niż konto</p>
          <ul class="divide-y divide-line text-meta">
            @foreach ($overridden as $goal)
              <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2">
                <span class="min-w-0 break-words">{{ $goal['campaign_name'] }} <span class="text-ink-mute">· {{ OperationCatalog::CONVERSION_CATEGORIES[$goal['category']] ?? $goal['category'] }}</span></span>
                <span class="pp-badge pp-badge-{{ $goal['biddable'] ? 'ok' : 'neutral' }}">{{ $goal['biddable'] ? 'w optymalizacji' : 'poza' }}</span>
              </li>
            @endforeach
          </ul>
        @endif
      @endif
    </section>
