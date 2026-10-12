# DataForSEO – integracja

DataForSEO dostarcza pomysły słów kluczowych z wolumenem, CPC i konkurencją (dane Google Ads Keyword Planner) dla modułu **Keyword Opportunities**. Integracja jest opcjonalna: bez niej analiza korzysta wyłącznie z danych Google Ads (wyszukiwane hasła, słowa kluczowe, wykluczenia).

**Zasady bezpieczeństwa i kosztów**

- Płatne zapytanie powstaje wyłącznie po ręcznym potwierdzeniu przez użytkownika z rolą administratora organizacji (uprawnienie `RunPaidAnalysis`: checkbox zgody + zaakceptowany szacunek kosztu). Członek widzi plan i stan limitów, ale nie zleca zapytań ani nie zmienia fraz źródłowych i limitu.
- Bez ustawionego miesięcznego limitu projektu płatne zapytania są zablokowane.
- Zapytanie wykonuje worker `pp:sync:work` (systemowy cron) – nigdy wyświetlenie strony.
- Identyczne zapytanie w okresie ważności pamięci podręcznej (domyślnie 30 dni) nie jest ponownie płatne.
- Dane logowania są tylko w `wp-config.php` / zmiennych środowiskowych; nie trafiają do repozytorium, bazy, logów ani widoków.
- Testy automatyczne używają atrapy HTTP – nie wykonują żadnych zapytań do DataForSEO.

## 1. Dane dostępowe

1. Załóż konto na <https://app.dataforseo.com/> i doładuj saldo (rozliczenie pay-as-you-go, USD).
2. W panelu: **API Access** → skopiuj **API login** (e-mail) i **API password**. Hasło API jest inne niż hasło do panelu.
3. Dodaj do `wp-config.php` (lokalnie i na produkcji – osobno, nigdy w Git):

```php
define('PP_DATAFORSEO_LOGIN', 'twoj-login@example.com');
define('PP_DATAFORSEO_PASSWORD', 'haslo-api');
// opcjonalnie:
// define('PP_DATAFORSEO_SANDBOX', true);                    // sandbox: darmowe, przykładowe (nieprawdziwe) dane
// define('PP_DATAFORSEO_ORGANIZATION_MONTHLY_LIMIT', 20);   // limit USD / miesiąc na każdą organizację
// define('PP_DATAFORSEO_CACHE_DAYS', 30);                   // ważność zapisanych odpowiedzi (1–365 dni)
```

Zamiast stałych można użyć zmiennych środowiskowych o tych samych nazwach.

## 2. Konfiguracja w aplikacji

- **Limit projektu**: Keyword Opportunities → zakładka **DataForSEO** → „Limit (USD / mies.)”. Puste = płatne zapytania zablokowane; 0 = zablokowane.
- **Limit organizacji** (opcjonalny): stała `PP_DATAFORSEO_ORGANIZATION_MONTHLY_LIMIT`.
- **Lokalizacja i język**: Ustawienia projektu → Kontekst biznesowy (domyślnie Polska `2616`, `pl`).
- **Frazy źródłowe** (sekcja „Frazy źródłowe” na zakładce DataForSEO):
  - propozycje automatyczne (`SeedSelector`): usługi priorytetowe (z wariantem „dla firm”, gdy grupą docelową są firmy), produkty/usługi, konwertujące hasła i słowa, słowa z ruchem – na podstawie kontekstu biznesowego (oferta, grupa docelowa, niepożądani odbiorcy, marki) i tego, co konto już reklamuje;
  - odrzucane automatycznie, z podanym powodem (lista „Odrzucone propozycje”): inny język niż projekt, nazwy firm i domen (np. „hk bundle e commerce limited”), marki konkurencji z kontekstu, zapytania informacyjne, darmowe / praca / nauka / zrób to sam / niepożądani odbiorcy, brak związku z ofertą (np. „online seller”, „marketing plan for a new product”). Własna marka i marki spoza listy konkurencji nie są blokowane;
  - edycja: usuń, dodaj (jedna fraza w linii), popraw, „Przywróć automatyczne”, „Wczytaj ostatnio użyty zestaw”; zapisany zestaw jest pamiętany w projekcie (`projects.keyword_seeds`), ostatnio użyty – przy każdym zleceniu;
  - frazy dodane ręcznie nie są odrzucane – dostają tylko ostrzeżenie o możliwej niskiej trafności;
  - maks. 20 fraz (limit zadania), maks. 80 znaków; liczba fraz i szacunek kosztu są widoczne przed zgodą; zmiana fraz zmienia klucz pamięci podręcznej (nowe zapytanie = nowy koszt).

