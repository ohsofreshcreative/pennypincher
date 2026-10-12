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
  Domain/GoogleAds/Enums.php             mapowanie enumów Google Ads (statusy, kategorie konwersji, strategie)
  Domain/Audit/                          Rule, Finding, AuditContext, Severity, Dimension, Category, AuditScore, RuleRegistry, Rules/*
  Domain/Support/                        NotFound, ValidationFailed
  Domain/Analysis/                       AnalysisPeriod (okres analizy), Statistics (pewność statystyczna), Text (normalizacja, intencje)
  Domain/Leaks/                          Leak, LeakContext, LeakDetector (reguły BL1xx–BL4xx), SavingsEstimate
  Domain/Keywords/                       OpportunityScore, KeywordAnalyzer (możliwości i wykluczenia), SeedSelector
  Domain/Recommendations/                RecommendationGenerator (łączenie, deduplikacja, priorytet)
  Domain/Conversions/ConversionClass     klasy biznesowe konwersji
  Domain/Projects/BusinessContext        kontekst biznesowy projektu (pola opcjonalne)
  Services/                              AccessService, ImportService, DashboardService, ProjectValidator, …
  Repositories/                          zapytania SQL (zawsze zawężone do projektu)
  Infrastructure/Database/               Db ($wpdb + transakcje), Migrator, Schema
  Infrastructure/Import/                 czytniki CSV/XLSX, FileInspector, TableAnalyzer, Writers
  Infrastructure/GoogleAds/              Config, GoogleHttp (biała lista hostów), OAuthClient, GoogleAdsClient, Sync/Gaql, Sync/ApiSyncer
  Infrastructure/DataForSeo/             DataForSeoConfig, DataForSeoHttp (biała lista hostów, Basic Auth, klasyfikacja błędów), DataForSeoClient, DataForSeoException
  Infrastructure/Security/               SecretBox (szyfrowanie tokenów), Hardening (utwardzenie WordPressa)
  Services/GoogleAds/                    ConnectionService (OAuth, tokeny, konta), SyncService (kolejka synchronizacji)
  Services/Audit/AuditContextBuilder     dane wejściowe audytu (okres, stan aktywności); Services/AuditService – uruchamianie i statusy
  Services/Analysis/                     AnalysisService (potok), AnalysisDataBuilder, BudgetLeakService, KeywordOpportunityService,
                                         RecommendationService, ConversionBreakdown
  Services/DataForSeo/DataForSeoService  plan, zgoda, limity, kolejka, worker, pamięć podręczna
  Jobs/ProcessImport.php                 walidacja + zapis importu (samodzielne zadanie)
  Console/                               komendy `wp acorn pp:*`
  Support/                               AppUrl, Flash, Format, Paginator, LineChart, DateRangeRequest, AnalysisPeriodRequest
resources/views/app/                     layouty, strony i partiale aplikacji
resources/views/components/pp/           komponenty Blade (<x-pp.*>)
resources/css/pp-app.css, resources/js/pp-app.js   osobny entry Vite aplikacji
tests/                                   runner, testy jednostkowe i integracyjne, pliki przykładowe, Support/GoogleAdsMock
scripts/deploy.sh, scripts/rollback.sh   wdrożenie na Hostinger i wycofanie (docs/DEPLOYMENT.md)
.htaccess                                blokada dostępu z sieci do plików motywu poza assetami
```

Zależności między warstwami: kontrolery → serwisy → repozytoria/domena. Domena nie zna kontrolerów, widoków ani bazy (wyjątek: `Project::timezone()` korzysta z `wp_timezone()` jako zapasowej strefy).

Moduły analizy (Budget Leaks, Rekomendacje, Keyword Opportunities) mają warstwy: pobranie (`AnalysisDataBuilder`, `MetricsRepository` + `PeriodResolver`, `DataForSeoClient`) → normalizacja (`Text`, `ConversionBreakdown`) → analiza (`LeakDetector`, `KeywordAnalyzer`) → ocena (`Statistics`, `OpportunityScore`, `Leak::priorityScore`) → rekomendacje (`RecommendationGenerator`) → prezentacja (kontrolery i widoki czytają wyłącznie zapisane wyniki).

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
| `/app/projects/{id}/google-ads/` | połączenie, wybór konta, historia synchronizacji; POST `connect/`, `account/`, `sync/`, `sync/{run}/retry/`, `disconnect/` |
| `/app/google-ads/callback/` | powrót z OAuth Google (projekt i użytkownik ze state) |
| `/app/projects/{id}/audits/` | audyt wybranego okresu (`?period=last_30\|last_90\|last_180\|last_365\|custom&from=&to=`), `/audits/{run}/` – audyt z historii; POST `audits/` (uruchom), `audits/findings/{id}/status/` |
| `/app/projects/{id}/budget-leaks/` | Budget Leaks okresu (filtry: kampania, kategoria, priorytet, status); POST `budget-leaks/{id}/status/` |
| `/app/projects/{id}/recommendations/` | rekomendacje (filtry, sortowanie, `?open=` – historia); POST `recommendations/{id}/status/`, `/note/`, `/obsolete/` |
| `/app/projects/{id}/keywords/` | Keyword Opportunities (`?tab=opportunities\|negatives\|dataforseo`); POST `keywords/dataforseo/` (zlecenie po zgodzie), `keywords/dataforseo/test/`, `keywords/dataforseo/limit/` |
| `/app/projects/{id}/conversions/` | klasyfikacja konwersji (GET, POST) |
| POST `/app/projects/{id}/analysis/` | pełna analiza okresu (audyt → Budget Leaks → słowa → rekomendacje) |
| POST `/app/projects/{id}/settings/context/` | kontekst biznesowy; POST `google-ads/backfill/` – ponowne pobranie historii |
| `/app/organizations/{id}/members/` | członkowie organizacji |
| `/app/campaigns/`, `/app/search-terms/`, `/app/imports/`, `/app/settings/`, `/app/google-ads/`, `/app/audits/`, `/app/budget-leaks/`, `/app/recommendations/`, `/app/keywords/`, `/app/conversions/` | skróty → bieżący projekt |
| `/app/switch/?project=` | przełącznik projektu |
| inne `/app/*` | 404 aplikacji |

Decyzja: identyfikator projektu jest w URL (zamiast „bieżącego projektu w sesji”), bo przy wielu organizacjach linki muszą być jednoznaczne i bezpieczne do udostępnienia. Skróty z promptu zostały zachowane jako przekierowania. Ostatnio otwierany projekt jest zapamiętywany w user meta `pp_current_project`.

Wymaga „ładnych” bezpośrednich odnośników (Ustawienia → Bezpośrednie odnośniki ≠ „Prosty”), żeby `/app/...` trafiało do `index.php`.

## Bezpieczeństwo

- **Uwierzytelnianie**: standardowe konta i ciasteczka WordPressa (`wp_signon`), własny formularz; limit prób logowania `LoginThrottle` (szczegóły i plan dla klucza SSH: `docs/SECURITY.md`).
- **Autoryzacja w backendzie**: każdy projekt/organizacja jest pobierany przez `AccessService`, a zapytanie łączy go z `organization_users` dla bieżącego użytkownika. Brak członkostwa = 404 (nie ujawniamy istnienia cudzych danych), niewystarczająca rola = 403. Administrator WordPressa nie ma automatycznego dostępu do danych organizacji – może jedynie zakładać organizacje (filtr `pennypincher/can_create_organization`).
- **Role**: członek – podgląd i import; administrator – + projekty, ustawienia, członkowie; właściciel – + rola właściciela. Organizacja musi mieć co najmniej jednego właściciela.
- **Izolacja danych**: wszystkie tabele danych mają `project_id`; repozytoria zawsze filtrują po projekcie, także przy filtrach z parametrów (np. kampania z innego projektu jest ignorowana).
- **CSRF**: nonce WordPressa (`_pp_nonce`, akcja `pennypincher_app`) dla każdego POST – middleware `VerifyNonce`, odpowiedź 419.
- **SQL**: `Db` wstawia parametry `?` przez `mysqli_real_escape_string` (jak `wpdb::prepare`, ale z obsługą NULL); nazwy kolumn sortowania pochodzą z białej listy; `LIKE` używa `esc_like`.
- **Wyjście**: Blade `{{ }}`; `{!! !!}` tylko dla statycznych ikon SVG.
- **Przekierowania**: `redirect_to` tylko w obrębie `/app/` (`AppUrl::safeRedirect`).
- **Pliki**: limit rozmiaru (filtr `pennypincher/import/max_bytes`, domyślnie 15 MB, nie więcej niż limit PHP), rozszerzenia csv/tsv/txt/xlsx, kontrola sygnatury (XLSX = ZIP z `xl/workbook.xml`, CSV ≠ ZIP/PDF/ELF/PHP), ochrona przed zip bombą i ścieżkami `..`, XML bez sieci (`LIBXML_NONET`). Plik jest czytany z katalogu tymczasowego PHP i nigdy nie trafia do `uploads`.
- **Nagłówki**: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-store`, `noindex`.
- **Sekrety**: `PP_GOOGLE_ADS_CLIENT_ID/SECRET`, `PP_ENCRYPTION_KEY` oraz `PP_DATAFORSEO_LOGIN/PASSWORD` w `wp-config.php`/zmiennych środowiskowych, nigdy w repozytorium ani bazie; tokeny OAuth w bazie są szyfrowane (`SecretBox`). Dane DataForSEO trafiają wyłącznie do nagłówka `Authorization` żądań na `api.dataforseo.com`/`sandbox.dataforseo.com` i nie są logowane ani wyświetlane. Aplikacja nie używa APP_KEY ani sesji Laravel.
- **Brak zmian na kontach**: żaden moduł nie wysyła mutacji do Google Ads (brak słów, wykluczeń, budżetów, ustawień konwersji) – Penny Pincher tylko czyta i proponuje. Płatne zapytania DataForSEO wymagają zgody użytkownika z rolą administratora projektu i miesięcznego limitu.
- **WordPress i serwer**: `Hardening` (rejestracja, XML-RPC, wyliczanie użytkowników), `.htaccess` motywu, HSTS; pełna lista: `docs/SECURITY.md`, `docs/DEPLOYMENT.md`.

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
| `pp_google_ads_connections` | połączenie projektu z kontem: `customer_id`, `login_customer_id` (MCC), waluta, strefa, e-mail Google, zaszyfrowane tokeny, status (`pending_account`, `active`, `reauth_required`, `disconnected`, `error`) |
| `pp_sync_runs` | przebiegi synchronizacji: typ, okno dat, kroki (JSON), `step_index/steps_total`, rekordy, próby, `next_attempt_at`, błąd, dziennik kroków |
| `pp_data_coverage` | okna dat, za które dane z API są kompletne (per projekt, źródło, zbiór danych) |
| `pp_audit_runs` | audyty: wynik (NULL = brak wyniku), rozbicie wyniku i jakość danych (JSON), zakres, wersja reguł, wyzwalacz |
| `pp_audit_findings` | problemy: reguła, kategoria, ważność, opis, dowody (JSON), rekomendacja, encja, odcisk, kara, obszar wyniku, status |
| `pp_negative_keywords` | słowa wykluczające z Google Ads (kampania, grupa reklam, listy wspólne) – pełna wymiana przy synchronizacji |
| `pp_analysis_runs` | przebiegi analiz (`budget_leaks`, `keywords`): okres, preset, podsumowanie (JSON), wyzwalacz |
| `pp_budget_leaks` | wykryte wycieki: reguła, kategoria, odcisk, encja, koszt, kliknięcia, konwersje (Google i wartościowe), pewność, priorytet, szacunek oszczędności, dowody, status |
| `pp_keyword_opportunities`, `pp_negative_suggestions` | wyniki analizy słów: frazy z Opportunity Score i rozbiciem, propozycje wykluczeń (`already_excluded`) |
| `pp_recommendations` | rekomendacje – jedna na odcisk w projekcie (unikalny klucz), status, notatka, nieaktualność, okres ostatniej analizy |
| `pp_recommendation_events` | historia: utworzenie, zmiany statusu, notatki, nieaktualność, przywrócenie |
| `pp_dataforseo_tasks` | zadania DataForSEO: endpoint, zadanie (JSON), hash, status, szacunek i koszt faktyczny, próby, błąd, kto zlecił i kiedy zatwierdził |
| `pp_dataforseo_cache` | odpowiedzi API (klucz = endpoint + treść zadania + tryb), ważność |
| `pp_keyword_ideas` | pomysły słów z DataForSEO per projekt, lokalizacja i język (unikalne po frazie znormalizowanej) |
| `pp_schema_migrations` | zastosowane migracje |

Migracje: `2026_10_12_000001_initial_schema`, `2026_10_12_000002_view_through_conversions` (kolumna `view_through_conversions` w tabelach metryk, dodawana tylko jeśli jej brakuje), `2026_10_12_000003_google_ads_sync_audit` (tabele wyżej; w tabelach metryk `source` (`import`/`api`), `sync_run_id`, `import_id` może być NULL, unikalny klucz rozszerzony o `source` – nowy klucz powstaje przed usunięciem starego; ustawienia kampanii (`settings` JSON), status grup reklam, ustawienia działań konwersji: `category_code`, `primary_for_goal`, `counting_type`, `type`, `settings`). `2026_10_12_000004_analysis_modules` (tabele modułów analizy wyżej; `projects.business_context` (JSON), `projects.dataforseo_monthly_limit`; `conversion_actions.business_class/business_goal/classified_by/classified_at`; `audit_runs.range_preset` + indeks okresu; `conversion_metrics` per kampania przez istniejące `campaign_scope`). Wszystkie zmiany są warunkowe (sprawdzenie `information_schema`) i addytywne – istniejące dane się nie zmieniają.

Zasady:

- **Kwoty**: `BIGINT` w mikrojednostkach (1 zł = 1 000 000), jak `cost_micros` w Google Ads API. Parsowanie i sumy bez floatów. Konwersje: `DECIMAL(18,4)` (bywają ułamkowe).
- **Brak danych**: `NULL`, nie 0.
- **Dane źródłowe vs obliczone**: w bazie są tylko wartości z eksportu; CTR, CPC, koszt konwersji i wykorzystanie budżetu są liczone w `MetricCalculator`/`BudgetCalculator` przy odczycie.
- **Idempotencja**: encje mają `natural_key` (sha1 z identyfikatora Google Ads albo nazwy w kontekście), metryki unikalny klucz `(encja, granularity, date_from, date_to)` → ponowny import nadpisuje te same wiersze.
- **Pochodzenie**: `import_id` w metrykach, `last_import_id` w encjach.
- **Google Ads API**: synchronizacja zapisuje do tych samych tabel (`source = 'api'`, dane dzienne); szczegóły w `docs/GOOGLE_ADS.md`.

### Migracje

`Migrator` wykonuje brakujące migracje raz (tabela `pp_schema_migrations`), pod blokadą `GET_LOCK`, tylko addytywnie (`CREATE TABLE IF NOT EXISTS`, nigdy `DROP`). Uruchamia je automatycznie pierwsze żądanie `/app/` po zmianie wersji (tania kontrola opcji `pp_schema_version`) albo ręcznie `wp acorn pp:migrate`.

## Przepływ importu

1. **Upload** (`ImportsController@store` → `ImportService::upload`): `FileInspector` → `CsvReader`/`XlsxReader` → `TableAnalyzer` (wiersze tytułowe, wiersz nagłówków, język, zakres dat z nagłówka) → `ReportRegistry::detect` (typ) → `guessMapping` (aliasy PL/EN) → wykrycie separatora dziesiętnego → zapis importu (`uploaded`) i surowych wierszy w jednej transakcji.
2. **Podgląd** (`/imports/{id}/`): typ (z możliwością zmiany), mapowanie każdego pola na kolumnę, zakres dat, pierwsze wiersze, ostrzeżenie o duplikacie (ten sam hash pliku).
3. **Zatwierdzenie** (`ImportService::confirm` → `Jobs\ProcessImport`): walidacja mapowania i zakresu; `RowNormalizer` typuje każdy wiersz (pomija puste i podsumowania „Total/Łącznie”); jakikolwiek błąd wartości = nic nie jest zapisywane, import czeka na poprawki; zgodność waluty z projektem; zapis encji i metryk przez `Writers` w jednej transakcji; usunięcie surowych wierszy; status `completed`.
4. **Podsumowanie**: okres, ziarnistość, liczba wierszy zaimportowanych/pominiętych, rekordy metryk, zsumowane duplikaty kluczy.

Rozpoznawanie struktury eksportu (`TableAnalyzer::headerIndex`): wiersz nagłówków to wiersz w pierwszych 30, który ma co najmniej 2 niepuste komórki (tytuł raportu i zakres dat to zwykle jedna komórka) oraz co najmniej 2 znane nagłówki albo 1 nagłówek identyfikujący typ raportu (np. „Działania powodujące konwersję”). Wygrywa wiersz z największą liczbą znanych nagłówków. Nagłówki są porównywane po kluczu `HeaderNormalizer::key` – bez wielkości liter, polskich znaków, kropek, cudzysłowów, myślników i dopisków w nawiasach („Koszt (PLN)” = „koszt”), a aliasy obejmują polskie i angielskie nazwy z interfejsu Google Ads i Edytora raportów (liczba pojedyncza i mnoga).

Konwersje po obejrzeniu (`view_through_conversions`) są osobną kolumną we wszystkich tabelach metryk i nigdy nie są dodawane do konwersji.

Obsługiwane formaty: CSV (UTF-8 z/bez BOM, UTF-16LE/BE „Excel CSV”, Windows-1250; separator `,` `;` tab `|`), XLSX (pierwszy arkusz; własny strumieniowy czytnik na `ZipArchive` + `XMLReader`). Liczby: `1 234,56`, `1,234.56`, `1.234,56`, waluty, procenty, `(12,50)`, `--`. Daty: ISO, `01.09.2026`, `1 września 2026`, `Sep 1, 2026`, daty seryjne Excela.

Zadanie `ProcessImport` przyjmuje tylko identyfikatory, więc może działać synchronicznie (obecnie), z CLI (`wp acorn pp:import`) albo z kolejki w przyszłości. Porzucone podglądy sprząta `wp acorn pp:imports:prune` (zalecany systemowy cron) – WP-Cron uruchamia to samo zadanie raz dziennie tylko jako wygodę.

## Agregacja i zakresy dat

`PeriodResolver` wybiera dane do zsumowania dla zakresu:

1. tylko dane mieszczące się w całości w zakresie (okresu nie dzielimy na dni),
2. bez nakładania się (dzień w okresie, dwa nakładające się okresy),
3. dwa warianty – „najpierw dni” i „najpierw okresy” (zachłannie od najdłuższego) – wygrywa większe pokrycie dni; przy remisie dane dzienne.

Dane z API mają pierwszeństwo: zakresy z `pp_data_coverage` są traktowane jako kompletne dane dzienne (dzień bez wiersza = 0), a importowane dni i okresy nachodzące na nie są pomijane i liczone w `overriddenByApi`. Kroki 1–3 dotyczą tylko dni poza zakresami API.

Interfejs pokazuje źródło, pokrycie zakresu (np. 30 z 31 dni), pominięte nakładające się dane i okresy niemieszczące się w zakresie.

Dashboard: KPI i wykresy wyłącznie z raportu kampanii; sekcja haseł z raportu wyszukiwanych haseł i sekcja działań powodujących konwersję z raportu konwersji – każda z osobnym rozstrzygnięciem okresów. Przy niepełnym pokryciu zakresu wykorzystanie budżetu jest liczone względem budżetu dla dni z danymi (`BudgetCalculator::budgetForCoverage`). „Wszystkie dane” to zakres od najwcześniejszej do najpóźniejszej daty we wszystkich zaimportowanych raportach. W raporcie konwersji dane dla całego konta mają pierwszeństwo przed sumą danych per kampania tego samego działania. Wykresy tylko z danych dziennych. Budżet zakresu = Σ budżet miesięczny × (dni zakresu w miesiącu / dni miesiąca).

## Silnik audytu

- **Reguła** (`Domain/Audit/Rule`): `id`, `name`, `description`, `category`, `evaluate(AuditContext): Finding[]`. Reguły są deterministyczne (bez AI), nie czytają bazy – dostają `AuditContext` zbudowany raz przez `Services/Audit/AuditContextBuilder` (ostatnie 30 zakończonych dni w strefie projektu, poprzednie 30 dni do porównań, tempo miesiąca, ustawienia z API, stan synchronizacji).
- **Problem** (`Finding`): ważność (`critical`/`warning`/`info`), tytuł, opis, dowody (pary etykieta–wartość), rekomendacja, encja, obszar wyniku i kara; `fingerprint()` = reguła + encja + wariant – status „Przejrzany/Pominięty” przechodzi na kolejne audyty.
- **Nowa reguła** (Prompt 3+): klasa w `Domain/Audit/Rules/` rozszerzająca `BaseRule` (stałe `ID`, `NAME`, `DESCRIPTION`, `CATEGORY`, `DIMENSION`), wpis w `RuleRegistry::RULES`, podbicie `RuleRegistry::VERSION`, test w `tests/Unit/AuditRulesTest.php`. Dane, których brakuje w kontekście, dodaje się w `AuditContextBuilder` (+ `AuditContext`).
- **Audit Score** (`AuditScore`): pule – konfiguracja 25, pomiar konwersji 30, ryzyka i efektywność 30, kompletność danych 15. Kara: krytyczny 10, ostrzeżenie 4, informacja 1 (część informacji 0), w puli obszaru, nie więcej niż pula. Pominięte problemy nie liczą się (wynik przeliczany po zmianie statusu). Brak danych kampanii lub pokrycie < 50% → wynik `NULL` z powodem. To ocena higieny konta, nie ROAS.
- **Uruchamianie**: ręcznie (`/audits/` dla wybranego okresu, `wp acorn pp:audit`, `pp:analyze`) i automatycznie po każdej zakończonej synchronizacji (ostatnie 30 dni). Progi zależne od długości okresu (np. CS005: 15 konwersji na 30 dni) są skalowane. Błąd jednej reguły jest logowany i zapisywany w `data_quality.rule_errors`, audyt dostaje status `partial`.

Reguły (wersja 1.1 – dodana DQ008):

| ID | Kategoria | Warunek | Ważność |
|---|---|---|---|
| CT001 | Pomiar konwersji | Konwersja główna z kategorią mikrokonwersji (wyświetlenie strony, zaangażowanie, klik wychodzący, dojazd, pobranie, koszyk, rozpoczęcie płatności) – na podstawie ustawień z API; kategoria „domyślna” + nazwa sugerująca mikrozdarzenie = informacja | ostrzeżenie / krytyczny przy ≥ 30% konwersji głównych |
| CT002 | Pomiar konwersji | Brak aktywnej głównej konwersji wartościowej (zakup, lead, telefon, rejestracja…) | krytyczny |
| CT003 | Pomiar konwersji | Konwersja typu lead liczona „Wszystkie” | ostrzeżenie |
| CS001 | Ustawienia kampanii | Partnerzy wyszukiwania włączeni | informacja |
| CS002 | Ustawienia kampanii | Sieć reklamowa w kampanii w sieci wyszukiwania | ostrzeżenie |
| CS003 | Ustawienia kampanii | Kierowanie „Obecność lub zainteresowanie” | ostrzeżenie |
| CS004 | Ustawienia kampanii | Brak lokalizacji (po synchronizacji lokalizacji) | ostrzeżenie |
| CS005 | Ustawienia kampanii | Inteligentne ustalanie stawek przy < 15 konwersjach w 30 dni | ostrzeżenie / krytyczny przy mikrokonwersjach |
| BP001 | Budżet | Przekroczony budżet miesięczny / prognoza (szacunek liniowy, tylko przy pełnych danych dziennych, min. 3 dni) ≥ 110% (≥ 130% krytyczny) / ≤ 70% po 7 dniach (informacja); brak budżetu (informacja, bez kary) | wg warunku |
| BP002 | Budżet | Suma budżetów dziennych × 30,4 ≥ 125% budżetu miesięcznego (≥ 200% ostrzeżenie) | informacja / ostrzeżenie |
| DQ001 | Jakość danych | Brak danych kampanii w okresie | krytyczny (wynik = brak) |
| DQ002 | Jakość danych | Brak połączenia z API | informacja |
| DQ003 | Jakość danych | Ostatnia udana synchronizacja > 48 h (> 7 dni krytyczny) lub jeszcze brak | ostrzeżenie / krytyczny |
| DQ004 | Jakość danych | Wymagana ponowna autoryzacja / ostatnia synchronizacja z błędem | krytyczny / ostrzeżenie |
| DQ005 | Jakość danych | Dane pokrywają część okresu (< 80% ostrzeżenie) | informacja / ostrzeżenie |
| DQ006 | Jakość danych | Nakładające się źródła (pominięte importy) | informacja, bez kary |
| DQ007 | Jakość danych | Waluta konta ≠ waluta projektu | ostrzeżenie |
| DQ008 | Jakość danych | Dane są, ale w okresie brak wydatków i wyświetleń (kampanie wyłączone) – komunikat z sugestią wcześniejszego okresu; DQ001 się wtedy nie uruchamia | informacja, bez kary (wynik = brak) |
| PF001 | Skuteczność | CPC +30% (≥ 100 kliknięć w obu okresach) | ostrzeżenie |
| PF002 | Skuteczność | CTR −25% (≥ 1000 wyświetleń w obu okresach) | ostrzeżenie |
| PF003 | Skuteczność | CPA +30% (≥ 10 konwersji w obu okresach; pomijane przy mikrokonwersjach) | ostrzeżenie |
| PF004 | Skuteczność | Kampania bez konwersji z wydatkami ≥ max(100 jedn. waluty, 10% budżetu) (≥ 25% budżetu krytyczny); brak danych o konwersjach ≠ 0 | ostrzeżenie / krytyczny |
| PF005 | Skuteczność | Hasła z ≥ 10 kliknięciami bez konwersji ≥ 10% wydatków na hasła (≥ 25% ostrzeżenie) | informacja / ostrzeżenie |

## Okres analizy

`AnalysisPeriod`: `last_30`, `last_90`, `last_180` (6 miesięcy), `last_365` (12 miesięcy) – zawsze kończą się wczoraj w strefie projektu – albo `custom` (maks. 731 dni, nie w przyszłość). Wybór jest zapamiętywany per użytkownik i projekt (user meta `pp_analysis_period`), a parametry `period/from/to` są w URL. Audyt, Budget Leaks, słowa kluczowe i rekomendacje liczą się dla tego samego okresu; porównania – z poprzednim okresem tej samej długości. Przebiegi różnych okresów są osobnymi wierszami (`audit_runs`, `analysis_runs` z datami i presetem) – nie nadpisują się.

Stan danych okresu (`AuditContextBuilder::activity`): `no_data` (brak danych), `no_activity` (dane są, ale bez wydatków i wyświetleń – np. kampanie wstrzymane; komunikat informacyjny zamiast błędu), `partial` (niepełne pokrycie), `complete`. Do tego pierwszy/ostatni dzień danych, dni z aktywnością, źródło i kompletność.

## Moduły analizy

Potok `AnalysisService::run(projekt, okres)`: audyt → `BudgetLeakService` → `KeywordOpportunityService` → `RecommendationService::refresh`. Błąd jednego modułu nie blokuje pozostałych. Uruchamiany ręcznie (POST `analysis/`, `wp acorn pp:analyze {projekt} --period=`), po każdej synchronizacji (ostatnie 30 dni) i po pobraniu danych z DataForSEO (bez audytu). Nigdy przy wyświetlaniu strony.

**Budget Leaks** (`LeakDetector`): reguły na danych okresu.

| Reguła | Opis |
|---|---|
| BL101 | Hasło bez konwersji: ≥ 10 kliknięć i koszt ≥ max(20 jedn. waluty, 0,5% wydatków, typowy koszt konwersji kampanii); pewność z P(0 konwersji) = (1−p)^n przy współczynniku kampanii (≥ 50 kliknięć) lub konta |
| BL102 | Hasło o niskiej intencji (darmowe, praca, nauka, zrób to sam, niepożądani odbiorcy z kontekstu) |
| BL103 | Powtarzający się temat (ten sam rdzeń w ≥ 3 hasłach) bez konwersji |
| BL104 | CPC hasła ≥ 2× CPC kampanii bez wyników |
| BL105 | Hasło bez związku z ofertą i słowami grupy (tylko przy opisanej ofercie; niska pewność) |
| BL201 | Słowo kluczowe bez konwersji (te same progi i pewność co BL101) |
| BL202 | Zbyt szerokie dopasowanie – duża część kosztu haseł słowa to zapytania mało związane z jego treścią |
| BL203 | Koszt konwersji słowa ≥ 2,5× średniej kampanii |
| BL204 | Zdublowane słowo (ten sam tekst i dopasowanie w kilku grupach) |
| BL301 | Kampania (≥ 15% wydatków lub ≥ 10% budżetu okresu, ≥ 30 kliknięć) bez konwersji celów biznesowych (gdy są dane per kampania) albo bez konwersji Google |
| BL302 | Udział w wydatkach ≥ 1,5× udziału w konwersjach celów biznesowych |
| BL303 | Wzrost kosztu konwersji względem poprzedniego okresu tej samej długości |
| BL304 | Kampania w sieci wyszukiwania z siecią reklamową/partnerami (bez oceny kosztu – brak podziału na sieci) |
| BL401 | Konwersje Google Ads to w ≥ 50% mikrokonwersje |
| BL402 | Brak działań oznaczonych jako cel biznesowy |
| BL403 | Cele biznesowe to głównie potencjalne kontakty (kliknięcia e-maila/telefonu) |
| BL404 | Podejrzanie wysoki współczynnik konwersji kampanii |
| BL405 | Mało danych do oceny (informacja o niskiej pewności wniosków) |

Pewność: wysoka gdy P < 5%, średnia < 20%, inaczej niska; przy głównie mikrokonwersjach obniżana. Priorytet (0–100) = wpływ (≤ 45, udział w wydatkach / nadpisanie dla pomiaru) + pewność (35/22/8) + istotność biznesowa (≤ 20) → wysoki ≥ 60, średni ≥ 35. Oszczędności (`SavingsEstimate`): miesięczny run-rate (koszt / dni × 30,4) tylko przy pewności ≥ średnia; per kampania maksimum z (suma unikalnych haseł, suma słów, suma kampanii), ograniczone miesięcznymi wydatkami kampanii – nakładające się problemy nie są sumowane. Status (Nowy/Przejrzany/Pominięty) przechodzi na kolejne przebiegi po odcisku.

**Konwersje** (`ConversionClass`, `ConversionBreakdown`): klasy lead / potencjalny kontakt (kliknięcie e-maila, telefonu) / potwierdzony lead / sprzedaż / mikrokonwersja / inna / nieokreślona + znacznik „cel biznesowy”. Propozycja z kategorii Google Ads, ręczna zmiana w `/conversions/` (bez ponownej synchronizacji, bez zmian w Google Ads). Metryki Google (`google`, `google_micro`) i biznesowe (`goals`, `leads`, `potential`, `confirmed`, `sales`…) są osobne; `confirmed` = NULL, jeśli żadne działanie nie jest potwierdzonym leadem. Konta i kampanii nie sumujemy; konwersji nie rozdzielamy proporcjonalnie na hasła i słowa (te mają tylko „Konwersje” Google).

**Słowa kluczowe** (`KeywordAnalyzer`): kandydaci = konwertujące hasła spoza słów kluczowych + pomysły z DataForSEO → deduplikacja po `Text::normalize` → pominięcie aktywnych słów i fraz objętych wykluczeniami (semantyka exact/phrase/broad) → intencja (zablokowane: niepożądani odbiorcy, praca, darmowe, nauka, DIY) → `OpportunityScore` (wagi w kodzie: oferta 35, intencja 25, CPC 15, wolumen 10, brak pokrycia 15; brak danych = 0 pkt i niższa pewność; CPC w USD nie jest porównywane z walutą konta) → sugerowana kampania/grupa (największe pokrycie rdzeni) → zapis ze źródłami. Wykluczenia: grupy tokenów niskiej intencji (informacyjne tylko z niską pewnością i przy istotnym koszcie) + pojedyncze kosztowne hasła (ścisłe), z flagą `already_excluded`.

**Rekomendacje** (`RecommendationGenerator` + `RecommendationService`): z problemów audytu (bez informacji i pominiętych), Budget Leaks (bez pominiętych i niskich/niepewnych), możliwości (score ≥ 50, grupowane per grupa reklam) i wykluczeń (grupowane per powód, bez już wykluczonych). Podobne problemy są łączone (hasła i słowa per kampania, duplikaty), ten sam temat z kilku źródeł (np. PF004 i BL301) – deduplikowany (wyższy priorytet + 5, źródła łączone). Odcisk = sha1(temat), niezależny od okresu: ponowna analiza aktualizuje treść, zachowuje status i notatkę. Brak w analizie tego samego okresu → „nieaktualna” (automatycznie przywracana, gdy problem wraca; ręczne oznaczenie zostaje). Statusy: Nowa, Do sprawdzenia, Zaakceptowana, Odrzucona, Wykonana (wyłącznie ręczne potwierdzenie). Każda zmiana → `recommendation_events`.

**DataForSEO**: `docs/DATAFORSEO.md`.

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
| REST Google Ads API przez WP HTTP API zamiast biblioteki `googleads/google-ads-php` | Biblioteka wymaga gRPC/protobuf (rozszerzenia PHP niedostępne na hostingu współdzielonym) i dużych zależności; potrzebujemy tylko odczytu `searchStream`. Atrapy testów działają przez `pre_http_request`. |
| Kolejka w tabeli `pp_sync_runs` + systemowy cron | Hosting współdzielony bez workerów; kroki są krótkie, wznawialne i chronione `GET_LOCK`, WP-Cron nie jest jedynym mechanizmem. |
| Zastępowanie okna metryk API zamiast upsertu wierszy | Google koryguje dane wstecz (konwersje z opóźnieniem); usunięcie i wstawienie okna w transakcji usuwa też wiersze, które zniknęły. |
| Rekomendacje jako jeden wiersz na odcisk (nie per przebieg) | Status, notatka i historia należą do użytkownika i muszą przetrwać ponowne analizy; wyniki przebiegów (leaks, frazy) są per przebieg, żeby okresy się nie nadpisywały. |
| DataForSEO przez kolejkę w `pp_dataforseo_tasks` i worker `pp:sync:work` | Płatne zapytanie tylko po zgodzie, kontrola limitu tuż przed wywołaniem, ponowienia bez blokowania żądania HTTP (timeout API 120 s). |
| Libsodium secretbox z kluczem w `wp-config.php` | Wbudowane w PHP ≥ 7.2, uwierzytelnione szyfrowanie, prosta rotacja; klucz poza bazą chroni tokeny przy wycieku zrzutu bazy. |
