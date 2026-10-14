# Change Center – zmiany Google Ads pod kontrolą

Change Center to jedyna droga, którą Penny Pincher może cokolwiek zmienić na koncie Google Ads:

**Analiza → Rekomendacja → Przygotowanie zmiany → Podgląd → Zatwierdzenie → Wdrożenie przez API → Weryfikacja → Monitoring efektów.**

Domyślnie (i na czas Promptu 5) serwer działa w trybie **PREPARE_ONLY** – zmiany można przygotować, sprawdzić i zatwierdzić, ale **nic nie jest wysyłane do Google Ads**.

## Model danych (migracja `2026_10_12_000006_google_ads_changes`, addytywna)

| Tabela | Rola |
|---|---|
| `change_sets` | Zestaw zmian: projekt, organizacja, **połączenie i konto Google Ads** (na stałe), źródło (rekomendacja, Action Plan, plan…), status, wersja, zatwierdzona wersja + odcisk SHA-256, ryzyko, wpływ na budżet, klucz wdrożenia (unikalny), token workera, próby, błędy, odwołanie do cofanego zestawu. |
| `change_operations` | Operacje: unikalny `op_uuid`, typ (`campaign_budget.update`…), resource name, odwołanie tymczasowe i grupa atomowa, zależności, ładunek, **stan „przed”** (+ źródło: `api`/`sync`, czas odczytu), kontekst odczytu, ryzyko, ostrzeżenia, status, wynikowy resource name, błąd API, weryfikacja, powiązanie z obiektem lokalnym. |
| `change_events` | Historia: kto, kiedy, zmiana statusu, notatka. |
| `change_attempts` | Każdy kontakt z API przy zmianie (walidacja, wykonanie, uzgodnienie): wynik, HTTP, request-id, kod błędu, komunikat – **bez tokenów i bez pełnych odpowiedzi** (`ChangeSetRepository::scrub`). |
| `google_ads_links` | Obiekt lokalny (np. kampania planu) ↔ zasób Google Ads. Unikalne – chroni przed ponownym utworzeniem. |
| `google_ads_snapshots` | Odczyty na żądanie (reklamy, kryteria, konwersje, cele) – tylko do wyświetlania. |
| `organization_users.can_execute_changes` | Uprawnienie „wykonawca zmian” (nadaje właściciel, tylko admin/właściciel). |
| `google_ads_connections.write_enabled[_by/_at]` | Zgoda właściciela na zapis dla konkretnego konta. Reset przy zmianie konta i rozłączeniu. |

## Statusy

Zestaw: **Szkic → Do przeglądu → Zatwierdzony → Walidacja → Gotowy do wdrożenia → Wdrażanie → Wdrożony / Częściowo wdrożony / Błąd wdrożenia / Wymaga uzgodnienia**, a przed wysłaniem w każdej chwili **Anulowany**.

Operacja: Oczekuje, Wysyłana, Przyjęta przez API, Zweryfikowana, Stan po zmianie inny niż oczekiwany, Odrzucona, Pominięta (zależność), Konflikt (zmiana poza Penny Pincher), Wynik nieznany.

W interfejsie zawsze rozróżniamy cztery poziomy:
1. **Szkic** – nic nie wysłano,
2. **Zatwierdzone** – administrator zaakceptował propozycję, nadal nic nie wysłano,
3. **Wdrożone** – Google Ads API przyjął operację,
4. **Zweryfikowane** – ponowny odczyt potwierdził stan na koncie.

Efekt biznesowy oceniamy osobno (Monitoring → Efekty wdrożonych zmian). Komunikat po kliknięciu „Wdróż” brzmi „Zestaw trafił do kolejki… To jeszcze nie jest wdrożenie”.

## Podgląd

