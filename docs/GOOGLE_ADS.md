# Penny Pincher – integracja z Google Ads API

Stan: 12.10.2026 (Prompt 2). **Integracja jest zaimplementowana i przetestowana wyłącznie na atrapie API**
(`tests/Support/GoogleAdsMock.php`). Nie była uruchomiona z prawdziwym kontem Google – brak danych dostępowych
(projekt Google Cloud, klient OAuth). Pierwsze prawdziwe połączenie zweryfikuje to, czego atrapa nie sprawdzi:
dokładny kształt odpowiedzi, limity i uprawnienia konta.

## Co robi integracja

- Łączy **projekt** z jednym kontem reklamowym przez OAuth 2.0 (autoryzacja w imieniu użytkownika Google).
  Struktura bazy (`pp_google_ads_connections.project_id`) pozwala później na kilka kont w projekcie.
- Ma dostęp **tylko do odczytu**: wywołuje wyłącznie `customers:listAccessibleCustomers` i `googleAds:searchStream`
  (zapytania GAQL). Synchronizacja i analiza nie wysyłają żadnych operacji `mutate`. Zmiany konta są możliwe wyłącznie
  przez Change Center (przegląd, zatwierdzenie, świadome wdrożenie) i domyślnie są **wyłączone** (`PP_GOOGLE_ADS_WRITE_MODE`
  = `prepare_only`) – zob. `docs/CHANGE_CENTER.md` i `docs/GOOGLE_ADS_MANAGEMENT.md`.
- Synchronizuje: kampanie (ID, nazwa, status, typ, budżet, strategia stawek, sieci, typ kierowania na lokalizacje,
  lokalizacje, data i godzina rozpoczęcia/zakończenia – `start_date_time`/`end_date_time`), grupy reklam, słowa kluczowe (z wynikiem jakości), wyszukiwane hasła z metrykami, działania
  konwersji (kategoria, status, konwersja główna/dodatkowa, sposób zliczania, typ, źródło) i dzienne metryki:
  wyświetlenia, kliknięcia, koszt, konwersje, wszystkie konwersje, wartość konwersji, konwersje po obejrzeniu
  (CTR, CPC i CPA są liczone z sum).
- Od Prompt 3 dodatkowo: słowa wykluczające (kampania, grupa reklam, listy wspólne – `pp_negative_keywords`, pełna
  wymiana przy każdej synchronizacji) i konwersje per kampania i działanie (`segments.conversion_action` w zasobie
  `campaign`, zapis w `pp_conversion_metrics` z `campaign_scope` = id kampanii, zbiór pokrycia `conversions_by_campaign`).
  Oba kroki są **opcjonalne**: pola są sprawdzone ze schematem v25, ale zapytania nie były uruchomione na prawdziwym
  koncie – błąd zapytania (INVALID) jest zapisywany w dzienniku kroków jako pominięty i nie zatrzymuje synchronizacji.
  Dla istniejących połączeń dane historyczne pojawią się po przycisku **Pobierz historię ponownie** (POST
  `google-ads/backfill/`) albo stopniowo przez codzienną synchronizację (30 dni).
- Historia: 12 miesięcy przy pierwszej synchronizacji, potem codziennie ostatnie 30 dni (korekty konwersji
  z opóźnieniem nadpisują się).

## Wymagania po stronie Google (stan na 10.2026)

- **Developer token nie jest już potrzebny.** Od 09.09.2026 Google wycofał developer tokeny – poziom dostępu
  wynika z projektu Google Cloud. Nagłówek `developer-token` jest opcjonalny i ignorowany; aplikacja wysyła go
  tylko, gdy ustawisz `PP_GOOGLE_ADS_DEVELOPER_TOKEN` (zbędne).
