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

    {{-- Nowe działanie --}}
    @if ($canPrepare && $overview['connected'])
      <section class="pp-card p-5" aria-labelledby="new-conversion">
        <h3 id="new-conversion" class="pp-card-title">Nowe działanie konwersji (strona internetowa)</h3>
        <p class="mt-1 text-meta text-ink-soft">Penny Pincher tworzy tylko konwersje typu „strona internetowa” (WEBPAGE). Po utworzeniu trzeba zainstalować tag Google (gtag.js) lub skonfigurować go w Google Tag Manager – bez tagu konwersja nie będzie rejestrowana. Fragment zdarzenia pobierzesz w Google Ads → Cele → Konwersje.</p>
        <form method="post" action="{{ $base }}/create" class="mt-3 grid gap-3 sm:grid-cols-2">
          <x-pp.nonce />@isset($returnTo)<input type="hidden" name="return" value="{{ $returnTo }}">@endisset
          <x-pp.field name="name" label="Nazwa" required class="sm:col-span-2" />
          <x-pp.field name="category" label="Kategoria" :options="OperationCatalog::CONVERSION_CATEGORIES" value="SUBMIT_LEAD_FORM" required />
          <x-pp.field name="counting_type" label="Zliczanie" :options="OperationCatalog::COUNTING" value="ONE_PER_CLICK" required />
          <x-pp.field name="click_window" label="Okno po kliknięciu (dni)" type="number" min="1" max="90" value="30" />
          <x-pp.field name="view_window" label="Okno po wyświetleniu (dni)" type="number" min="1" max="30" value="1" />
          <x-pp.field name="default_value" label="Wartość domyślna" inputmode="decimal" />
          <label class="flex items-start gap-2 text-meta"><input type="checkbox" name="always_use_default_value" value="1" class="mt-1"> <span>Zawsze używaj wartości domyślnej</span></label>
          <label class="flex items-start gap-2 text-meta sm:col-span-2"><input type="checkbox" name="primary_for_goal" value="1" class="mt-1"> <span>Konwersja główna (używana w optymalizacji stawek) – zalecane tylko dla rzeczywistych leadów</span></label>
          <div class="sm:col-span-2"><x-pp.button variant="secondary" icon="plus">Przygotuj konwersję (szkic)</x-pp.button></div>
        </form>
      </section>
    @endif