Dla każdej operacji: konto, zasób (resource name), typ, tabela **przed / po** (wartości z odczytu API, nie z synchronizacji), uzasadnienie (źródło), ryzyko, ostrzeżenia, błędy walidacji. Dla budżetu: obecnie / proponowane / różnica (kwota i %) / szacunek miesięczny ×30,4, ostrzeżenie przy zwiększeniu i lista kampanii przy budżecie współdzielonym. W szkicu i przeglądzie operacje można edytować (pola ładunku) lub usuwać – każda zmiana podnosi wersję i unieważnia zatwierdzenie.

## Zatwierdzanie (dwa osobne kroki)

1. **Akceptacja propozycji** (administrator): wymaga odczytu stanu z API nie starszego niż 24 h, poprawnej walidacji lokalnej, dodatkowego potwierdzenia przy **wysokim ryzyku** i osobnego przy zmianach **celów konwersji / strategii stawek**. Zapisuje wersję i odcisk operacji. Potem walidacja: lokalna (zawsze) i – jeśli dozwolona – `validateOnly` w Google Ads API. Odrzucenie przez Google → powrót do szkicu.
2. **Autoryzacja operacji API** (wykonawca): przycisk „Wdróż do Google Ads” dostępny tylko przy trybie WRITE_ENABLED, zgodzie dla konta i uprawnieniu wykonawcy. Potwierdzenie: zaznaczenie, a przy wysokim ryzyku wpisanie słowa **WDRÓŻ**. Formularz niesie jednorazowy `deploy_key` (podwójne kliknięcie / ponowne wysłanie formularza = zignorowane).

Zatwierdzenie rekomendacji (status w module Rekomendacje) **nie** jest zatwierdzeniem zmiany i odwrotnie.

## Ryzyko

- wysokie: zwiększenie budżetu, zmiana strategii stawek, cele konwersji (konto/kampania), konwersja główna / zliczanie, włączenie kampanii, usunięcie słowa, lokalizacji lub języka,
- średnie: zmniejszenie budżetu, wstrzymania, wykluczenia, nowe aktywne słowa,
- niskie: nazwy, elementy tworzone jako wstrzymane.

## Wdrożenie (worker)

Tylko `ExecutionService` (wywoływany przez `pp:changes:work` i `pp:sync:work`) wysyła mutacje, wyłącznie przez `GoogleAdsMutateClient`. Kroki:

1. przejęcie zestawu (`claim` z tokenem – drugi worker go pominie) + `GET_LOCK` per konto,
2. ponowne sprawdzenie uprawnień osoby, która zleciła wdrożenie (rola mogła się zmienić), trybu, zgody, wykonawcy, **zgodności konta i połączenia** z zestawem,
3. kontrola odcisku: operacje identyczne z zatwierdzoną wersją, inaczej → ponowny przegląd,
4. **ochrona przed dryfem**: ponowny odczyt stanu każdego zasobu; zmiana poza Penny Pincher → konflikt, zatwierdzenie unieważnione, nic nie wysłane,
5. wysyłka: grupy atomowe (tymczasowe ID tylko w jednym żądaniu) jako jedno żądanie bez `partial_failure`, pozostałe w porcjach z `partial_failure` – wynik zapisywany per operacja,
6. operacje przyjęte nigdy nie są wysyłane ponownie (`OP_DONE`),
7. powiązania lokalne ↔ Google, weryfikacja odczytem, status końcowy, zdarzenie w historii rekomendacji („deployed”, bez zmiany jej statusu).

### Błędy i uzgadnianie

| Sytuacja | Zachowanie |
|---|---|
| 400 – walidacja | operacje odrzucone, komunikat Google przy operacji, Częściowo / Błąd |
| limit zapytań (429) | nic nie zapisano → ponowienie później (backoff od 60 s, maks. 3 próby, potem zatrzymanie z komunikatem), zestaw czeka w kolejce |
| autoryzacja / uprawnienia | operacje odrzucone, bez ponawiania |
| **timeout, brak odpowiedzi, 5xx po wysłaniu** | **wynik nieznany** → „Wymaga uzgodnienia”, **bez automatycznego ponowienia** |
| worker przerwany w trakcie (> 30 min) | „Wymaga uzgodnienia” |