- **Poziom dostępu projektu Cloud**: nowy projekt dostaje dostęp *Test* (tylko konta testowe). Do prawdziwych kont
  potrzebny jest co najmniej *Explorer* (do 2 880 operacji dziennie na kontach produkcyjnych – dla kilku kont
  i synchronizacji raz dziennie wystarczy; backfill jednego konta to ok. 60 zapytań), dla większej skali *Basic*
  lub *Standard*. Wniosek składa się w Google Cloud Console w ustawieniach Google Ads API.
- **Wersja API**: domyślnie `v25` (najnowsza; v22 wyłączono 07.10.2026). Zmienisz ją stałą
  `PP_GOOGLE_ADS_API_VERSION`, gdy Google ogłosi wycofanie v25 (wersje żyją ok. roku).

Źródła: [Cloud-managed access levels](https://developers.google.com/google-ads/api/docs/concepts/no-developer-token),
[Developer token – dokumentacja](https://developers.google.com/google-ads/api/docs/first-call/dev-token).
Przed konfiguracją sprawdź te strony – wymagania Google się zmieniają.

## Konfiguracja krok po kroku

### 1. Projekt Google Cloud

1. [console.cloud.google.com](https://console.cloud.google.com/) → nowy projekt (np. „Penny Pincher”).
2. *APIs & Services → Library* → **Google Ads API** → *Enable*.
3. W ustawieniach Google Ads API projektu sprawdź poziom dostępu; złóż wniosek o *Explorer* lub *Basic*
   (opis zastosowania: „audyt i raportowanie kont klientów, tylko odczyt”).

### 2. Ekran zgody OAuth

1. *APIs & Services → OAuth consent screen* (Google Auth Platform).
2. Typ: **External** (klienci z różnych domen) albo *Internal*, jeśli korzystają tylko konta z Twojej organizacji Workspace.
3. Nazwa aplikacji, e-mail wsparcia, domena `ohsofresh.top`, link do polityki prywatności.
4. Zakresy: `https://www.googleapis.com/auth/adwords`, `openid`, `email`.
5. Dopóki aplikacja ma status *Testing*, dodaj adresy Google osób, które będą łączyć konta (*Test users*).
   Zakres `adwords` jest „wrażliwy” – publikacja dla wszystkich wymaga weryfikacji aplikacji przez Google.

### 3. Klient OAuth (Web application)

*APIs & Services → Credentials → Create credentials → OAuth client ID → Web application*.

Authorized redirect URIs (dokładnie, ze slashem na końcu):

| Środowisko | Redirect URI |
|---|---|
| Produkcja | `https://pennypincher.ohsofresh.top/app/google-ads/callback/` |
| LocalWP | `https://pennypincher.local/app/google-ads/callback/` – Google wymaga HTTPS poza `localhost`, więc w Local włącz SSL (*Trust* certyfikatu) |

Dokładny adres dla danego środowiska pokazuje `wp acorn pp:google-ads:check` i strona *Google Ads* w aplikacji.

Zapisz **Client ID** i **Client secret** – nie wklejaj ich do czatu, ticketów ani repozytorium.

### 4. Konfiguracja serwera (`wp-config.php`, poza repozytorium)

```php
// Penny Pincher – Google Ads API
define('PP_GOOGLE_ADS_CLIENT_ID', '…apps.googleusercontent.com');
define('PP_GOOGLE_ADS_CLIENT_SECRET', '…');
// Klucz szyfrowania tokenów – wygeneruj: wp acorn pp:key:generate (inny dla każdego środowiska)
define('PP_ENCRYPTION_KEY', '…');
// Opcjonalnie:
// define('PP_GOOGLE_ADS_API_VERSION', 'v25');
// define('PP_GOOGLE_ADS_LOGIN_CUSTOMER_ID', '1234567890'); // domyślne konto menedżera (MCC)
// Change Center (domyślnie prepare_only – nic nie jest wysyłane do Google Ads):
// define('PP_GOOGLE_ADS_WRITE_MODE', 'prepare_only');   // read_only | prepare_only | write_enabled (nieznana wartość = read_only)
// define('PP_GOOGLE_ADS_API_VALIDATION', false);        // true = walidacja validateOnly (bez zapisu) przy zatwierdzaniu
```

Zamiast stałych można użyć zmiennych środowiskowych o tych samych nazwach. Sprawdzenie (bez wyświetlania sekretów
i bez łączenia z Google): `wp acorn pp:google-ads:check`.

**Klucz szyfrowania**: utrata `PP_ENCRYPTION_KEY` oznacza, że zapisane tokeny są nie do odczytania – trzeba
połączyć konta ponownie (dane w bazie zostają). Zmiana klucza: obecny przenieś do `PP_ENCRYPTION_KEY_PREVIOUS`,
nowy wpisz w `PP_ENCRYPTION_KEY`; tokeny odszyfrują się starym kluczem, a odświeżone zapiszą nowym.

### 5. Cron (automatyczna synchronizacja)

WP-Cron nie wystarcza (działa tylko przy ruchu na stronie). Na Hostingerze (hPanel → Zaawansowane → Cron Jobs):

```
*/10 * * * * cd /home/u679500043/domains/pennypincher.ohsofresh.top/public_html && wp acorn pp:sync:work --time=240 >> /home/u679500043/logs/pp-sync.log 2>&1
```

Zadanie co 10 minut planuje codzienną synchronizację (gdy od ostatniej minęło ≥ 20 h), dokańcza przerwane
i ponawia te z błędami chwilowymi. Szczegóły i pozostałe zadania: `docs/DEPLOYMENT.md`.

### 6. Połączenie w aplikacji

1. `/app/projects/{id}/google-ads/` → **Połącz z Google Ads** (właściciel lub administrator organizacji).
2. Zgoda w Google → powrót do aplikacji → **wybór konta reklamowego** (lista kont dostępnych dla tego konta
   Google, także kont klientów pod MCC).
3. Start synchronizacji historii (12 miesięcy) – część kroków wykonuje się od razu, resztę dokończy cron.
   Postęp, liczba rekordów i błędy są w *Historii synchronizacji*.

## Architektura

| Warstwa | Plik | Rola |
|---|---|---|
| Konfiguracja | `Infrastructure/GoogleAds/GoogleAdsConfig.php` | Stałe/zmienne środowiskowe, wersja API, redirect URI, lista braków |
| HTTP | `Infrastructure/GoogleAds/GoogleHttp.php` | `wp_remote_request` wyłącznie do `oauth2.googleapis.com`, `googleads.googleapis.com`, `accounts.google.com` przez HTTPS; klasyfikacja błędów |
| OAuth | `Infrastructure/GoogleAds/OAuthClient.php` | Adres zgody (PKCE S256, `access_type=offline`, `prompt=consent`), wymiana kodu, odświeżanie, unieważnienie |
| API | `Infrastructure/GoogleAds/GoogleAdsClient.php` | `listAccessibleCustomers`, `searchStream` (scalanie partii), nagłówek `login-customer-id` |
| Zapytania | `Infrastructure/GoogleAds/Sync/Gaql.php` | Zapytania GAQL dla każdego kroku; pola sprawdzane testem `GaqlSchemaTest` z listą pól v25 (`tests/Support/GaqlSchemaV25.php`), atrapa API odrzuca nieznane pola jak Google (`UNRECOGNIZED_FIELD`) |
| Zapis | `Infrastructure/GoogleAds/Sync/ApiSyncer.php` | Upsert encji po ID Google, zastępowanie okna metryk API w transakcji, pokrycie `pp_data_coverage` |
| Połączenie | `Services/GoogleAds/ConnectionService.php` | state, tokeny (szyfrowane), konta, wybór konta, odświeżanie, rozłączenie |
| Kolejka | `Services/GoogleAds/SyncService.php` + `Repositories/SyncRunRepository.php` | Plan kroków, wykonywanie z limitem czasu, blokada, ponowienia, harmonogram |
| UI | `Http/Controllers/GoogleAdsController.php`, `resources/views/app/pages/google-ads.blade.php` | Status, wybór konta, historia, ponowienie |

### Lista kont po autoryzacji

1. `GET /v25/customers:listAccessibleCustomers` – bez `login-customer-id` i bez developer tokenu; parsowane `resourceNames` (`customers/{id}`).
2. Dla każdego konta `searchStream` z `FROM customer` w kontekście tego konta (`login-customer-id` = to konto).
3. Dla konta menedżera `FROM customer_client` (wszystkie poziomy, bez menedżerów, tylko `ENABLED`); wybrane konto zapisuje MCC jako `login_customer_id`.
4. Błąd odczytu pojedynczego konta nie przerywa listy (konto trafia do „Pominięte konta”), ale gdy **żadnego** konta nie da się odczytać, pokazywany jest błąd Google (najistotniejszy: poziom dostępu projektu > uprawnienia > inne), a nie „brak kont”. Lista jest zapamiętywana 10 minut tylko, gdy nie jest pusta; ponowna autoryzacja ją czyści.

### Przepływ OAuth i bezpieczeństwo

- **state**: 256 bitów losowości, jednorazowy (usuwany przy pierwszym użyciu), ważny 10 minut, zapisany
  (jako hash w nazwie transientu) razem z ID użytkownika i projektu oraz `code_verifier` PKCE.
- Uprawnienia (`UpdateProject` – właściciel/administrator) są sprawdzane przy **rozpoczęciu i przy powrocie**;
  powrót innego użytkownika lub z nieznanym state nie wymienia kodu na tokeny.
- Tokeny (refresh i access) są szyfrowane libsodium (`SecretBox`, XSalsa20-Poly1305) kluczem spoza repozytorium
  i bazy; nie są zwracane przez repozytorium poza metodą `secrets()`, nie trafiają do logów ani komunikatów błędów.
- Sekrety są wysyłane wyłącznie do oficjalnych endpointów Google (lista dozwolonych hostów w `GoogleHttp`).
- Token odświeżania odrzucony przez Google (`invalid_grant`) → status połączenia „Wymaga ponownej autoryzacji”.
- Rozłączenie unieważnia token w Google, usuwa tokeny z bazy i zostawia zsynchronizowane dane.
- Projektu demonstracyjnego nie można połączyć z prawdziwym kontem.

### Synchronizacja

- **Przebieg** (`pp_sync_runs`): typ `backfill` (12 miesięcy) albo `incremental` (30 dni do wczoraj w strefie
  czasowej konta). Pierwsza synchronizacja połączenia jest zawsze backfillem.
- **Kroki**: najpierw struktura (`campaigns`, `locations`, `ad_groups`, `keywords`, `conversion_actions`,
  `negative_keywords`), potem metryki w oknach kalendarzowych miesięcy od najnowszego (`campaign_metrics`,
  `keyword_metrics`, `search_term_metrics`, `conversion_metrics`, `campaign_conversion_metrics`). Kroki z
  `ApiSyncer::OPTIONAL_STEPS` przy błędzie zapytania są pomijane (wpis `skipped` w dzienniku). Postęp = `step_index / steps_total`.
- **Wznawianie**: każdy krok jest zapisywany po zakończeniu; przerwany przebieg (limit czasu, restart, błąd)
  kontynuuje od pierwszego niewykonanego kroku. Przebieg „w toku” bez zmian przez 30 minut wraca do kolejki.
- **Idempotencja**: krok metryk usuwa wiersze `source='api'` z okna i wstawia nowe w jednej transakcji;
  encje są aktualizowane po identyfikatorach Google. Powtórzenie kroku nie tworzy duplikatów.
- **Błędy**: limit zapytań (429/`RESOURCE_EXHAUSTED`) i błędy chwilowe (5xx, sieć) → ponowienie z wykładniczym
  opóźnieniem 1, 2, 4… min (maks. 1 h) albo po czasie podanym przez Google, maks. 6 prób; brak autoryzacji →
  „Wymaga ponownej autoryzacji”; brak uprawnień i błędne zapytania → błąd widoczny w historii, z przyciskiem
  **Ponów** (wznawia od kroku z błędem).
- **Blokada**: `GET_LOCK` MySQL na połączenie – dwa procesy (cron i przycisk) nie przetwarzają tego samego konta naraz;
  otwarty przebieg blokuje dodanie kolejnego.
- **Waluta**: gdy waluta konta różni się od waluty projektu, synchronizacja zatrzymuje się z komunikatem.
- Po zakończonym przebiegu uruchamia się analiza projektu dla ostatnich 30 dni: audyt, Budget Leaks, słowa
  kluczowe, rekomendacje i Action Plan (`trigger_type = sync`), a następnie monitoring (alerty z danych w bazie,
  raz na przebieg – `docs/MONITORING.md`) i ewentualne powiadomienia e-mail (opt-in). Worker przy okazji odświeża
  kursy walut NBP (najwyżej co 12 h, tylko gdy są potrzebne). Żaden z tych kroków nie odpytuje płatnych API.
- Frazy z planu kampanii albo zaakceptowane w Keyword Opportunities, które pojawią się na koncie jako aktywne słowa
  kluczowe, dostają status „Wykorzystana”.
- **Campaign Planner nie korzysta z Google Ads API** – plany, budżety, wykluczenia i reklamy istnieją tylko
  w aplikacji (eksport CSV/HTML do ręcznego wdrożenia).

### Dane z API a importy CSV/XLSX

- Wiersze metryk mają `source` (`import` / `api`). Dla dni objętych pokryciem API (`pp_data_coverage`)
  `PeriodResolver` bierze **wyłącznie dane z API**; importowane dni i raporty okresowe nachodzące na te dni są
  pomijane (licznik `overriddenByApi`, komunikat na dashboardzie i w audycie). Wydatki nie są sumowane podwójnie.
- Importy dalej uzupełniają dni, których API nie obejmuje (np. historia starsza niż 12 miesięcy), i pozostają
  w historii importów.
- W oknie pokrytym przez API dzień bez wierszy oznacza brak aktywności (0), a nie brak danych.
- Kampanie/działania konwersji znane wcześniej z importu (po nazwie) dostają identyfikator Google zamiast duplikatu.

## Komendy

| Komenda | Działanie |
|---|---|
| `wp acorn pp:google-ads:check` | Stan konfiguracji (bez sekretów, bez łączenia z Google) i redirect URI |
| `wp acorn pp:google-ads:diagnose {projekt}` | Diagnostyka połączenia projektu: odświeżenie tokenu, `listAccessibleCustomers`, odczyt każdego konta i kont pod MCC – endpoint, status HTTP, kod błędu Google, komunikat, liczba kont (bez tokenów). To samo: przycisk „Diagnostyka połączenia” na stronie Google Ads |
| `wp acorn pp:key:generate` | Nowy `PP_ENCRYPTION_KEY` (wypisuje linię do `wp-config.php`) |
| `wp acorn pp:sync:work --time=240` | Zadanie crona: planowanie + przetwarzanie kolejki |
| `wp acorn pp:sync {projekt} [--backfill]` | Ręczna synchronizacja projektu |
| `wp acorn pp:audit {projekt}` | Audyt projektu |
| `wp acorn pp:analyze {projekt} --period=last_90` | Pełna analiza okresu (audyt, Budget Leaks, słowa kluczowe, rekomendacje, Action Plan; `--from= --to=` dla własnego zakresu) |
| `wp acorn pp:monitor {projekt}` | Monitoring projektu (alerty z danych w bazie, bez zapytań do API) |
| `wp acorn pp:fx:update [USD EUR …]` | Kursy walut z publicznego API NBP (tabela A) |

## Testy

`tests/Integration/GoogleAdsOAuthTest.php` i `GoogleAdsSyncTest.php` (atrapa `pre_http_request`): adres zgody
i PKCE, state (jednorazowy, użytkownik, wygaśnięcie), uprawnienia przy powrocie, szyfrowanie tokenów, brak refresh
tokenu, wybór konta (także MCC i `login-customer-id`), odświeżanie i `invalid_grant`, rozłączenie, klasyfikacja
błędów, lista dozwolonych hostów, backfill 12 miesięcy, idempotencja, pierwszeństwo API nad importem, dni zerowe,
ponowienia z opóźnieniem i wznowienie od kroku, limit prób, blokada równoległa, harmonogram, waluta, izolacja.

## Rozwiązywanie problemów

| Objaw | Przyczyna / rozwiązanie |
|---|---|
| `redirect_uri_mismatch` w Google | Redirect URI w kliencie OAuth musi być identyczny (HTTPS, ukośnik na końcu) – skopiuj z `pp:google-ads:check`. |
| „Brak uprawnień do Google Ads API” z kodem `CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION` | `listAccessibleCustomers` działa, ale odczyt konta odrzuca projekt Cloud z poziomem *Test*. Poziom Explorer/Basic musi być przyznany **temu samemu projektowi**, w którym jest klient OAuth (Cloud Console → Google Ads API → przegląd). Po zmianie: „Połącz ponownie”. |
| `USER_PERMISSION_DENIED` | Konto Google nie ma uprawnień do konta reklamowego albo dostęp idzie przez MCC – zaloguj się adresem z dostępem lub wybierz konto spod MCC. |
| „Brak kont reklamowych” | Google zwrócił pustą listę dla tego konta Google (`listAccessibleCustomers` = 0) – zalogowano się innym adresem niż ten z dostępem w Google Ads. |
| `access_denied` / brak konta na liście | Konto Google nie ma dostępu do konta reklamowego albo aplikacja w trybie *Testing* i użytkownik nie jest na liście *Test users*. |
| „Google nie zwrócił tokenu odświeżania” | Usuń dostęp aplikacji na myaccount.google.com/permissions i połącz ponownie (aplikacja wymusza `prompt=consent`). |
| `PERMISSION_DENIED` / dostęp tylko do kont testowych | Projekt Cloud ma poziom *Test* – złóż wniosek o *Explorer*/*Basic*. |
| `RESOURCE_EXHAUSTED` | Limit dzienny poziomu dostępu – synchronizacja ponowi się sama; przy wielu kontach rozważ wyższy poziom. |
| `INVALID_ARGUMENT` / `queryError: UNRECOGNIZED_FIELD` | Zapytanie GAQL zawiera pole usunięte z używanej wersji API (np. `campaign.start_date`/`end_date` usunięte w v23 – zastąpione `start_date_time`/`end_date_time`). Przebieg kończy się błędem bez ponawiania i bez utraty danych; po poprawce zapytania kliknij „Ponów” (wznowienie od kroku z błędem). Lista pól v25: `tests/Support/GaqlSchemaV25.php`. |
| `UNSUPPORTED_VERSION` / 404 na `v25` | Wersja wycofana – ustaw nowszą w `PP_GOOGLE_ADS_API_VERSION` i sprawdź zmiany w GAQL. |
| „Wymaga ponownej autoryzacji” | Cofnięto dostęp, zmieniono hasło lub token wygasł (aplikacja w trybie *Testing*: refresh token ważny 7 dni!) – połącz ponownie. |
| Synchronizacja stoi „w kolejce” | Nie działa cron `pp:sync:work` – sprawdź hPanel i `~/logs/pp-sync.log`. |
| Nie da się odszyfrować tokenów | Zmieniono `PP_ENCRYPTION_KEY` bez `PP_ENCRYPTION_KEY_PREVIOUS` – przywróć klucz albo połącz konta ponownie. |
