@php
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Services\ProjectValidator;
  use App\PennyPincher\Domain\Projects\Project;

  /** @var Project|null $model */
  $value = function (string $key, $default = null) use ($old, $model) {
    return array_key_exists($key, $old) ? $old[$key] : $default;
  };
  $timezones = array_combine(timezone_identifiers_list(), timezone_identifiers_list());
  $currencies = array_combine(ProjectValidator::CURRENCIES, ProjectValidator::CURRENCIES);
  $disabled = $disabled ?? false;
@endphp

<fieldset class="space-y-5" @disabled($disabled)>
  <legend class="sr-only">Dane projektu</legend>

  <x-pp.field name="name" label="Nazwa projektu" :value="$value('name', $model?->name)" :errors="$errors" required maxlength="191" />

  <div class="grid gap-5 sm:grid-cols-3">
    <x-pp.field name="platform" label="Platforma" :options="Project::PLATFORMS" :value="$value('platform', $model?->platform ?? 'google_ads')" :errors="$errors" required />
    <x-pp.field name="currency" label="Waluta konta" :options="$currencies" :value="$value('currency', $model?->currency ?? 'PLN')" :errors="$errors" required
      help="Musi być zgodna z walutą eksportów Google Ads." />
    <x-pp.field name="timezone" label="Strefa czasowa" :options="$timezones" :value="$value('timezone', $model?->timezone ?? 'Europe/Warsaw')" :errors="$errors" required />
  </div>

  <div class="grid gap-5 sm:grid-cols-2">
    <x-pp.field name="website_url" label="Adres strony" type="url" :value="$value('website_url', $model?->websiteUrl)" :errors="$errors" placeholder="https://" />
    <x-pp.field name="landing_page_url" label="Adres landing page" type="url" :value="$value('landing_page_url', $model?->landingPageUrl)" :errors="$errors" placeholder="https://" />
  </div>

  <div class="grid gap-5 sm:grid-cols-3">
    <x-pp.field name="monthly_budget" label="Budżet miesięczny" inputmode="decimal" :value="$value('monthly_budget', Format::moneyInput($model?->monthlyBudgetMicros))" :errors="$errors"
      help="Maksymalny miesięczny budżet reklamowy. Dashboard pokazuje jego wykorzystanie." />
    <x-pp.field name="min_project_value" label="Minimalna wartość projektu" inputmode="decimal" :value="$value('min_project_value', Format::moneyInput($model?->minProjectValueMicros))" :errors="$errors" required
      help="Najmniejsza wartość zlecenia, które opłaca się pozyskać." />
    <x-pp.field name="max_project_value" label="Maksymalna wartość projektu" inputmode="decimal" :value="$value('max_project_value', Format::moneyInput($model?->maxProjectValueMicros))" :errors="$errors" />
  </div>

  <x-pp.field name="business_description" label="Opis działalności" type="textarea" :value="$value('business_description', $model?->businessDescription)" :errors="$errors"
    help="Co reklamujesz i czym się wyróżniasz. Wykorzystamy to przy audycie i rekomendacjach." />
  <x-pp.field name="target_customer" label="Klient docelowy" type="textarea" rows="3" :value="$value('target_customer', $model?->targetCustomer)" :errors="$errors"
    help="Kto jest właściwym klientem, a kto nie (np. B2B, firmy rozważające redesign)." />

  <x-pp.field name="status" label="Status projektu" :options="Project::STATUSES" :value="$value('status', $model?->status ?? 'active')" :errors="$errors" required class="sm:w-64" />
</fieldset>