„Uzgodnij stan” (administrator) tylko czyta konto: operacje znalezione w oczekiwanym stanie → przyjęte; nieznalezione → wracają do „Oczekuje”, a zestaw do „Gotowy do wdrożenia” (ponowne świadome wdrożenie wyśle tylko brakujące); inny stan → konflikt.

## Cofanie

„Przygotuj cofnięcie” tworzy **nowy szkic** (źródło „Cofnięcie zmiany”), przechodzący pełny przegląd i zatwierdzenie. Odwrotności przygotowujemy tylko gdy są bezpieczne i znamy stan „przed” (budżet, statusy, nazwy, sieci, strategia standardowa, cele; dodane wykluczenia i kryteria kampanii – usunięcie, dodane słowa, grupy i reklamy – wstrzymanie; nowej kampanii nie cofamy automatycznie – powstaje jako wstrzymana i nic nie wydaje). Nie obiecujemy pełnego cofnięcia: usuniętego słowa nie da się przywrócić z historią, okres uczenia strategii nie wraca.

„Przygotuj ponowienie” – nowy szkic tylko z operacji nieudanych/pominiętych.

## Efekty

`ChangeEffectsService`: kampanie objęte zmianą, okna tej samej długości przed i po (maks. 14 dni, min. 7 pełnych dni po), min. 30 kliknięć w każdym oknie, inaczej „brak wiarygodnego porównania”. Zawsze z zastrzeżeniami (sezonowość, opóźnione konwersje, inne zmiany w tym czasie – wypisane). Penny Pincher **nie oznacza rekomendacji jako skutecznej**.

## Uprawnienia

| Rola | Może |
|---|---|
| Członek (Member) | przeglądać, przygotowywać szkice, edytować, wysyłać do przeglądu, anulować swoje szkice |
| Administrator | + zatwierdzać, odsyłać, uzgadniać stan |
| Administrator z flagą „wykonawca zmian” | + uruchamiać wdrożenie |
| Właściciel | + zgoda na zapis dla konta, nadawanie flagi wykonawcy |

(Rola „Viewer” nie istnieje w systemie – najniższą rolą jest Członek.) Każda akcja sprawdza organizację i projekt po stronie serwera; zestawy innych projektów zwracają „nie znaleziono”; identyfikator konta pochodzi z zestawu i połączenia, nigdy z żądania HTTP; wszystkie POST wymagają nonce (CSRF).

## Źródła zmian

Przycisk „Przygotuj zmianę” w: Rekomendacjach, Budget Leaks, Action Plan, Keyword Opportunities (zaznaczone propozycje wykluczeń), Campaign Plannerze („Przygotuj wdrożenie do Google Ads”), Monitoringu (przejście do zarządzania kampanią) oraz w Google Ads Management i Conversion Management. Pokazywany tylko dla elementów z odpowiednikiem w API; pozostałe mają opis „wymaga działań poza Google Ads API”. Ponowne kliknięcie otwiera istniejący otwarty szkic.

Obsługiwane typy rekomendacji: `negatives`, `group_terms`, `group_keywords`, `keyword_opportunities`, `leak_bl101`, `leak_bl102`, `leak_bl201`, `leak_bl304`, `audit_cs001`, `audit_cs002`, `audit_ct001`, `audit_ct003`. Budget Leaks: BL101/BL102 (wykluczenie hasła, dopasowanie ścisłe), BL201 (wstrzymanie słowa), BL304 (sieci).

Rekomendacje konwersji (CT001 – konwersja główna, CT003 – zliczanie) są sprawdzane polityką pól `ConversionFieldPolicy` ([CONVERSION_MANAGEMENT.md](CONVERSION_MANAGEMENT.md)): działania, których pola API nie pozwala zmienić (np. `SMART_CAMPAIGN_AD_CLICKS_TO_CALL`), nie dostają operacji; gdy żadne działanie rekomendacji nie jest edytowalne, zamiast przycisku pokazywane jest „Działanie ręczne: …” (to samo w Action Plan).

