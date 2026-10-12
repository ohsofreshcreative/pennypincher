# Penny Pincher – postęp prac

Stan na 12.10.2026 (etap 5 – Prompt 5; wcześniej Prompt 1, 1.1, 2, 3, 4 i poprawki diagnostyki/GAQL).

## Prompt 5 – Google Ads Management, Change Center, One-Click Optimization

### Zrobione

- **Change Center** (`docs/CHANGE_CENTER.md`): model zestawów i operacji, 11 statusów zestawu i 9 operacji, podgląd przed/po (stan z API), ryzyko, wpływ na budżet (dzień, %, ×30,4), edycja i usuwanie operacji, przegląd → zatwierdzenie wersji (odcisk) → walidacja lokalna / `validateOnly` → świadome wdrożenie (klucz jednorazowy, potwierdzenie „WDRÓŻ” przy wysokim ryzyku), historia, próby API, uzgadnianie, ponowienie nieudanych, cofnięcie jako nowy szkic, efekty przed/po.
- **Silnik wykonawczy**: `GoogleAdsMutateClient` (jedyny zapis, własna kontrola trybu), `ExecutionService` (claim + `GET_LOCK`, ponowna kontrola uprawnień i konta, odcisk, dryf, grupy atomowe z tymczasowymi ID, `partial_failure`, wynik per operacja, timeout/5xx → „Wymaga uzgodnienia” bez ponawiania, limit → ponowienie z przerwą, przerwany worker → uzgodnienie, weryfikacja odczytem), `pp:changes:work` + krok w `pp:sync:work`. Tryby READ_ONLY / PREPARE_ONLY (domyślny) / WRITE_ENABLED.
- **Google Ads Management** (`docs/GOOGLE_ADS_MANAGEMENT.md`): kampanie Search (nowa – zawsze wstrzymana z własnym budżetem, nazwa, status, budżet z obsługą współdzielonego, sieci, strategia, lokalizacje, języki, wykluczenia), grupy, słowa (duplikaty, konflikty z wykluczeniami, limit 80 znaków / 10 słów, zmiana dopasowania jako atomowa zamiana), wykluczenia (blokowane słowa), RSA (nowa wersja). Inne typy kampanii – tylko odczyt.
- **Conversion Management** (`docs/CONVERSION_MANAGEMENT.md`): konfiguracja Google Ads obok klasyfikacji PP, odczyt działań i celów, edycja dozwolonych pól wg typu, tworzenie konwersji WEBPAGE (z ostrzeżeniem o tagu/GTM), cele konta i kampanii, Conversion Health Check (mikrokonwersja główna – przykład „30 sekund”, zliczanie, duplikaty, nieaktywne, cel niepasujący, cele kampanii, brak pomiaru leadów).
- **Przygotuj zmianę** w Rekomendacjach, Budget Leaks (BL101/102/201/304), Action Plan, Keyword Opportunities (zaznaczone wykluczenia), Monitoringu (przejście do kampanii) i **Przygotuj wdrożenie do Google Ads** w zatwierdzonym planie kampanii (kolejność zależności, kontrola duplikatów nazw i słów, powiązania lokalne ↔ Google chroniące przed ponownym utworzeniem). Elementy bez odpowiednika w API mają opis „wymaga działań poza Google Ads API”.
- **Uprawnienia**: `PrepareChanges` (członek), `ApproveChanges`, `ExecuteChanges` (administrator + flaga „wykonawca zmian”), `ManageWriteAccess` (właściciel).
- **Migracja** `2026_10_12_000006_google_ads_changes` – addytywna i warunkowa.
- **Poprawki w trakcie**: dane z API w Conversion Management miały niższy priorytet niż dane z importu (operator `+`), brak wykrywania konfliktu nowego słowa z istniejącym wykluczeniem, ostrzeżenie PHP w Health Check, pusty szkic po nieudanym przygotowaniu (teraz anulowany z powodem).

### Weryfikacja (Prompt 5)

- Testy: **263/263** na bazach `fresh` i `local` (216 wcześniejszych + 47 nowych), bez ostrzeżeń PHP w logu. Nowe: `Unit/ChangeDomainTest` (13), `Integration/ChangeCenterTest` (20), `Integration/GoogleAdsManagementTest` (14), rozszerzony `GaqlSchemaTest` (19 nowych zapytań stanu). Zapis testowany wyłącznie na atrapie konta `tests/Support/FakeGoogleAdsAccount` (GAQL + `googleAds:mutate`, tymczasowe ID, `partial_failure`, `validateOnly`, wstrzykiwane timeouty, 5xx, limity).
- Test HTTP (24/24): strony 200, CSRF 419, zatwierdzenie szkicu i wdrożenie odrzucone, zgoda na zapis niemożliwa bez trybu serwera, członek: szkic tak, zatwierdzenie / wdrożenie / zgoda / wykonawca – 403, zestaw innej organizacji – 404.
- Zrzuty 1440 / 834 / 390 px: Change Center (lista, szczegóły), Management (lista, kampania, grupa), Conversion Management, moduły z przyciskami – bez poziomego przewijania.
- Żadnej mutacji ani walidacji na prawdziwym koncie, żadnych zapytań DataForSEO.

