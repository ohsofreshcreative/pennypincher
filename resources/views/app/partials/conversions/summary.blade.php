{{-- Konwersje w okresie: Google Ads osobno od klasyfikacji Penny Pincher (ConversionBreakdown). --}}
@php
  use App\PennyPincher\Domain\Conversions\ConversionClass;
  use App\PennyPincher\Domain\GoogleAds\Enums;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $account = $breakdown['account'];
  $metric = fn ($value) => $value === null ? 'Brak danych' : Format::conversions($value);
@endphp

  <x-pp.alert type="info" title="Klasyfikacja działa tylko w Penny Pincher">
    Ustawienia konwersji w Google Ads pozostają bez zmian. Oryginalne konwersje Google Ads zawsze pokazujemy osobno.
    Kliknięcie e-maila lub telefonu traktujemy jako potencjalny kontakt, a nie potwierdzony lead.
  </x-pp.alert>

  <section class="pp-card" aria-labelledby="pp-conv-summary">
    <div class="pp-card-header">
      <h2 id="pp-conv-summary" class="pp-card-title">Konwersje w okresie {{ $period->range->label() }}</h2>
      <p class="text-meta text-ink-mute">Dane konta (wszystkie kampanie)</p>
    </div>
    <dl class="grid grid-cols-2 divide-line sm:grid-cols-3 lg:grid-cols-6 lg:divide-x">
      <x-pp.stat-card label="Konwersje Google Ads" :value="$metric($account['google'])" hint="Kolumna „Konwersje”" />
      <x-pp.stat-card label="Cele biznesowe" :value="$metric($account['goals'])" hint="Działania oznaczone jako cel" />
      <x-pp.stat-card label="Leady" :value="$metric($account['leads'])" hint="Zakwalifikowane jako lead" />
      <x-pp.stat-card label="Potwierdzone leady" :value="$metric($account['confirmed'])" :hint="$breakdown['confirmed_available'] ? 'Działania potwierdzone' : 'Brak źródła potwierdzeń'" />
      <x-pp.stat-card label="Potencjalne kontakty" :value="$metric($account['potential'])" hint="E-mail, telefon, połączenia" />
      <x-pp.stat-card label="Mikrokonwersje" :value="$metric($account['micro'])" hint="Zachowanie na stronie" />
    </dl>
    @unless ($breakdown['campaign_level_available'])
      <p class="border-t border-line px-5 py-3 text-meta text-ink-mute">Brak danych o konwersjach per kampania i działanie dla tego okresu – analizy kampanii pokażą „brak danych” zamiast szacować. Dane pojawią się po synchronizacji historii z Google Ads.</p>
    @endunless
  </section>