## Pola tylko do odczytu i operacje nieobsługiwane przez API

Walidacja rozróżnia: brak aktualnego odczytu, **wymaga potwierdzenia** (`confirmations`), **operacje nieobsługiwane przez Google Ads API** (`unsupported` – np. zmiana zliczania w konwersji typu tylko do odczytu; jedyna droga: usunąć operację) i pozostałe błędy konfiguracji. `addOperation`/`updateOperation` odrzucają pola tylko do odczytu, formularz „Edytuj operację” ich nie pokazuje, a `OperationCatalog::build` nie zbuduje takiego żądania. Zestawy zapisane przed wprowadzeniem polityki nie są zmieniane automatycznie – widok wskazuje operacje do usunięcia.

## Testy

- `tests/Unit/ChangeDomainTest.php`, `tests/Integration/ChangeCenterTest.php`, `tests/Integration/GoogleAdsManagementTest.php` – na atrapie konta (`tests/Support/FakeGoogleAdsAccount`).
- `tests/Unit/ConversionFieldPolicyTest.php`, `tests/Integration/ConversionEditabilityTest.php` – edytowalność pól konwersji (wszystkie typy v25, konwersje Smart, rekomendacje, Action Plan, Change Center, odrzucenie przez atrapę jak `UPDATE_NOT_SUPPORTED`).
- `tests/Http/run.sh` – testy HTTP wszystkich formularzy Change Center, Google Ads Management, Conversion Management i przycisków „Przygotuj zmianę”/„Przygotuj wdrożenie” na lokalnym serwerze PHP: adresy i pola są brane z wyrenderowanego HTML, sprawdzany jest wynik kontrolera (przekierowanie, komunikat, stan zestawu), CSRF (419) i uprawnienia członka (403). Tylko lokalnie, na jednorazowej kopii bazy; nic nie wychodzi do Google.

## Zapis tylko na wskazanym koncie i blokada emisji

**MANUAL APPROVAL**: rekomendacja, szkic i zatwierdzenie nigdy nie wysyłają zmian. Polecenie wdrożenia tworzy wyłącznie ręczne „Wdróż do Google Ads” (wykonawca, POST z nonce); worker wykonuje tylko zestaw z ważnym poleceniem wydanym po zatwierdzeniu tej wersji (`deploy_key`, `deploy_requested_by`, `deploy_requested_at` ≥ `approved_at`, zgodny odcisk). Wykonanie wymaga konta z allowlisty `PP_GOOGLE_ADS_WRITE_CUSTOMERS` (pusta = żadne; brak niekonfigurowalnych blokad kont, opcjonalnie `PP_GOOGLE_ADS_BLOCKED_CUSTOMERS`). Blokada emisji reklam (domyślna): nowe kampanie, grupy i reklamy tylko jako wstrzymane (odrzucane przy przygotowaniu); włączenie istniejących zasobów i zmiany budżetów aktywnych kampanii można przygotować i zatwierdzić – walidacja pokazuje je w sekcji „Wdrożenie zablokowane w trybie bezpieczeństwa” (`validateLocally()['serving']`, nie są błędem), a `deployBlocker` ukrywa przycisk „Wdróż”, `requestDeploy` odmawia, worker zatrzymuje zestaw po odczycie stanu, klient mutate odrzuca ENABLED. Worker sprawdza zgodę, allowlistę i tryb przed każdym żądaniem. Instrukcja testów: [GOOGLE_ADS_WRITE_TESTING.md](GOOGLE_ADS_WRITE_TESTING.md).

## Zobacz też

[GOOGLE_ADS_MANAGEMENT.md](GOOGLE_ADS_MANAGEMENT.md), [GOOGLE_ADS_WRITE_TESTING.md](GOOGLE_ADS_WRITE_TESTING.md), [CONVERSION_MANAGEMENT.md](CONVERSION_MANAGEMENT.md), [SECURITY.md](SECURITY.md), [DEPLOYMENT.md](DEPLOYMENT.md).
