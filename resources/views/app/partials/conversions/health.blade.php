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

  {{-- Health Check --}}
  <section class="pp-card min-w-0" aria-labelledby="health">
    <div class="pp-card-header">
      <h3 id="health" class="pp-card-title flex items-center gap-2"><x-pp.icon name="target" /> Conversion Health Check</h3>
      <span class="text-caption text-ink-mute">Pomiar leadów: {{ $overview['measurement']['reliable'] ? 'wiarygodny' : 'niewiarygodny' }}</span>
    </div>
    @if ($issues === [])
      <p class="px-5 py-4 text-meta text-ink-soft">Nie wykryto problemów z konfiguracją konwersji. {{ $overview['api_read_at'] ? '' : 'Odczytaj konfigurację z Google Ads, aby sprawdzić też cele konta i kampanii.' }}</p>
    @else
      <ol class="divide-y divide-line">
        @foreach ($issues as $issue)
          <li class="px-5 py-4 text-meta leading-6">
            <p class="flex flex-wrap items-center gap-2">
              <span class="pp-badge pp-badge-{{ $severity[$issue['severity']][0] ?? 'neutral' }}">{{ $severity[$issue['severity']][1] ?? $issue['severity'] }}</span>
              <span class="font-semibold break-words text-ink">{{ $issue['title'] }}</span>
            </p>
            <p class="mt-1 text-ink-soft">{{ $issue['why'] }}</p>
            <p class="mt-1"><strong class="text-ink">Propozycja:</strong> {{ $issue['proposal'] }}</p>
            @if (! empty($issue['campaigns']))
              <p class="mt-1 text-ink-mute">Kampanie, których to dotyczy: {{ implode(', ', array_map(fn ($c) => $c['name'] . (isset($c['conversions']) ? ' (' . Format::conversions($c['conversions']) . ' konw.)' : ''), array_slice($issue['campaigns'], 0, 8))) }}{{ count($issue['campaigns']) > 8 ? '…' : '' }}</p>
            @endif
            @if (! empty($issue['bidding']))
              <p class="mt-1 text-warn">Zmiana celu wpływa na automatyczne strategie stawek – w Change Center wymaga osobnego potwierdzenia.</p>
            @endif
            <div class="mt-2">
              @if ($issue['operations'] !== [] && $canPrepare)
                <form method="post" action="{{ $base }}/health" data-busy>
                  <x-pp.nonce />@isset($returnTo)<input type="hidden" name="return" value="{{ $returnTo }}">@endisset<input type="hidden" name="issue" value="{{ $issue['key'] }}">
                  <x-pp.button variant="secondary" size="sm" icon="changes">Przygotuj zmianę</x-pp.button>
                </form>
              @elseif ($issue['not_editable'])
                <p class="text-ink-mute"><x-pp.icon name="lock" :size="14" class="inline" /> {{ $issue['not_editable'] }}</p>
              @endif
            </div>
          </li>
        @endforeach
      </ol>
    @endif
  </section>