### Znane ograniczenia (Prompt 5)

- Zapis nie był uruchomiony na prawdziwym (ani testowym) koncie Google Ads – zachowanie API potwierdzone tylko dokumentacją v25 i atrapą.
- Kontrola konfliktu słów z wykluczeniami korzysta z wykluczeń z synchronizacji (kampania, grupa); listy wspólne nie są uwzględniane.
- Reklamy i cele konwersji nie są synchronizowane – odczyt na żądanie.
- Efekty zmian liczone na danych dziennych z synchronizacji (kampanie objęte zmianą lub całe konto dla celów konta).

## Prompt 4 – Keyword Intelligence 2.0, Campaign Planner, Budget Planner, Action Plan, Monitoring, SaaS

### Zrobione

- **A. Keyword Intelligence 2.0**: edycja fraz źródłowych DataForSEO (usuń/dodaj/popraw, przywróć automatyczne, wczytaj ostatnio użyte, licznik, szacunek kosztu, maks. 20; ten sam limit, zgoda, cache i kolejka); nowy `SeedSelector` z odrzucaniem fraz (język, firmy/domeny, konkurencja, informacyjne, darmowe/praca/DIY, spoza oferty) i powodem; pola kontekstu „Własna marka” i „Marki konkurencji”; `Concepts` (synonimy, odmiany), `PhraseQuality`; `KeywordClusterer` z wymiennym `PhraseSimilarity`; `OpportunityScoreV2` (wersjonowany, osobna pewność 0–100, wyjaśnienie: mocne strony, ryzyka, braki, działanie); statusy fraz w `pp_keyword_decisions` (trwałe, z historią, akcje zbiorcze i dla klastra); waluty: `cpc_currency`, kursy NBP (`ExchangeRateService`, `pp:fx:update`, odświeżanie w workerze), „porównanie niedostępne” bez kursu. Nowa zakładka „Klastry”.
- **B. Campaign Planner** (`docs/CAMPAIGN_PLANNER.md`): plany (statusy, autor, kopia, historia), kampanie, grupy, słowa (dopasowania bez automatycznego przybliżonego, ostrzeżenie przy słabym pomiarze), dodawanie z Keyword Opportunities (frazy i klastry), wykluczenia na 3 poziomach z kontrolą konfliktów wg reguł Google, reklamy RSA (szablon z kontekstu, walidacja limitów), eksport CSV (BOM, `;`, ochrona przed formułami) i HTML.
- **C. Budget Planner** (`docs/BUDGET_PLANNER.md`): profil kampanii, podział wg scenariusza (Ostrożny / Zrównoważony / Eksperymentalny, konfigurowalne), prognozy w zakresach, przedział Wilsona dla konwersji tylko przy wiarygodnym pomiarze i historii, ostrzeżenia o przekroczeniu, ręczne korekty, informacja o budżecie dziennym Google.
- **D. Action Plan**: `ActionPlanBuilder` (grupowanie rekomendacji, kategorie, zależności – najpierw dane i pomiar, priorytet z ograniczeniem „wysokich” do 30%), `ActionPlanService` (trwałe statusy, notatki, historia, automatyczna blokada/odblokowanie tylko dla statusów ustawionych przez system), odświeżanie w potoku analizy.
- **E. Monitoring** (`docs/MONITORING.md`): 10 typów alertów z progami przeciw fałszywym alarmom, statusy (Nowy/Potwierdzony/Wyciszony/Rozwiązany), historia, deduplikacja, licznik w menu, filtry, e-mail opt-in (próg ważności, 1/dobę, bez ponownej wysyłki trwającego alertu), uruchamianie po udanej synchronizacji (idempotentne per przebieg) i `pp:monitor`.
- **F. SaaS**: nowe uprawnienia (`EditPlans`, `ApprovePlans`, `ManageBudget`, `RunPaidAnalysis`, `ManageAlerts`), izolacja projektu w każdym repozytorium, kontrolerze, formularzu, eksporcie i zadaniu w tle; DataForSEO: blokada `GET_LOCK` per organizacja przy zlecaniu, ponowna kontrola limitu pod blokadą i w workerze, rezerwacje innych projektów w limicie organizacji; paginacja (frazy, plany, alerty; klastry jednym zapytaniem z decyzjami pobieranymi zbiorczo), indeksy, drzewo planu w 5 zapytaniach (bez N+1).
- **UI**: menu Campaign Planner, Action Plan, Monitoring (licznik nowych alertów); usunięty blok „W kolejnych etapach”; wyjaśnienia dla osób bez doświadczenia w Google Ads przy wynikach, statusach i prognozach.
- **Migracja** `2026_10_12_000005_planning_monitoring` – wyłącznie addytywna i warunkowa; historyczne migracje bez zmian.
- **Poprawki w trakcie**: zła kolejność argumentów historii decyzji przy dodawaniu słów do planu (HTTP 500), podwójny ukośnik w adresach formularzy słów w planie, przyciski statusu planu jako akcje („Zatwierdź plan”) zamiast nazw statusów, historia planu obejmuje też zmiany struktury, deduplikacja powiadomień e-mail o trwających alertach.

