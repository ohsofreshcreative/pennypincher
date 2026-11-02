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

  <section class="pp-card p-5 text-meta leading-6 text-ink-soft">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="max-w-3xl">
        <h2 class="pp-card-title">Conversion Management</h2>
        <p class="mt-1">Konfiguracja konwersji w Google Ads (typ, status, główna/dodatkowa, zliczanie, okna, wartość) obok <strong class="text-ink">klasyfikacji Penny Pincher</strong>. Klasyfikacja w <a class="font-semibold text-mint underline" href="{{ AppUrl::project($project->id, 'conversions') }}">Konwersjach</a> służy tylko analizie i nigdy nie zmienia Google Ads. Zmiany konfiguracji Google Ads przygotowujesz tutaj – trafiają do Change Center.</p>
      </div>
      @if ($canPrepare && $overview['connected'])
        <form method="post" action="{{ $base }}/read" data-busy>
          <x-pp.nonce />@isset($returnTo)<input type="hidden" name="return" value="{{ $returnTo }}">@endisset
          <x-pp.button variant="secondary" icon="refresh" data-busy-label="Odczyt…">Odczytaj konwersje i cele z Google Ads</x-pp.button>
        </form>
      @endif
    </div>
    <p class="mt-2 text-ink-mute">Odczyt z Google Ads: {{ $overview['api_read_at'] ? Format::datetime($overview['api_read_at']) : 'jeszcze nie wykonano (pola poniżej z synchronizacji)' }}.</p>
  </section>

  @include('app.partials.changes.mode-banner')

  @unless ($overview['connected'])
    <x-pp.alert type="warning">Projekt nie ma aktywnego połączenia z Google Ads – widzisz dane z importu. Zmiany konfiguracji wymagają połączonego konta i synchronizacji przez API.</x-pp.alert>
  @endunless
