# Penny Pincher 2.0 – wydanie, stopniowe włączanie i rollback

Przebudowa interfejsu jest warstwą prezentacji nad istniejącymi trasami, danymi i regułami. Każdy nowy ekran jest
za flagą (domyślnie wyłączoną); bez flag aplikacja działa jak przed przebudową. Brak migracji bazy.

## Changelog

### Nowe (za flagami)
| Flaga | Co włącza | Dokumentacja |
|---|---|---|
| `pp2_navigation` | Nowy sidebar: 6 sekcji, selektor projektu, status Google Ads/importów | `NAWIGACJA.md`, `UI.md` |
| `pp2_dashboard` | Centrum dowodzenia zamiast Przeglądu (te same KPI, TOP 3, wycieki, status danych) | `CENTRUM_DOWODZENIA.md` |
| `pp2_campaigns` | Widok kampanii z zakładkami Wyniki / Wyszukiwane hasła / Optymalizacja / Planowanie | `KAMPANIE.md` |
| `pp2_optimization` | Wspólny punkt wejścia Optymalizacji (`optimization/`) | `OPTYMALIZACJA.md` |
| `pp2_action_plan` | Wspólny inbox Planu działania i ścieżka akcji (rekomendacja → zadanie → zmiana → monitoring) | `PLAN_DZIALANIA.md` |
| `pp2_conversions` | Centrum konwersji (`conversions/center`) | `CENTRUM_KONWERSJI.md` |
| `pp2_management` | Przegląd Zarządzania (`administration/`) | `ZARZADZANIE.md` |
| `pp2_assistant` | Asystent – wyjaśnienia rekomendacji, tylko odczyt, bez AI | `ASYSTENT.md` |

### Zmiany działające niezależnie od flag
- Change Center – dodatkowe bezpieczniki zapisu (`GOOGLE_ADS_WRITE_TESTING.md`): blokada zmian mogących zwiększyć
  wydatki aktywnych kampanii (`ServingGuard::raisesSpend`/`spendViolation`, nieznany stan = blokada), anulowanie
  zawieszonej walidacji i nieprzejętej kolejki (`ChangeStatus::CANCELLABLE`, `cancelQueued`), wyjście ze stanu
  „Wymaga uzgodnienia” bez operacji o nieznanym wyniku.
- Stare widoki złożone z partiali (Konwersje, Conversion Management, Google Ads – diagnostyka) – HTML identyczny
  z poprzednim (porównanie z flagą i bez).
- Testy: naprawiony test zależny od daty (`GoogleAdsSyncTest`), nowe testy modułów 2.0 i
  `RouteSecurityRegressionTest` (CSRF na każdym POST, izolacja na każdej trasie projektu, role).

### Bez zmian
Schemat bazy (bez migracji), 23 reguły audytu (wersja 1.1), API Google Ads v25, OAuth i tokeny, importy, cron
(`pp:sync:work`, jedno zdarzenie WP-Cron), uprawnienia (`Ability`), trasy i deep linki (stare adresy działają;
nowe trasy bez flagi przekierowują do starych modułów z zachowaniem parametrów).

## Włączanie

Flaga = stała w `wp-config.php` serwera, np. `define('PP2_NAVIGATION', true);`. Filtr
`pennypincher/feature/{flaga}` ma ostatnie słowo (np. mu-plugin z `__return_false` jako wyłącznik). Flagi są
globalne dla instancji – nie ma włączania per użytkownik ani per organizacja (świadomie bez nowego frameworka flag;
selektywne włączenie można dodać filtrem, który sprawdza `get_current_user_id()`).

Kolejność (każdy krok sprawdzony lokalnie na projekcie z importem, demo i pustym; także każda flaga osobno):
1. Wdrożenie kodu **bez flag** – zachowanie jak dotąd, poza bezpiecznikami Change Center.
2. `PP2_NAVIGATION`, `PP2_DASHBOARD` – sprawdzić KPI Przeglądu z Centrum dowodzenia w tym samym okresie.
3. `PP2_CAMPAIGNS`, `PP2_OPTIMIZATION`.
4. `PP2_ACTION_PLAN`, `PP2_CONVERSIONS`, `PP2_MANAGEMENT`.
5. `PP2_ASSISTANT`.

Po każdym kroku (min. jeden dzień pracy z prawdziwym kontem):
- log błędów PHP serwera (`wp-content/debug.log` / log hostingu) – brak nowych wpisów z `app/PennyPincher`,
- odpowiedzi 5xx w logu dostępu dla `/app/`,
- `wp acorn pp:google-ads:check` i ostatni przebieg `pp:sync:work` – bez nowych błędów,
- liczebności tabel `pp_*` przed/po (projekty, kampanie, importy, audyty, rekomendacje, historia) – bez spadków.

Warunek przejścia dalej: 0 nowych błędów i zgodne liczby; inaczej rollback kroku.

## Rollback

- **Flaga:** usunąć (albo ustawić `false`) stałą w `wp-config.php` – działa od następnego żądania, bez builda,
  cache i migracji. Dane i trasy są wspólne, nic nie trzeba przywracać.
- **Kod:** `scripts/rollback.sh {kopia}` – same pliki motywu (`DEPLOYMENT.md` → „Wycofanie”). Bez `--database`:
  wydanie nie ma migracji, a przywrócenie bazy nadpisałoby dane zapisane po kopii.
- Próba lokalna: włączenie flag po kolei i wycofanie w odwrotnej kolejności – po pełnym wyłączeniu HTML wszystkich
  86 sprawdzanych stron identyczny ze stanem wyjściowym.

## Usuwanie legacy – NIE wykonane

Stare widoki są fallbackiem i zostają do decyzji właściciela. Kryteria usunięcia każdego elementu: flaga włączona na
produkcji przez uzgodniony czas bez błędów, potwierdzony parytet (macierz w `NAWIGACJA.md`) i jawna zgoda.
Kandydaci (po zgodzie): stary Przegląd (`dashboard.blade.php`) po `pp2_dashboard`; stara nawigacja w
layoucie po `pp2_navigation`; rozgałęzienia `Feature::enabled` w kontrolerach i `Navigation`. Moduły źródłowe (Audyt, Budget
Leaks, Rekomendacje, Action Plan, Monitoring, Change Center, Konwersje, Google Ads, Importy, Ustawienia) są częścią
nowej nawigacji i **nie są** legacy do usunięcia. Stare adresy URL zostają na stałe (deep linki).

## Niezaimplementowane
- Status „Obserwowane” w inboxie Planu działania.
- Model AI w asystencie (wymaga decyzji: dostawca, klucz, zgoda na przekazanie danych klienta).
- Włączanie flag per użytkownik/organizacja.