### Weryfikacja (Prompt 4)

- Testy: **216/216** na pustej bazie (`fresh`) i na bazie z danymi z wcześniejszych etapów (`local`, migracja 5 na istniejących tabelach); `php tests/run.php unit` bez WordPressa: 109/109. Nowe: `Unit/KeywordIntelligenceTest` (13), `Unit/PlannerDomainTest` (10), `Unit/ActionPlanAndAlertsTest` (6), `Integration/CampaignPlannerTest` (7), `Integration/MonitoringTest` (5), `Integration/ActionPlanAndFxTest` (5), 2 nowe w `DataForSeoTest`, 1 nowy w `GoogleAdsSyncTest`. Żaden test nie został usunięty; podczas pełnego przebiegu w logu PHP nie ma ostrzeżeń.
- Test HTTP (Python, serwer PHP): wszystkie nowe strony 200 dla obu projektów (bez wyjątków w treści); planer: plan z klastra, dodanie fraz do planu, dodanie słów, zmiana dopasowania, przeniesienie, wykluczenie z konfliktem, usunięcie, eksport CSV (BOM), wszystkie zakładki, zablokowana edycja zatwierdzonego planu, 404 dla cudzego projektu (21/21); rola członka: tworzenie planu i wysyłka do przeglądu – tak, zatwierdzenie, budżet i DataForSEO – 403; regresja Prompt 2/3 (17/17).
- Zrzuty Chromium 1440 px, 834 px (tablet) i 390 px: Keyword Opportunities (3 zakładki), Campaign Planner (lista, struktura, budżet, wykluczenia, reklamy), Action Plan, Monitoring, Przegląd – bez poziomego przewijania strony.
- Atrapy: DataForSEO, NBP i e-mail (`pre_http_request`, `pre_wp_mail`) – zero zapytań sieciowych w testach, zero płatnych zapytań.

### Znane ograniczenia (Prompt 4)

- Klastry i dopasowanie do oferty są leksykalno-pojęciowe (słownik synonimów dla usług webowych, meblarskich i ogólnych) – frazy spoza słownika grupują się po wspólnych rdzeniach; model semantyczny można podłączyć przez `PhraseSimilarity`.
- Wykrywanie języka rozróżnia polski i angielski na podstawie znaczników – krótkie frazy bez znaczników mają język „nieustalony” (nie są odrzucane).
- Kursy NBP nie były pobrane z prawdziwego API w tym środowisku (brak dostępu do sieci) – parser przetestowany na atrapie zgodnej z dokumentacją NBP; pierwszy kurs pobierze worker albo `pp:fx:update`.
- Prognozy kliknięć wymagają CPC z historii konta albo przeliczonego CPC z DataForSEO; nowe frazy bez danych mają tylko budżet.
- Monitoring nie modeluje sezonowości rok do roku; e-mail korzysta z `wp_mail` (na produkcji sprawdź konfigurację poczty).
- Eksport CSV to zestawienie do ręcznego wdrożenia – nie jest formatem importu Google Ads Editor.
- Action Plan pokazuje do 300 działań (bez paginacji – w praktyce działań jest kilkanaście).


## Prompt 3 – Budget Leaks, Rekomendacje, Keyword Opportunities, DataForSEO, rozbudowa audytu

### Zrobione

