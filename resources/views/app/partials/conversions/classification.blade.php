{{-- Klasyfikacja działań konwersji (tylko interpretacja w Penny Pincher, POST conversions). --}}
@php
  use App\PennyPincher\Domain\Conversions\ConversionClass;
  use App\PennyPincher\Domain\GoogleAds\Enums;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $account = $breakdown['account'];
  $metric = fn ($value) => $value === null ? 'Brak danych' : Format::conversions($value);
@endphp

  <form method="post" action="{{ AppUrl::project($project->id, 'conversions') }}" class="pp-card min-w-0">
    <x-pp.nonce />
    @isset($returnTo)<input type="hidden" name="return" value="{{ $returnTo }}">@endisset
    <div class="pp-card-header">
      <h2 class="pp-card-title">Działania powodujące konwersję</h2>
      <p class="text-meta text-ink-mute">„Propozycja” = klasa wynikająca z kategorii Google Ads, dopóki jej nie zmienisz</p>
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
                  <span class="text-caption text-ink-mute">{{ $action['status'] === 'removed' ? 'usunięte' : ($action['counting_type'] === 'MANY_PER_CLICK' ? 'zliczanie: każda' : ($action['counting_type'] === 'ONE_PER_CLICK' ? 'zliczanie: jedna' : '')) }}</span>
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
                  <span class="mt-1 block text-caption {{ $action['classification_source'] === 'manual' ? 'text-mint-dark' : 'text-ink-mute' }}">{{ $action['classification_source'] === 'manual' ? 'Ustawione ręcznie' : 'Propozycja' }}</span>
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
