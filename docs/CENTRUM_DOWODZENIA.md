# Centrum dowodzenia (Przegląd 2.0)

Flaga `pp2_dashboard` (stała `PP2_DASHBOARD` w `wp-config.php` albo filtr `pennypincher/feature/pp2_dashboard`),
domyślnie wyłączona. Adres bez zmian: `/app/projects/{id}/` (alias `command-center/`). Flaga jest niezależna od
`pp2_navigation` – z nawigacją 2.0 nagłówek i okruszki to „Centrum dowodzenia”.

## Źródła danych – bez nowych obliczeń

`DashboardController::show` liczy dane tak samo dla obu widoków (`DashboardService::build`, `health`, `optimization`),
więc KPI są identyczne ze starym Przeglądem w tym samym zakresie dat (`tests/Integration/CommandCenterTest`).
Nowe są tylko odczyty istniejących rekordów:

- `DashboardService::activity()` – ostatnie zdarzenia: importy, synchronizacje Google Ads, audyty, analizy (Budget Leaks,
  słowa kluczowe), zestawy zmian, alerty; najnowsze najpierw (`latestEvents`), 8 pozycji, każda z linkiem do szczegółów.
- `DashboardService::newAlerts()` – nowe alerty monitoringu (status `new`), najpoważniejsze najpierw.

## Układ

1. **Pierwsze kroki** (tylko gdy coś brakuje): dane konta (Google Ads albo import), budżet miesięczny, pierwsza analiza –
   jedna karta zamiast powtarzanych komunikatów „brak danych”. Bez danych nie ma KPI ani szczegółów (żadnych zer zastępczych).
2. Ostrzeżenia jakości danych z audytu, pasek pokrycia z oznaczeniem źródła (API / import / demo).
3. **4 KPI**: wydatki, konwersje, koszt konwersji, CTR (ze średnim CPC w podpisie).
4. **Budżet**: wykorzystanie budżetu w wybranym zakresie + tempo bieżącego miesiąca z prognozą (oznaczoną jako szacunek).
5. **Co warto zrobić dzisiaj**: 3 rekomendacje o najwyższym `priority_score` (źródło, pewność, wpływ tylko gdy
   generator podał metodologię w `impact_label`) oraz „Gdzie tracimy pieniądze”: 3 największe wycieki z ostatniej
   analizy i nowe alerty – z linkiem do dowodów (`#leak-…`, `#alert-…`); potencjał oszczędności tylko jako SZACUNEK.
6. **Źródła danych**: Google Ads API (konto, ostatnia synchronizacja, błąd ostatniej), importy (ostatni raport),
   audyt i jakość danych. **Akcje**: analiza bieżącego zakresu dat (formularz `analysis`, wymaga `ImportData`,
   powrót na Centrum dowodzenia), import, kampanie, plan działania.
7. **Ostatnie zdarzenia**.
8. **Szczegóły eksperckie** (`<details>`, otwarte przez `?details=expert`): kliknięcia, wyświetlenia, CPC, budżet
   miesięczny, wykresy dzienne (tylko z danych dziennych – z raportów okresowych wyłącznie sumy), tabele kampanii,
   wyszukiwanych haseł i konwersji oraz najważniejsze wyniki audytu – te same partiale co w starym Przeglądzie.

Wspólne partiale obu widoków: `dashboard-budget-range`, `dashboard-pacing`, `dashboard-campaigns`,
`dashboard-search-terms`, `dashboard-conversions` (wydzielone ze starego widoku bez zmian treści).

## Niezaimplementowane (świadomie)

- Tryb Prosty/Ekspercki jako przełącznik w topbarze – zamiast tego rozwijane „Szczegóły eksperckie”.
- Aktualność danych w topbarze – data ostatniej synchronizacji i importu jest w karcie „Źródła danych”.
- Jeden zakres dat dla analizy: analiza z Centrum dowodzenia dostaje zakres dashboardu jako własny okres,
  ale moduły analizy dalej mają osobno zapamiętany okres.

## Rollback

Wyłączenie `pp2_dashboard` przywraca stary Przegląd (`pages/dashboard.blade.php`).