- **Okres analizy** (A): presety 30/90 dni, 6/12 miesięcy i własny zakres (maks. 731 dni) dla audytu i wszystkich modułów; zapamiętywanie per użytkownik i projekt; historia audytów i analiz per okres (bez nadpisywania); stany danych `no_data` / `no_activity` / `partial` / `complete` z pierwszym i ostatnim dniem, dniami aktywności, źródłem i kompletnością; nowa reguła DQ008 (informacja „W wybranym okresie kampanie nie generowały wydatków ani wyświetleń…”) zamiast krytycznego błędu, gdy kampanie były wyłączone; progi reguł skalowane do długości okresu.
- **Kontekst biznesowy i konwersje** (B–C): pola kontekstu w ustawieniach (opcjonalne, z informacją o wpływie na trafność), klasyfikacja działań konwersji (`/conversions/`), metryki Google vs biznesowe, bez wymyślania potwierdzonych leadów. Synchronizacja: słowa wykluczające i konwersje per kampania i działanie (kroki opcjonalne), przycisk „Pobierz historię ponownie”.
- **Budget Leaks** (D): 18 reguł (hasła, słowa, kampanie, pomiar), pewność statystyczna, priorytet, szacunek oszczędności bez podwójnego liczenia, statusy przenoszone między przebiegami, filtry, historia analiz.
- **Rekomendacje** (E): generator z grupowaniem i deduplikacją między audytem, Budget Leaks, słowami i wykluczeniami; trwałe rekomendacje (odcisk), statusy, notatki, historia zdarzeń, nieaktualność automatyczna i ręczna, filtry i sortowanie, paginacja.
- **DataForSEO** (F): konfiguracja w `wp-config.php`, klient REST (biała lista hostów, Basic Auth, timeout 120 s, klasyfikacja kodów błędów), bezpłatny test połączenia i kodów lokalizacji, kolejka zadań z zgodą użytkownika, limitem projektu (wymagany) i organizacji (opcjonalny), ponowną kontrolą limitu przed zapytaniem, ponowieniami, pamięcią podręczną i kosztem z odpowiedzi; worker w `pp:sync:work`; `pp:dataforseo:check`.
- **Keyword Opportunities i wykluczenia** (G): kandydaci z haseł i DataForSEO, deduplikacja, pominięcie istniejących słów i wykluczeń, intencja, Opportunity Score 0–100 z rozbiciem i pewnością, sugerowana kampania/grupa; propozycje wykluczeń z poziomem, dopasowaniem i informacją „już wykluczona”.
- **UI i dashboard** (H): menu Budget Leaks, Rekomendacje, Keyword Opportunities, Konwersje; wspólny wybór okresu; stany puste/ładowania/błędów; panel „Optymalizacja” na Przeglądzie (największe problemy, nowe rekomendacje, możliwości, wykluczenia, szacowany potencjał) – bez liczb, gdy analiza nie była uruchomiona.
- **Potok** `AnalysisService` (ręcznie, `pp:analyze`, po synchronizacji, po DataForSEO).

### Weryfikacja (Prompt 3)

- Testy: 167/167 na pustej bazie (`fresh`) i na bazie z danymi z wcześniejszych etapów (`local`, migracja 4 na istniejących tabelach); nowe: `Unit/AnalysisModulesTest` (18), `Integration/AnalysisModulesTest` (7), `Integration/DataForSeoTest` (6), 2 nowe testy w `GoogleAdsSyncTest`; zaktualizowane oczekiwania w `AuditTest`/`AuditRulesTest` (23 reguły, wersja reguł). Żaden test nie został usunięty.
- Test HTTP (curl, serwer PHP): wszystkie nowe strony 200 (oba projekty, różne okresy), uruchomienie analizy, zmiana statusu/notatki/nieaktualności rekomendacji, błędny status, limit DataForSEO, zlecenie bez konfiguracji (komunikat), brak nonce → 419.
- Zrzuty Chromium 1440 px i 390 px (Przegląd, Budget Leaks, Rekomendacje, Keyword Opportunities, DataForSEO) – bez poziomego przewijania.
- **Nie zweryfikowano z prawdziwymi usługami**: DataForSEO (brak danych dostępowych – tylko atrapa, zero płatnych zapytań) oraz nowe zapytania GAQL (wykluczenia, konwersje per kampania) na prawdziwym koncie – dlatego są krokami opcjonalnymi.

### Znane ograniczenia (Prompt 3)

- Konwersje haseł i słów kluczowych to „Konwersje” Google Ads (Google nie raportuje ich per działanie w tych widokach) – Budget Leaks dla haseł nie rozróżnia mikrokonwersji; zamiast tego obniża pewność, gdy konwersje główne to głównie mikrokonwersje.
- Potwierdzone leady: klasa istnieje, ale bez importu z CRM liczba jest „brak danych”.
- Dopasowanie do oferty to porównanie rdzeni słów (bez synonimów i AI) – reguła BL105 ma niską pewność.
- CPC z DataForSEO jest w USD i nie jest przeliczane na walutę konta.
- Analiza po synchronizacji liczy tylko ostatnie 30 dni; inne okresy – ręcznie lub `pp:analyze`.
- Dla połączeń sprzed Prompt 3 konwersje per kampania i wykluczenia wymagają „Pobierz historię ponownie” (albo pojawią się dla 30 dni po najbliższej synchronizacji).

## Prompt 2 – Google Ads API, synchronizacja, audyt, produkcja

### Definition of Done

