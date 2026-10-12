# Penny Pincher – architektura

## Stos

WordPress 7.1 · Sage 11 · Acorn 5 (komponenty Laravel 12) · PHP ≥ 8.2 · Blade · Tailwind CSS 4 · Alpine.js · Vite 8 · MySQL/MariaDB (InnoDB).

Cały kod aplikacji jest w motywie – bez osobnej wtyczki i bez frameworka SPA.

## Struktura

```
routes/app.php                         trasy /app/ (Acorn)
app/PennyPincher/
  Providers/PennyPincherServiceProvider  rejestracja modułu, renderowanie 403/404, hooki WP, komendy
  Http/Controllers/                      cienkie kontrolery (żądanie → serwis → widok)
  Http/Middleware/                       PrepareAppRequest, Authenticate, VerifyNonce
  Http/Responses.php                     strony błędów + nagłówki bezpieczeństwa
  Domain/Access/                         Role, Ability, Policy, AccessDenied
  Domain/Projects/Project.php            obiekt projektu (z rolą bieżącego użytkownika)
  Domain/Metrics/                        Money, MetricCalculator, DateRange, PeriodResolver, BudgetCalculator
  Domain/Import/                         definicje raportów, parsowanie liczb i dat, normalizacja wierszy
  Domain/Support/                        NotFound, ValidationFailed
  Services/                              AccessService, ImportService, DashboardService, ProjectValidator, …
  Repositories/                          zapytania SQL (zawsze zawężone do projektu)
  Infrastructure/Database/               Db ($wpdb + transakcje), Migrator, Schema
  Infrastructure/Import/                 czytniki CSV/XLSX, FileInspector, TableAnalyzer, Writers
  Jobs/ProcessImport.php                 walidacja + zapis importu (samodzielne zadanie)
  Console/                               komendy `wp acorn pp:*`
  Support/                               AppUrl, Flash, Format, Paginator, LineChart, DateRangeRequest
resources/views/app/                     layouty, strony i partiale aplikacji
resources/views/components/pp/           komponenty Blade (<x-pp.*>)
resources/css/pp-app.css, resources/js/pp-app.js   osobny entry Vite aplikacji
tests/                                   runner, testy jednostkowe i integracyjne, pliki przykładowe
```

Zależności między warstwami: kontrolery → serwisy → repozytoria/domena. Domena nie zna kontrolerów, widoków ani bazy (wyjątek: `Project::timezone()` korzysta z `wp_timezone()` jako zapasowej strefy).

Nowe moduły (Audit, Budget Leaks, Keyword Intelligence…) dostają własne serwisy i repozytoria w tej strukturze; czytają metryki przez `MetricsRepository` + `PeriodResolver`, żeby zachować zasady agregacji.

## Routing

Trasy rejestruje Acorn (`withRouting(using: …)` w `functions.php` → `routes/app.php`). To nie są strony WordPressa – żądanie jest obsługiwane na `parse_request`, więc działa odświeżanie podstron, a WordPress nie generuje własnego 404.

| URL | Widok |
|---|---|
| `/app/login/` | logowanie (GET/POST), `/app/logout/` (POST) |
| `/app/` | przekierowanie do bieżącego projektu albo listy projektów |
| `/app/projects/` | organizacje i projekty, tworzenie organizacji, projekt demo |
| `/app/projects/new/` | nowy projekt |
| `/app/projects/{id}/` | Przegląd (dashboard) |
| `/app/projects/{id}/campaigns/` | kampanie, `/campaigns/{campaign}/` – szczegóły |
| `/app/projects/{id}/search-terms/` | wyszukiwane hasła |
| `/app/projects/{id}/imports/` | import i historia, `/imports/{import}/` – podgląd lub podsumowanie |
| `/app/projects/{id}/settings/` | ustawienia projektu |
| `/app/organizations/{id}/members/` | członkowie organizacji |
| `/app/campaigns/`, `/app/search-terms/`, `/app/imports/`, `/app/settings/` | skróty → bieżący projekt |
| `/app/switch/?project=` | przełącznik projektu |
| inne `/app/*` | 404 aplikacji |