Wykorzystanie liczone jest w bieżącym miesiącu kalendarzowym (UTC): koszt zakończonych zadań + szacunek zadań w kolejce (rezerwacja) – osobno dla projektu i dla całej organizacji (rezerwacje innych projektów organizacji też się liczą). Zlecenie jest zapisywane pod blokadą bazy per organizacja (`GET_LOCK`), a limit jest sprawdzany ponownie pod blokadą i jeszcze raz przez worker tuż przed zapytaniem – równoległe zlecenia (dwie karty, dwóch użytkowników) nie obejdą limitu.

## 3. Endpointy (sprawdzone w dokumentacji docs.dataforseo.com/v3, 10.2026)

| Cel | Metoda i ścieżka | Koszt |
|---|---|---|
| Test połączenia, saldo | `GET /v3/appendix/user_data` (wynik: `tasks[0].result[0].money.balance`) | bezpłatny |
| Kody lokalizacji | `GET /v3/keywords_data/google_ads/locations/{kraj}` (np. `pl`) | bezpłatny |
| Pomysły słów | `POST /v3/keywords_data/google_ads/keywords_for_keywords/live` | ok. 0,09 USD / zadanie (cennik 10.2026) |

Zadanie `keywords_for_keywords/live` – tablica z dokładnie jednym zadaniem:

```json
[{ "keywords": ["sofy na wymiar", "narożniki"], "location_code": 2616, "language_code": "pl", "sort_by": "relevance" }]
```

- `keywords`: maks. 20 fraz, każda maks. 80 znaków (aplikacja normalizuje, deduplikuje i sortuje, więc ta sama lista = ten sam klucz pamięci podręcznej).
- Wynik: płaska lista w `tasks[0].result[]`: `keyword`, `search_volume`, `cpc` (USD – zapisywane z walutą w `keyword_ideas.cpc_currency`; do porównań z kontem przeliczane kursem NBP z datą i źródłem kursu, bez kursu – „porównanie niedostępne”), `competition` (`LOW`/`MEDIUM`/`HIGH`), `competition_index` (0–100), `low_top_of_page_bid`, `high_top_of_page_bid`, `monthly_searches`.
- Brakujące wartości zapisujemy jako `NULL` („Brak danych” w interfejsie) – nigdy 0.
- Kod lokalizacji Polski `2616` to kod Google Ads geo target constant, którego używa DataForSEO; **przed pierwszą analizą sprawdź go** bezpłatnie: `wp acorn pp:dataforseo:check --locations=pl`.

Koszt nie jest jednolity (zależy od endpointu, trybu live/standard, a w DataForSEO Labs od liczby wyników). Stała `DataForSeoClient::ESTIMATES` to szacunek używany do kontroli limitu **przed** zapytaniem; po zapytaniu zapisywany jest koszt faktyczny z pola `cost` odpowiedzi (gdy brak pola – szacunek). Nowy endpoint = wpis w `ESTIMATES` + parser + test.

## 4. Przepływ

