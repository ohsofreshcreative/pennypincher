@php
  use App\PennyPincher\Domain\Projects\BusinessContext;
  use App\PennyPincher\Support\Format;

  $context = $project->context();
  $value = fn (string $key, $default = null) => array_key_exists($key, $old) ? $old[$key] : $default;
  $disabled = $disabled ?? false;
  $completeness = $context->completeness();
@endphp

<fieldset class="space-y-5" @disabled($disabled)>
  <legend class="sr-only">Kontekst biznesowy</legend>

  <x-pp.alert :type="$completeness['missing'] === [] ? 'success' : 'info'" :title="'Uzupełniono ' . $completeness['filled'] . ' z ' . $completeness['total'] . ' pól kontekstu'">
    Pola są opcjonalne, ale im więcej wiemy o ofercie i kliencie, tym trafniej oceniamy dopasowanie fraz, intencję i propozycje wykluczeń.
    @if ($completeness['missing'] !== [])
      Brakuje: {{ implode(', ', $completeness['missing']) }}.
    @endif
  </x-pp.alert>

  <x-pp.field name="business_description" label="Opis firmy" type="textarea" :value="$value('business_description', $project->businessDescription)" :errors="$errors" />
  <div class="grid gap-5 lg:grid-cols-2">
    <x-pp.field name="products_services" label="Główne produkty i usługi" type="textarea" :value="$value('products_services', $context->get('products_services'))" :errors="$errors"
      help="Jedna pozycja w linii, np. „projektowanie stron internetowych”." />
    <x-pp.field name="priority_services" label="Usługi priorytetowe" type="textarea" :value="$value('priority_services', $context->get('priority_services'))" :errors="$errors"
      help="Co reklamować w pierwszej kolejności – podbija ocenę pasujących fraz." />
    <x-pp.field name="target_customer" label="Grupa docelowa" type="textarea" rows="3" :value="$value('target_customer', $project->targetCustomer)" :errors="$errors" />
    <x-pp.field name="ideal_customer" label="Klient idealny" type="textarea" rows="3" :value="$value('ideal_customer', $context->get('ideal_customer'))" :errors="$errors" />
    <x-pp.field name="excluded_audiences" label="Niepożądane grupy odbiorców" type="textarea" rows="3" :value="$value('excluded_audiences', $context->get('excluded_audiences'))" :errors="$errors"
      help="Frazy lub tematy, których nie chcesz (jedna w linii), np. „darmowy kreator”, „kurs”. Wykorzystamy je w propozycjach wykluczeń." />
    <x-pp.field name="advantages" label="Najważniejsze przewagi firmy" type="textarea" rows="3" :value="$value('advantages', $context->get('advantages'))" :errors="$errors" />
  </div>
  <div class="grid gap-5 sm:grid-cols-3">
    <x-pp.field name="target_locations" label="Lokalizacje docelowe" :value="$value('target_locations', $context->get('target_locations'))" :errors="$errors" />
    <x-pp.field name="language" label="Język" :options="BusinessContext::LANGUAGES" :value="$value('language', $context->language())" :errors="$errors" required />
    <x-pp.field name="keyword_location_code" label="Rynek dla danych o słowach kluczowych" :options="BusinessContext::LOCATIONS" :value="$value('keyword_location_code', $context->locationCode())" :errors="$errors" required
      help="Kraj, dla którego pobieramy popularność i CPC fraz (DataForSEO)." />
  </div>
  <div class="grid gap-5 sm:grid-cols-3">
    <x-pp.field name="average_value" label="Średnia wartość klienta / zlecenia" inputmode="decimal" :value="$value('average_value', Format::moneyInput($context->get('average_value_micros')))" :errors="$errors"
      help="Gdy puste, używamy środka przedziału wartości projektu." />
    <x-pp.field name="primary_goal" label="Główny cel reklamowy" :options="['' => 'Wybierz…'] + BusinessContext::GOALS" :value="$value('primary_goal', $context->get('primary_goal'))" :errors="$errors" />
    <x-pp.field name="landing_page_url" label="Adres strony docelowej" type="url" :value="$value('landing_page_url', $project->landingPageUrl)" :errors="$errors" placeholder="https://" />
  </div>
 <div class="grid gap-5 lg:grid-cols-2">
    <x-pp.field name="own_brand" label="Własna marka (nazwy, warianty)" type="textarea" rows="2" :value="$value('own_brand', $context->get('own_brand'))" :errors="$errors"
      help="Jedna w linii. Frazy z własną marką nie są traktowane jako przypadkowe nazwy firm." />
    <x-pp.field name="competitor_brands" label="Marki konkurencji" type="textarea" rows="2" :value="$value('competitor_brands', $context->get('competitor_brands'))" :errors="$errors"
      help="Jedna w linii. Frazy z tymi markami dostają niższą ocenę (nie są blokowane – możesz je dodać świadomie)." />
  </div>
  <x-pp.field name="secondary_goals" label="Dodatkowe cele reklamowe" type="textarea" rows="2" :value="$value('secondary_goals', $context->get('secondary_goals'))" :errors="$errors" />
</fieldset>