| # | Punkt | Stan |
|---|---|---|
| 1 | Aplikacja wdrożona na produkcję | **Nie** – przygotowane (`scripts/deploy.sh`, `docs/DEPLOYMENT.md`), wstrzymane do wymiany klucza SSH i Twojej zgody |
| 2 | Produkcja zabezpieczona | Częściowo – mechanizmy w kodzie gotowe i przetestowane lokalnie (Hardening, `.htaccess`, HSTS, logowanie, izolacja); ustawienia serwera (wp-config, SSL, cron) do wykonania przy wdrożeniu |
| 3 | Problem klucza SSH rozwiązany | **Nie** – zabezpieczone wdrożenie (blokada odcisku, `.gitignore`, klucz nie trafia do paczki); rotacja i usunięcie z repozytorium wymagają Twoich kroków (`docs/SECURITY.md`) |
| 4 | OAuth Google Ads działa | Zaimplementowane, **zweryfikowane tylko na atrapie** – brak projektu Google Cloud i klienta OAuth |
| 5 | Projekt łączy się z kontem reklamowym | Jak wyżej (wybór konta, MCC, statusy – testy na atrapie) |
| 6 | Synchronizacja pobiera dane | Jak wyżej (backfill 12 mies., przyrostowa, ponowienia, wznowienie – testy na atrapie) |
| 7 | Automatyczna synchronizacja | Kod i komendy gotowe (`pp:sync:work`); cron na Hostingerze – do ustawienia przy wdrożeniu |
| 8 | Silnik audytu działa | **Tak** – 22 reguły, wynik, statusy, historia; działa na danych z importów, reguły ustawień kampanii/konwersji czekają na dane z API |
| 9 | Audit Score | **Tak** – przejrzyste kary, brak wyniku przy braku danych |
| 10 | UI i dashboard | **Tak** – strony Google Ads i Audyt, sekcja stanu konta na Przeglądzie (desktop/mobile sprawdzone) |
| 11 | Testy i dokumentacja | **Tak** – 121 testów (w tym 25 z atrapą API), dokumentacja `GOOGLE_ADS.md`, `DEPLOYMENT.md` i aktualizacje |

### Zrobione

- Połączenie projektu z Google Ads (OAuth 2.0 + PKCE, jednorazowy state powiązany z użytkownikiem i projektem, uprawnienia przy starcie i powrocie), wybór konta (także pod MCC), odświeżanie tokenów, „Wymaga ponownej autoryzacji”, rozłączenie z unieważnieniem tokenu. Tokeny szyfrowane libsodium kluczem spoza repozytorium, rotacja klucza.
- Synchronizacja przez REST API v25 (tylko odczyt): kampanie z ustawieniami (sieci, lokalizacje, kierowanie, budżet, strategia), grupy reklam, słowa kluczowe, wyszukiwane hasła, działania konwersji z ustawieniami, metryki dzienne. Kolejka `pp_sync_runs`: kroki wznawialne, idempotentne okna, ponowienia z wykładniczym opóźnieniem, blokada równoległych przebiegów, harmonogram dzienny, komendy CLI, strona z postępem, rekordami, błędami i „Ponów”.
- Pierwszeństwo danych z API nad importami (bez podwójnego liczenia, komunikaty o pominiętych importach), pokrycie okien API (dzień bez wierszy = 0).
- Silnik audytu z rozszerzalnym interfejsem reguł, 22 reguły (pomiar konwersji, ustawienia, budżet, jakość danych, skuteczność z progami próby), Audit Score 0–100 z rozbiciem na obszary, statusy Nowy/Przejrzany/Pominięty przenoszone między audytami, historia, audyt po każdej synchronizacji.
- Dashboard: Google Ads (status, ostatnia synchronizacja), Audit Score i problemy krytyczne, tempo budżetu w miesiącu z prognozą (szacunek), ostrzeżenia jakości danych, najważniejsze wyniki audytu.
- Produkcja: `Hardening` (rejestracja, XML-RPC 403, `/wp/v2/users`, `?author=`), HSTS, `.htaccess` motywu, blokada PHP w `uploads`, `scripts/deploy.sh` (kontrola tylko do odczytu, kopie, rsync, migracje, weryfikacja HTTP) i `scripts/rollback.sh`.
- Poprawka błędu: `reset()` na właściwości readonly `ValidationFailed::$errors` dawał błąd 500 w `OrganizationsController` (np. przy błędzie dodawania członka).

### Weryfikacja (Prompt 2)

