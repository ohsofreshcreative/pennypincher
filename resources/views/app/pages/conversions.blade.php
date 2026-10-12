@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\Conversions\ConversionClass;
  use App\PennyPincher\Domain\GoogleAds\Enums;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $account = $breakdown['account'];
  $metric = fn ($value) => $value === null ? 'Brak danych' : Format::conversions($value);
@endphp

@section('content')
  <x-pp.alert type="info" title="Klasyfikacja działa tylko w Penny Pincher">
    Ustawienia konwersji w Google Ads pozostają bez zmian. Oryginalne konwersje Google Ads zawsze pokazujemy osobno.
    Kliknięcie e-maila lub telefonu traktujemy jako potencjalny kontakt, a nie potwierdzony lead.
  </x-pp.alert>

  <section class="pp-card" aria-labelledby="pp-conv-summary">
    <div class="pp-card-header">
      <h2 id="pp-conv-summary" class="pp-card-title">Konwersje w okresie {{ $period->range->label() }}</h2>
      <p class="text-[13px] text-ink-mute">Dane konta (wszystkie kampanie)</p>
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
      <p class="border-t border-line px-5 py-3 text-[13px] text-ink-mute">Brak danych o konwersjach per kampania i działanie dla tego okresu – analizy kampanii pokażą „brak danych” zamiast szacować. Dane pojawią się po synchronizacji historii z Google Ads.</p>
    @endunless
  </section>

  <form method="post" action="{{ AppUrl::project($project->id, 'conversions') }}" class="pp-card min-w-0">
    <x-pp.nonce />
    <div class="pp-card-header">
      <h2 class="pp-card-title">Działania powodujące konwersję</h2>
      <p class="text-[13px] text-ink-mute">„Propozycja” = klasa wynikająca z kategorii Google Ads, dopóki jej nie zmienisz</p>
    </div>

    @if ($breakdown['actions'] === [])
      <x-pp.empty-state title="Brak działań konwersji" icon="check">Połącz Google Ads albo zaimportuj raport „Konwersje”, aby sklasyfikować działania.</x-pp.empty-state>
    @else
      <div class="pp-table-wrap">
        <table class="pp-table">
          <thead>
            <tr>
              <th>Działanie</th>
              <th>Kategoria Google Ads</th>
              <th>Wliczane do „Konwersji”</th>
              <th>Klasa w Penny Pincher</th>
              <th>Cel biznesowy</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($breakdown['actions'] as $action)
              <tr>
                <td class="max-w-[280px]">
                  <span class="block font-medium text-ink">{{ $action['name'] }}</span>
                  <span class="text-[12px] text-ink-mute">{{ $action['status'] === 'removed' ? 'usunięte' : ($action['counting_type'] === 'MANY_PER_CLICK' ? 'zliczanie: każda' : ($action['counting_type'] === 'ONE_PER_CLICK' ? 'zliczanie: jedna' : '')) }}</span>
                </td>
                <td class="text-ink-soft">{{ Enums::categoryLabel($action['category_code']) ?? ($action['category'] ?: '—') }}</td>
                <td class="text-ink-soft">{{ $action['primary_for_goal'] === null && $action['include_in_conversions'] === null ? '—' : (((int) ($action['primary_for_goal'] ?? $action['include_in_conversions'])) ? 'Tak (główna)' : 'Nie (dodatkowa)') }}</td>
                <td class="min-w-[220px]">
                  <label class="sr-only" for="class-{{ $action['id'] }}">Klasa działania {{ $action['name'] }}</label>
                  <select id="class-{{ $action['id'] }}" name="class[{{ $action['id'] }}]" class="pp-input pp-input-sm" @disabled(! $canEdit)>
                    @foreach (ConversionClass::options() as $value => $label)
                      <option value="{{ $value }}" @selected($action['effective_class'] === $value)>{{ $label }}</option>
                    @endforeach
                  </select>
                  <span class="mt-1 block text-[12px] {{ $action['classification_source'] === 'manual' ? 'text-mint-dark' : 'text-ink-mute' }}">{{ $action['classification_source'] === 'manual' ? 'Ustawione ręcznie' : 'Propozycja' }}</span>
                </td>
                <td>
                  <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="goal[{{ $action['id'] }}]" value="1" @checked($action['effective_goal']) @disabled(! $canEdit)>
                    <span>Cel</span>
                  </label>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if ($canEdit)
        <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-4">
          <x-pp.button>Zapisz klasyfikację</x-pp.button>
          <p class="text-sm text-ink-mute">„Cel biznesowy” = działania, które liczymy jako wartościowe konwersje w Budget Leaks i rekomendacjach.</p>
        </div>
      @else
        <p class="border-t border-line px-5 py-4 text-sm text-ink-mute">Klasyfikację może zmieniać właściciel lub administrator organizacji.</p>
      @endif
    @endif
  </form>
@endsection