Decyzja: identyfikator projektu jest w URL (zamiast „bieżącego projektu w sesji”), bo przy wielu organizacjach linki muszą być jednoznaczne i bezpieczne do udostępnienia. Skróty z promptu zostały zachowane jako przekierowania. Ostatnio otwierany projekt jest zapamiętywany w user meta `pp_current_project`.

Wymaga „ładnych” bezpośrednich odnośników (Ustawienia → Bezpośrednie odnośniki ≠ „Prosty”), żeby `/app/...` trafiało do `index.php`.

## Bezpieczeństwo

- **Uwierzytelnianie**: standardowe konta i ciasteczka WordPressa (`wp_signon`), własny formularz.
- **Autoryzacja w backendzie**: każdy projekt/organizacja jest pobierany przez `AccessService`, a zapytanie łączy go z `organization_users` dla bieżącego użytkownika. Brak członkostwa = 404 (nie ujawniamy istnienia cudzych danych), niewystarczająca rola = 403. Administrator WordPressa nie ma automatycznego dostępu do danych organizacji – może jedynie zakładać organizacje (filtr `pennypincher/can_create_organization`).
- **Role**: członek – podgląd i import; administrator – + projekty, ustawienia, członkowie; właściciel – + rola właściciela. Organizacja musi mieć co najmniej jednego właściciela.
- **Izolacja danych**: wszystkie tabele danych mają `project_id`; repozytoria zawsze filtrują po projekcie, także przy filtrach z parametrów (np. kampania z innego projektu jest ignorowana).
- **CSRF**: nonce WordPressa (`_pp_nonce`, akcja `pennypincher_app`) dla każdego POST – middleware `VerifyNonce`, odpowiedź 419.
- **SQL**: `Db` wstawia parametry `?` przez `mysqli_real_escape_string` (jak `wpdb::prepare`, ale z obsługą NULL); nazwy kolumn sortowania pochodzą z białej listy; `LIKE` używa `esc_like`.
- **Wyjście**: Blade `{{ }}`; `{!! !!}` tylko dla statycznych ikon SVG.
- **Przekierowania**: `redirect_to` tylko w obrębie `/app/` (`AppUrl::safeRedirect`).
- **Pliki**: limit rozmiaru (filtr `pennypincher/import/max_bytes`, domyślnie 15 MB, nie więcej niż limit PHP), rozszerzenia csv/tsv/txt/xlsx, kontrola sygnatury (XLSX = ZIP z `xl/workbook.xml`, CSV ≠ ZIP/PDF/ELF/PHP), ochrona przed zip bombą i ścieżkami `..`, XML bez sieci (`LIBXML_NONET`). Plik jest czytany z katalogu tymczasowego PHP i nigdy nie trafia do `uploads`.
- **Nagłówki**: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-store`, `noindex`.
- **Sekrety**: aplikacja nie potrzebuje żadnych sekretów (nie używa APP_KEY ani sesji Laravel).

## Model danych

Tabele `{$wpdb->prefix}pp_*`, InnoDB, klucze obce (30), migracje w `Infrastructure/Database/Schema.php`.

| Tabela | Opis |
|---|---|
| `pp_organizations` | organizacje (`slug` unikalny) |
| `pp_organization_users` | członkostwo i rola (`organization_id`, `user_id` unikalne) |
| `pp_projects` | projekty i ich ustawienia; `is_demo` |
| `pp_imports` | importy: plik, hash, kodowanie, mapowanie (JSON), zakres, ziarnistość, liczniki, błędy |
| `pp_import_rows` | surowe wiersze między uploadem a zatwierdzeniem (usuwane po imporcie) |
| `pp_campaigns` | kampanie; `external_id` (Campaign ID) gdy dostępny |
| `pp_ad_groups` | grupy reklam |
| `pp_keywords` | słowa kluczowe (tekst, dopasowanie, stan, wynik jakości, maks. CPC) |
| `pp_search_terms` | wyszukiwane hasła (hasło, dopasowanie, słowo kluczowe, dodane/wykluczone) |
| `pp_conversion_actions` | działania powodujące konwersję |
| `pp_campaign_metrics`, `pp_keyword_metrics`, `pp_search_term_metrics` | metryki: `granularity` (day/period), `date_from`, `date_to`, wyświetlenia, kliknięcia, `cost_micros`, konwersje, wartość, `import_id` |
| `pp_conversion_metrics` | metryki konwersji per działanie (i opcjonalnie kampania – `campaign_scope`, 0 = całe konto) |
| `pp_schema_migrations` | zastosowane migracje |

Zasady:

- **Kwoty**: `BIGINT` w mikrojednostkach (1 zł = 1 000 000), jak `cost_micros` w Google Ads API. Parsowanie i sumy bez floatów. Konwersje: `DECIMAL(18,4)` (bywają ułamkowe).
- **Brak danych**: `NULL`, nie 0.
- **Dane źródłowe vs obliczone**: w bazie są tylko wartości z eksportu; CTR, CPC, koszt konwersji i wykorzystanie budżetu są liczone w `MetricCalculator`/`BudgetCalculator` przy odczycie.
- **Idempotencja**: encje mają `natural_key` (sha1 z identyfikatora Google Ads albo nazwy w kontekście), metryki unikalny klucz `(encja, granularity, date_from, date_to)` → ponowny import nadpisuje te same wiersze.
- **Pochodzenie**: `import_id` w metrykach, `last_import_id` w encjach.
- **Pod Google Ads API**: kolumny `external_id`, mikrojednostki i ziarnistość dzienna odpowiadają danym z API, więc synchronizacja może zapisywać do tych samych tabel.

### Migracje

`Migrator` wykonuje brakujące migracje raz (tabela `pp_schema_migrations`), pod blokadą `GET_LOCK`, tylko addytywnie (`CREATE TABLE IF NOT EXISTS`, nigdy `DROP`). Uruchamia je automatycznie pierwsze żądanie `/app/` po zmianie wersji (tania kontrola opcji `pp_schema_version`) albo ręcznie `wp acorn pp:migrate`.

## Przepływ importu

1. **Upload** (`ImportsController@store` → `ImportService::upload`): `FileInspector` → `CsvReader`/`XlsxReader` → `TableAnalyzer` (wiersze tytułowe, wiersz nagłówków, język, zakres dat z nagłówka) → `ReportRegistry::detect` (typ) → `guessMapping` (aliasy PL/EN) → wykrycie separatora dziesiętnego → zapis importu (`uploaded`) i surowych wierszy w jednej transakcji.
2. **Podgląd** (`/imports/{id}/`): typ (z możliwością zmiany), mapowanie każdego pola na kolumnę, zakres dat, pierwsze wiersze, ostrzeżenie o duplikacie (ten sam hash pliku).
3. **Zatwierdzenie** (`ImportService::confirm` → `Jobs\ProcessImport`): walidacja mapowania i zakresu; `RowNormalizer` typuje każdy wiersz (pomija puste i podsumowania „Total/Łącznie”); jakikolwiek błąd wartości = nic nie jest zapisywane, import czeka na poprawki; zgodność waluty z projektem; zapis encji i metryk przez `Writers` w jednej transakcji; usunięcie surowych wierszy; status `completed`.
4. **Podsumowanie**: okres, ziarnistość, liczba wierszy zaimportowanych/pominiętych, rekordy metryk, zsumowane duplikaty kluczy.

Obsługiwane formaty: CSV (UTF-8 z/bez BOM, UTF-16LE/BE „Excel CSV”, Windows-1250; separator `,` `;` tab `|`), XLSX (pierwszy arkusz; własny strumieniowy czytnik na `ZipArchive` + `XMLReader`). Liczby: `1 234,56`, `1,234.56`, `1.234,56`, waluty, procenty, `(12,50)`, `--`. Daty: ISO, `01.09.2026`, `1 września 2026`, `Sep 1, 2026`, daty seryjne Excela.

Zadanie `ProcessImport` przyjmuje tylko identyfikatory, więc może działać synchronicznie (obecnie), z CLI (`wp acorn pp:import`) albo z kolejki w przyszłości. Porzucone podglądy sprząta `wp acorn pp:imports:prune` (zalecany systemowy cron) – WP-Cron uruchamia to samo zadanie raz dziennie tylko jako wygodę.

## Agregacja i zakresy dat

`PeriodResolver` wybiera dane do zsumowania dla zakresu:

1. tylko dane mieszczące się w całości w zakresie (okresu nie dzielimy na dni),
2. bez nakładania się (dzień w okresie, dwa nakładające się okresy),
3. dwa warianty – „najpierw dni” i „najpierw okresy” (zachłannie od najdłuższego) – wygrywa większe pokrycie dni; przy remisie dane dzienne.

Interfejs pokazuje źródło, pokrycie zakresu (np. 30 z 31 dni), pominięte nakładające się dane i okresy niemieszczące się w zakresie.

Dashboard: KPI i wykresy wyłącznie z raportu kampanii; sekcja haseł z raportu wyszukiwanych haseł z osobnym rozstrzygnięciem okresów. Wykresy tylko z danych dziennych. Budżet zakresu = Σ budżet miesięczny × (dni zakresu w miesiącu / dni miesiąca).

## Interfejs

- Layout `app.layouts.app`: ciemny pasek boczny (logo, przełącznik projektu, nawigacja, użytkownik), górny pasek (organizacja/projekt, tytuł, zakres dat), obszar roboczy. Na telefonie pasek boczny to wysuwany panel (Alpine).
- Komponenty `<x-pp.*>`: `button`, `stat-card`, `status-badge`, `empty-state`, `alert`, `modal`, `field`, `filter-bar`, `sort-header`, `pagination`, `date-range`, `line-chart`, `icon`, `nonce`.
- Paleta: papier `#f2f4f1`, atrament `#13221c`, mięta (akcje) `#1d6b52`, miedź (wydatki) `#a5531f`; nagłówek wydatków i logo w kroju PolySans Bulky Wide z motywu, reszta – systemowy sans z cyframi tabelarycznymi.
- Wykresy: SVG generowane w PHP (`Support/LineChart`), brakujące dni przerywają linię.
- CSS/JS aplikacji to osobny entry Vite (`pp-app.css` z `@import 'tailwindcss' source(none)`), a `app.css` strony ma `@source not` na katalogi aplikacji – style strony i aplikacji się nie mieszają.

## Decyzje techniczne

| Decyzja | Powód |
|---|---|
| `$wpdb` zamiast Eloquent/PDO | W LocalWP PDO często nie widzi socketu MySQL; `$wpdb` działa wszędzie tam, gdzie WP. |
| Nonce i sesja WordPressa zamiast middleware `web` Laravela | Brak potrzeby APP_KEY i sesji plikowych; zgodność z wtyczkami logowania. |
| Własny czytnik XLSX | Packagist był niedostępny w środowisku budowy; potrzebny jest tylko odczyt jednego arkusza. Można go zastąpić np. `openspout/openspout` bez zmian w reszcie kodu. |
| Wykresy SVG po stronie serwera | Dane i tak liczy backend; brak dodatkowej zależności JS. |
| Mikrojednostki w BIGINT | Brak błędów precyzji i zgodność z Google Ads API. |
| Transakcje z SAVEPOINT i wspólnym licznikiem | Zagnieżdżone operacje (np. projekt demo → importy) nie mogą zatwierdzić zewnętrznej transakcji. |
| Synchroniczne przetwarzanie importów | Pliki eksportów są małe; zadanie jest gotowe do przeniesienia do kolejki. |