- Testy: 121/121 na pustej bazie i na bazie z danymi z etapu 1 (migracja 3 na istniejących tabelach); w tym `GoogleAdsOAuthTest` (13), `GoogleAdsSyncTest` (12), `AuditTest` (7), `AuditRulesTest` (15), `DeploymentConfigTest` (5).
- Test HTTP (Python, serwer PHP): strony Przegląd/Google Ads/Audyt, uruchomienie audytu, zmiana statusu, odrzucenie złego statusu i cudzego problemu, połączenie bez konfiguracji, callback ze złym/nieznanym state, brak nonce → 419, anonimowy callback → logowanie; XML-RPC 403, `/wp-json/wp/v2/users` 401, `?author=1` → strona główna.
- Zrzuty w Chromium (1440 px i 390 px) – bez poziomego przewijania.
- Komendy `pp:google-ads:check`, `pp:sync:work`, `pp:sync`, `pp:audit` przez kernel Acorn.
- `scripts/deploy.sh --check` – sprawdzony etap budowy paczki (composer `--no-dev`, `yarn build`); połączenia z serwerem nie nawiązywano.

### Niezweryfikowane

- Prawdziwe Google Ads API (OAuth, konta, GAQL, limity) – brak danych dostępowych. Pierwsze połączenie może ujawnić różnice w polach odpowiedzi.
- LocalWP (`pennypincher.local`) i produkcja – testy w kopii środowiska.
- Skrypt wdrożenia na serwerze (część po `--check` i sama kontrola serwera).

### Poprawka 12.10.2026 – „brak kont reklamowych” po autoryzacji

- Przyczyna w kodzie: błędy odczytu szczegółów konta (np. 403 `CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION`, `USER_PERMISSION_DENIED`) były pomijane, więc pusta lista wyglądała jak „konto Google nie ma dostępu do żadnego konta”; pusta lista była też zapamiętywana na 10 minut i przeżywała ponowną autoryzację.
- Teraz: błąd Google z kodem i request ID zamiast „brak kont”, pominięte konta widoczne, brak cache dla pustej listy, czyszczenie cache przy autoryzacji, konta MCC na wszystkich poziomach, diagnostyka (`pp:google-ads:diagnose`, przycisk w UI), osobne komunikaty: brak kont / brak uprawnień API / błąd autoryzacji / błąd Google Ads API.
- Testy: `GoogleAdsAccountsTest` (7), łącznie 128/128.

### Poprawka 12.10.2026 – `UNRECOGNIZED_FIELD` przy pierwszej synchronizacji

- Przyczyna: zapytanie kampanii (`Gaql::campaigns()`) zawierało `campaign.start_date` i `campaign.end_date`, usunięte z API w v23 (zastąpione `campaign.start_date_time` / `campaign.end_date_time`, format „yyyy-MM-dd HH:mm:ss”). Atrapa testowa przyjmowała każde pole, więc testy tego nie wykryły.
- Poprawka: oficjalne pola `start_date_time`/`end_date_time` (zapisywane w `campaigns.settings` bez przekształceń); pozostałe pola wszystkich zapytań sprawdzone z v25 (pliki .proto v25 i różnice v23/v24/v25) – bez innych zmian.
- Regresja: `GaqlSchemaTest` (lista pól v25 i pól usuniętych), atrapa API odrzuca nieznane pola jak Google, test ponowienia po błędzie bez utraty danych. Testy 133/133.

### Kolejne kroki

1. Wymiana klucza SSH (`docs/SECURITY.md`, kroki 1–5).
2. Projekt Google Cloud, ekran zgody, klient OAuth, wniosek o poziom Explorer/Basic (`docs/GOOGLE_ADS.md`).
3. `wp-config.php` produkcji, `scripts/deploy.sh --check`, po Twojej zgodzie `scripts/deploy.sh`, cron.
4. Połączenie pierwszego konta (OhSoFresh), kontrola danych z API względem interfejsu Google Ads.

---

## Etap 1 (Prompt 1 i 1.1)

## Prompt 1.1 – poprawki

- **Import „Konwersje.csv” z Edytora raportów** – przyczyna: (1) brak aliasu „Działania powodujące konwersję” (liczba mnoga), (2) nieznana kolumna „Konw. po obejrzeniu”, (3) wykrywanie wiersza nagłówków wymagało ≥ 2 rozpoznanych kolumn, więc 3-kolumnowy raport z jedną znaną kolumną był odrzucany. Naprawione: nowa heurystyka wiersza nagłówków (≥ 2 komórki i ≥ 2 znane nagłówki albo nagłówek identyfikujący typ raportu), porównywanie nagłówków bez polskich znaków i interpunkcji, rozszerzone aliasy PL/EN dla wszystkich czterech typów raportów, daty i statusy bez polskich znaków.
- **Konwersje po obejrzeniu** – nowe pole i kolumna `view_through_conversions` (migracja `…000002`), nigdy nie sumowane z konwersjami.
- **Dashboard** – sekcja „Działania powodujące konwersję”; „Wszystkie dane” obejmuje wszystkie typy raportów; wykorzystanie budżetu przy niepełnych danych liczone względem budżetu dla dni z danymi.
- **Import** – podsumowanie pokazuje zakres z nagłówka eksportu i ostrzega o rozbieżnościach; podgląd wymienia kolumny, które nie zostaną zaimportowane.
- **Bezpieczeństwo** – limit prób logowania (okno 15 min, bez blokady na stałe), strona 500 bez szczegółów, odrzucanie XML z DOCTYPE w XLSX, projekt demo tylko dla osób mogących zakładać organizacje. Pełny opis i plan dla klucza SSH: `docs/SECURITY.md`.
- **Dokumentacja** – AGENTS.md bez odniesień do h2otwock (ścieżki, liczba bloków, WP-CLI), nowe `docs/SECURITY.md`.


