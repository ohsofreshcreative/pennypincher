@php
  use App\PennyPincher\Domain\Analysis\Text;
  use App\PennyPincher\Domain\Planner\MatchType;
  use App\PennyPincher\Domain\Planner\PlanStatus;
  use App\PennyPincher\Domain\Projects\BusinessContext;
  use App\PennyPincher\Support\Format;

  $currency = $plan['currency'];
  $money = fn ($m) => Format::money($m === null ? null : (int) $m, $currency, 0);
@endphp
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ $plan['name'] }} – plan kampanii</title>
  <style>
    :root { color-scheme: light; }
    body { font: 14px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; color: #13221c; background: #fff; margin: 0; padding: 32px 24px; }
    main { max-width: 960px; margin: 0 auto; }
    h1 { font-size: 24px; margin: 0 0 4px; }
    h2 { font-size: 18px; margin: 32px 0 8px; border-bottom: 1px solid #d8ddd8; padding-bottom: 4px; }
    h3 { font-size: 15px; margin: 20px 0 6px; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; font-size: 13px; }
    th, td { text-align: left; padding: 5px 8px; border-bottom: 1px solid #e6e9e6; vertical-align: top; }
    th { background: #f2f4f1; font-weight: 600; }
    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .muted { color: #5c6a64; }
    .note { background: #f2f4f1; padding: 10px 12px; border-radius: 6px; font-size: 13px; }
    .warn { background: #fdf3e2; padding: 10px 12px; border-radius: 6px; font-size: 13px; }
    ul { margin: 4px 0; padding-left: 20px; }
    @media print { body { padding: 0; } h2 { break-after: avoid; } table { break-inside: auto; } tr { break-inside: avoid; } }
  </style>
</head>
<body>
<main>
  <p class="muted">Penny Pincher · {{ $project->name }} · wygenerowano {{ wp_date('d.m.Y H:i') }}</p>
  <h1>{{ $plan['name'] }}</h1>
  <p class="muted">Status: {{ PlanStatus::LABELS[$plan['status']] }} · cel: {{ BusinessContext::GOALS[$plan['goal']] ?? '—' }} · usługa: {{ $plan['service'] ?: '—' }} · lokalizacja: {{ $plan['location'] ?: '—' }} · język: {{ BusinessContext::LANGUAGES[$plan['language']] ?? $plan['language'] }}</p>
  @if ($plan['audience'])<p><strong>Grupa docelowa:</strong> {{ $plan['audience'] }}</p>@endif
  <p class="note">Plan roboczy przygotowany w Penny Pincher. Nie został utworzony na koncie Google Ads – wdrożenie wykonuje się ręcznie. Kwoty i prognozy są orientacyjne.</p>

  @if (! $measurement['reliable'])<p class="warn"><strong>Pomiar konwersji:</strong> {{ $measurement['reason'] }} Prognozy konwersji są niedostępne; ostrożnie z dopasowaniem przybliżonym.</p>@endif

  <h2>Budżet</h2>
  <p>Budżet planu: <strong>{{ $budget['budget'] !== null ? $money($budget['budget']) . ' / mies.' : 'nie ustawiono' }}</strong>@if ($budget['daily'] !== null) (ok. {{ $money($budget['daily']) }} dziennie)@endif · przydzielono: {{ $money($budget['allocated']) }} · scenariusz: {{ $budget['config']['label'] }}</p>
  @foreach ($budget['warnings'] as $warning)<p class="warn">{{ $warning }}</p>@endforeach
  <table>
    <thead><tr><th>Kampania</th><th class="num">Budżet / mies.</th><th class="num">Dziennie</th><th class="num">Szac. CPC</th><th class="num">Kliknięcia / mies.</th><th>Konwersje</th><th>Niepewność</th></tr></thead>
    <tbody>
      @foreach ($budget['rows'] as $row)
        @php $f = $row['forecast']; @endphp
        <tr>
          <td>{{ $row['name'] }}</td>
          <td class="num">{{ $row['budget_micros'] !== null ? $money($row['budget_micros']) : '—' }}</td>
          <td class="num">{{ $f['daily'] !== null ? $money($f['daily']) : '—' }}</td>
          <td class="num">{{ $f['cpc'] !== null ? Format::money($f['cpc_low'], $currency) . '–' . Format::money($f['cpc_high'], $currency) : 'brak danych' }}</td>
          <td class="num">{{ $f['clicks_low'] !== null ? Format::number($f['clicks_low']) . '–' . Format::number($f['clicks_high']) : '—' }}</td>
          <td>{{ $f['lead_message'] ?? (rtrim(rtrim(number_format($f['conversions_low'], 1, ',', ''), '0'), ',') . '–' . rtrim(rtrim(number_format($f['conversions_high'], 1, ',', ''), '0'), ',')) }}</td>
          <td>{{ ['low' => 'niska', 'medium' => 'średnia', 'high' => 'wysoka'][$f['uncertainty']] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <h2>Struktura kampanii</h2>
  @foreach ($tree['campaigns'] as $campaign)
    <h3>{{ $campaign['name'] }} <span class="muted">({{ $campaign['kind'] === 'brand' ? 'brandowa' : 'wyszukiwarka' }}{{ $campaign['final_url'] ? ' · ' . $campaign['final_url'] : '' }})</span></h3>
    @foreach ($campaign['groups'] as $group)
      <p><strong>Grupa reklam: {{ $group['name'] }}</strong>{{ $group['final_url'] ? ' · ' . $group['final_url'] : '' }}</p>
      @if ($group['keywords'])
        <table>
          <thead><tr><th>Słowo kluczowe</th><th>Dopasowanie</th><th>Intencja</th><th class="num">Score</th><th class="num">Szac. CPC</th><th class="num">Wolumen</th></tr></thead>
          <tbody>
            @foreach ($group['keywords'] as $keyword)
              <tr>
                <td>{{ MatchType::notation($keyword['phrase'], $keyword['match_type']) }}</td>
                <td>{{ MatchType::LABELS[$keyword['match_type']] ?? $keyword['match_type'] }}</td>
                <td>{{ Text::INTENT_LABELS[$keyword['intent']] ?? '—' }}</td>
                <td class="num">{{ $keyword['score'] ?? '—' }}</td>
                <td class="num">{{ $keyword['cpc_micros'] !== null ? Format::money((int) $keyword['cpc_micros'], $keyword['cpc_currency'] ?? $currency) : 'brak danych' }}</td>
                <td class="num">{{ $keyword['search_volume'] === null ? 'brak danych' : Format::number($keyword['search_volume']) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
      @foreach ($group['ads'] as $ad)
        <p class="muted">Reklama · {{ $ad['final_url'] ?: 'bez URL' }}{{ $ad['path1'] ? ' · /' . $ad['path1'] . ($ad['path2'] ? '/' . $ad['path2'] : '') : '' }}{{ $ad['cta'] ? ' · ' . $ad['cta'] : '' }}</p>
        <ul>@foreach ($ad['headlines'] as $headline)<li>{{ $headline }}</li>@endforeach</ul>
        <ul>@foreach ($ad['descriptions'] as $description)<li>{{ $description }}</li>@endforeach</ul>
      @endforeach
    @endforeach
  @endforeach

  <h2>Wykluczenia</h2>
  @if ($tree['negatives'])
    <table>
      <thead><tr><th>Wykluczenie</th><th>Poziom</th><th>Zakres</th></tr></thead>
      <tbody>
        @foreach ($tree['negatives'] as $negative)
          <tr><td>{{ MatchType::notation($negative['phrase'], $negative['match_type']) }}</td><td>{{ PlanStatus::LEVELS[$negative['level']] ?? $negative['level'] }}</td><td>{{ $negative['level'] === 'campaign' ? ($tree['campaigns'][(int) $negative['plan_campaign_id']]['name'] ?? '—') : ($negative['level'] === 'ad_group' ? '#' . $negative['plan_ad_group_id'] : 'cały plan') }}</td></tr>
        @endforeach
      </tbody>
    </table>
  @else
    <p class="muted">Brak.</p>
  @endif
  @if ($conflicts)
    <p class="warn"><strong>Konflikty:</strong></p>
    <ul>@foreach ($conflicts as $conflict)<li>{{ $conflict['message'] }}</li>@endforeach</ul>
  @endif

  @if ($plan['notes'])<h2>Notatki</h2><p>{!! nl2br(e($plan['notes'])) !!}</p>@endif
</main>
</body>
</html>