1. Użytkownik otwiera zakładkę DataForSEO – aplikacja pokazuje plan (frazy, lokalizację, szacunek, stan limitów) wyliczony z bazy, bez zapytań do API.
2. Po zaznaczeniu zgody: walidacja (konfiguracja, zgoda, limit projektu i organizacji, niezmieniony szacunek), zadanie trafia do `pp_dataforseo_tasks` (`queued`, kto zlecił, kiedy zatwierdził). Identyczne oczekujące zadanie nie jest dublowane.
3. Worker (`pp:sync:work`) przejmuje zadanie atomowo, sprawdza pamięć podręczną, ponownie sprawdza limit tuż przed płatnym zapytaniem, wywołuje API (timeout 120 s), zapisuje odpowiedź w `pp_dataforseo_cache`, frazy w `pp_keyword_ideas`, koszt i liczbę wyników w zadaniu.
4. Po sukcesie: analiza słów kluczowych i rekomendacji dla ostatnich 30 dni (bez audytu). Frazy pojawiają się w „Nowych frazach” ze źródłem „DataForSEO” i datą aktualizacji. Pełny proces: frazy → deduplikacja → porównanie z istniejącymi słowami i wykluczeniami → jakość frazy (język, firmy, konkurencja) → dopasowanie do oferty (pojęcia) → intencja → wolumen/konkurencja/CPC (z przeliczeniem waluty) → Opportunity Score 2.0 i pewność → klastry → sugerowana kampania/grupa → rekomendacja. Statusy fraz (zaakceptowana, odrzucona, w planie…) nie zmieniają się przy ponownym pobraniu.

Architektura jest gotowa na cykliczne odświeżanie (zadanie można zlecić z crona), ale obecnie zadania tworzy wyłącznie użytkownik.

## 5. Błędy i ponowienia

| Kod | Znaczenie | Zachowanie |
|---|---|---|
| 20000 | OK | – |
| 40100/40101, HTTP 401 | błędne dane logowania | błąd bez ponowienia |
| 40200/40210, HTTP 402 | brak środków | błąd bez ponowienia („Brak środków…”) |
| 40203 | limit kosztów ustawiony w panelu DataForSEO | błąd bez ponowienia |
| 40202, 40209, HTTP 429 | limit zapytań / zbyt wiele równoczesnych | ponowienie (maks. 3 próby, odstęp 1, 2, 4 min) |
| 50000+, HTTP 5xx, timeout, brak połączenia | błąd przejściowy | ponowienie |
| 40000–40999 (inne), np. 40501 | niepoprawne parametry | błąd bez ponowienia |
| odpowiedź bez `tasks` / niepoprawna lista wyników | nieprawidłowa odpowiedź | błąd bez ponowienia |

Sprawdzane są: kod HTTP, `status_code` koperty i `status_code` pierwszego zadania. Komunikaty błędów nie zawierają danych logowania. Zadania przerwane (np. restart) wracają do kolejki po 15 minutach.

## 6. Test połączenia

- Interfejs: Keyword Opportunities → DataForSEO → „Test połączenia (bezpłatny)”.
- Konsola: `wp acorn pp:dataforseo:check` (saldo) i `wp acorn pp:dataforseo:check --locations=pl` (kody lokalizacji). Oba używają wyłącznie bezpłatnych endpointów.

## 7. Pierwsza analiza

1. Dodaj stałe do `wp-config.php` i uruchom `wp acorn pp:dataforseo:check --locations=pl` – sprawdź saldo i kod lokalizacji „Poland” (oczekiwany `2616`).
2. Uzupełnij kontekst biznesowy (usługi priorytetowe, oferta, niepożądani odbiorcy, lokalizacja, język).
3. Ustaw limit projektu (np. 2 USD na start).
4. Keyword Opportunities → DataForSEO: sprawdź frazy źródłowe i szacunek, zaznacz zgodę, „Pobierz pomysły z DataForSEO”.
5. Poczekaj na `pp:sync:work` (do 10 minut) albo uruchom go ręcznie: `wp acorn pp:sync:work --no-schedule --time=60`.
6. Sprawdź status zadania i koszt faktyczny na zakładce DataForSEO, a nowe frazy na zakładce „Nowe frazy”.

Do testów integracji bez kosztów użyj `PP_DATAFORSEO_SANDBOX=true` (dane przykładowe – nie wyciągaj z nich wniosków; pamięć podręczna sandboxa jest oddzielona od produkcyjnej).