## Zrobione

- Moduł aplikacji w motywie (`app/PennyPincher`, `routes/app.php`), routing Acorn pod `/app/` z obsługą 403/404/419.
- Logowanie własnym ekranem (konta WordPress), wylogowanie, ochrona przed open redirect, CSRF (nonce WP), nagłówki bezpieczeństwa.
- Organizacje, członkostwo i role (właściciel/administrator/członek), zarządzanie członkami, projekty z pełnymi ustawieniami, przełącznik projektu.
- 15 tabel `pp_*` z kluczami obcymi, wersjonowane migracje (automatycznie przy pierwszym wejściu na `/app/` lub `wp acorn pp:migrate`).
- Import CSV (UTF-8/UTF-16/Windows-1250, dowolny separator) i XLSX: rozpoznanie wiersza nagłówków, języka, zakresu dat i typu raportu, podgląd, ręczne mapowanie, walidacja całości bez częściowego zapisu, idempotentny ponowny import, ostrzeżenie o duplikacie, zgodność waluty.
- Typy raportów: Skuteczność kampanii, Wyszukiwane hasła, Słowa kluczowe, Działania powodujące konwersję.
- Dashboard (KPI, budżet proporcjonalny do zakresu, wykresy dzienne SVG, kampanie, hasła, importy, informacja o źródle i pokryciu dat), Kampanie (filtry, sortowanie, paginacja, szczegóły ze źródłami danych), Wyszukiwane hasła (wyszukiwarka, filtry kampanii i konwersji, sortowanie, paginacja), Ustawienia projektu.
- Projekt demonstracyjny w osobnej organizacji „Demo” (dane przechodzą przez prawdziwy importer).
- Komendy: `wp acorn pp:migrate`, `pp:organization:create`, `pp:import`, `pp:imports:prune`.
- 71 testów automatycznych (`tests/run.php`) + pliki przykładowe w `tests/fixtures` (w tym dokładna struktura `Konwersje.csv` i jej warianty: UTF-16/tab, XLSX, średniki i puste wiersze, wersja angielska, nagłówki bez polskich znaków).
- Dokumentacja: `AGENTS.md` (= `CLAUDE.md`), `docs/PRODUCT.md`, `docs/ARCHITECTURE.md`, ten plik.

## Weryfikacja

Wykonane w kopii środowiska (WordPress 7.1.3 i wtyczki z LocalWP, motyw z `vendor/`, PHP 8.3, MariaDB 10.11):

- `php -l` dla wszystkich plików aplikacji – bez błędów,
- `yarn build` – przechodzi (ostrzeżenie o `::first-child` w istniejącym CSS strony, niezwiązane),
- testy: 71/71 na bazie po aktualizacji (migracja 2) i na pustej bazie (jednostkowe: parsowanie liczb i dat, kwoty, metryki, budżet, rozstrzyganie okresów, czytniki CSV/XLSX, normalizacja; integracyjne: izolacja organizacji, role, importy wszystkich typów, idempotencja, błędne pliki, waluta, dashboard, filtry i paginacja, projekt demo, migracje),
- migracje na pustej bazie: 15 tabel, 30 kluczy obcych, testy bez pozostawiania danych,
- test HTTP end-to-end (70/70, Prompt 1.1): m.in. import `Konwersje.csv` przez interfejs, sekcja konwersji na dashboardzie, limit logowania (HTTP 429), brak tworzenia demo przez członka; wcześniejszy zakres: logowanie, 302/403/404/419, skróty URL, tworzenie organizacji i projektu, ustawienia, upload i zatwierdzenie CSV i XLSX, ponowny import, błędne pliki, anulowanie, zmiana typu raportu, izolacja i role drugiego użytkownika, wylogowanie,
- przegląd w przeglądarce (Chromium, 1440 px i 390 px): dashboard, kampanie, hasła, importy, podgląd importu, ustawienia, projekty – bez poziomego przewijania strony,
- komendy `pp:*` uruchomione przez kernel konsoli Acorn.

## Niezweryfikowane / znane problemy

- **LocalWP nie został przetestowany**: przeglądarka w aplikacji Claude dwukrotnie nie załadowała `http://pennypincher.local/app/` (12.10.2026, Prompt 1 i 1.1) – prawdopodobnie strona w Local była zatrzymana. Wszystkie testy wykonano w wiernej kopii środowiska. Do wykonania ręcznie: lista „Testy manualne w LocalWP” niżej.
- Testy działały na MariaDB 10.11; LocalWP zwykle używa MySQL 8 – `VALUES()` w `ON DUPLICATE KEY UPDATE` generuje tam tylko ostrzeżenie o przestarzałej składni.
- Komendy uruchamiane były przez kernel Acorn, nie przez binarkę `wp` (brak WP-CLI w środowisku testowym).
- Czytnik XLSX jest własny (Packagist był niedostępny); obsługuje pierwszy arkusz pliku.
- Import działa synchronicznie (limit 100 000 wierszy i rozmiaru pliku); kolejka będzie potrzebna przy synchronizacji z API.
- Istniejące, niezwiązane problemy motywu: ostrzeżenie PHP w JSON-LD w `layouts/app.blade.php` (`@context` interpretowane przez Blade), pozostałe z listy „Znane niespójności” w AGENTS.md; sekcja „Repository map” w AGENTS.md nadal opisuje motyw h2otwock.
- W repozytorium (także na `origin/main`) jest śledzony prywatny klucz SSH `key` – plan wymiany i usunięcia: `docs/SECURITY.md`. Nie wykonano automatycznie.
- `PRZENOSINY.md` to Twoja instrukcja przenoszenia warsztatu Figma → blok z projektu h2otwock; nie dotyczy aplikacji, zostawiona bez zmian.
- Raporty z segmentem „Tydzień”/„Miesiąc” (zamiast „Dzień”) są zapisywane jako suma za cały zakres raportu – poprawne sumy, ale bez podziału w czasie.

## Testy manualne w LocalWP

1. Uruchom stronę w Local, wejdź na `http://pennypincher.local/app/` – przekierowanie do `/app/login/`, logowanie kontem administratora.
2. Pierwsze wejście uruchamia migracje: w bazie powinny być tabele `wp_pp_*` (15) i opcja `pp_schema_version = 2026_10_12_000002_view_through_conversions`.
3. Projekty: utwórz organizację i projekt, odśwież stronę projektu (F5) – brak 404.
4. Ustawienia: zmień budżet, zapisz, sprawdź komunikat i wartość na dashboardzie.
5. Importy: prześlij `Konwersje.csv` – podgląd ma rozpoznać „Działania powodujące konwersję”, „Konwersje”, „Konwersje po obejrzeniu” i zakres 01.01.2026 – 29.06.2026; zatwierdź; sekcja konwersji na Przeglądzie (zakres „Wszystkie dane”).
6. Prześlij ponownie ten sam plik – ostrzeżenie o duplikacie, po zatwierdzeniu sumy bez zmian.
7. Zaimportuj raport kampanii (CSV i XLSX, najlepiej z „Dzień”) oraz wyszukiwanych haseł; sprawdź Przegląd, Kampanie (filtry, sortowanie, szczegóły) i Wyszukiwane hasła.
8. Zakresy dat: „Ten miesiąc”, „Własny zakres”, „Wszystkie dane” – komunikaty o pokryciu i braku danych zamiast zer.
9. Bezpieczeństwo: 6 razy złe hasło → komunikat „Zbyt wiele nieudanych prób…”; drugi użytkownik bez organizacji → 403; członek innej organizacji nie otworzy cudzego `/app/projects/{id}/` (404).
10. Strona publiczna (`/`) działa jak wcześniej.

## Ręczna konfiguracja

1. Uruchom stronę w LocalWP; ustaw bezpośrednie odnośniki inne niż „Prosty”.
2. Wejdź na `/app/` jako administrator WordPressa – migracje utworzą tabele (albo `wp acorn pp:migrate`).
3. Utwórz organizację „OhSoFresh Creative” i projekt z ustawieniami z `docs/PRODUCT.md`.
4. Zaimportuj raporty z Google Ads (Kampanie z segmentem „Dzień”, Wyszukiwane hasła).
5. Inne osoby: konto WordPress + dodanie w „Członkowie” organizacji.
6. Produkcja: systemowy cron `wp acorn pp:imports:prune` raz dziennie; opcjonalnie filtr `pennypincher/import/max_bytes`.

## Następne kroki (Prompt 2)

- Wykonać testy manualne w LocalWP (wyżej) i plan wymiany klucza SSH (`docs/SECURITY.md`).

- Silnik audytowy na zaimportowanych danych (reguły, priorytety, wyniki per projekt i zakres dat).
- Wykorzystanie opisu biznesu, klienta docelowego i wartości projektów z ustawień w regułach.
- Rozszerzenie widoku słów kluczowych i konwersji (dane są już importowane do bazy).
