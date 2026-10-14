# Zapis do Google Ads – MANUAL APPROVAL na koncie OhSoFresh

Penny Pincher zarządza **istniejącym kontem OhSoFresh (689-970-4535)** – tym samym, które jest połączone przez OAuth
i synchronizowane. Nie tworzymy nowego konta ani projektu Google Cloud. Aplikacja działa w modelu **MANUAL APPROVAL**:
może automatycznie pobierać dane, analizować, generować rekomendacje i przygotowywać propozycje zmian, ale **każda
rzeczywista zmiana w Google Ads wymaga świadomej decyzji człowieka**: zatwierdzenia konkretnej wersji zestawu
i osobnego, ręcznego kliknięcia „Wdróż do Google Ads”.

## 1. Proces

```
REKOMENDACJA → PRZYGOTOWANIE ZMIANY (szkic) → ODCZYT AKTUALNEGO STANU → WALIDACJA → PRZEGLĄD
→ ZATWIERDZENIE (administrator, konkretna wersja) → RĘCZNE „WDRÓŻ DO GOOGLE ADS” (wykonawca)
→ WYKONANIE PRZEZ API (worker) → PONOWNY ODCZYT → WERYFIKACJA
```

- Rekomendacja, szkic i zatwierdzenie **nie wysyłają** żadnej zmiany (przy zatwierdzeniu najwyżej walidacja
  `validate_only`, jeśli `PP_GOOGLE_ADS_API_VALIDATION=true` – bez zapisu).
- Polecenie wdrożenia tworzy wyłącznie kontroler po żądaniu POST z nonce od użytkownika z uprawnieniem „wykonawca
  zmian”. Żaden cron, worker ani usługa nie zatwierdza zestawów i nie tworzy poleceń wdrożenia.
- Worker wykonuje tylko zestaw z ważnym poleceniem (`deploy_key`, osoba, czas) wydanym **po** zatwierdzeniu tej wersji;
  odcisk SHA-256 operacji musi się zgadzać z zatwierdzonym. Każda edycja unieważnia zatwierdzenie.
- Skutecznie wdrożonego zestawu nie da się wdrożyć ponownie; operacje przyjęte nie są wysyłane drugi raz.
- Wynik niepewny (timeout, 5xx) – „Wymaga uzgodnienia”, bez automatycznego ponowienia.
- „Wdrożony” (przyjęte przez API) ≠ „Zweryfikowany” (potwierdzone ponownym odczytem z Google Ads).
- Odrzucenie przez API – konkretny komunikat Google przy operacji; częściowy sukces – „Częściowo wdrożony”, a nieudane
  operacje można przygotować ponownie jako nowy szkic.
- Cofnięcie – nowy szkic z operacją odwrotną, tylko tam, gdzie jest bezpieczna (nie każdą zmianę da się cofnąć, np.
  usunięcie słowa lub reklamy).
- Historia: kto przygotował (`created_by`), zatwierdził (`approved_by`, `approved_at`, wersja), zlecił wdrożenie
  (`deploy_requested_by`, `deploy_requested_at`), zakres operacji, wynik każdej operacji, request-id (`change_attempts`).

## 2. Warunki zapisu (wszystkie po stronie serwera)

| Warunek | Gdzie | Domyślnie |
|---|---|---|
| `PP_GOOGLE_ADS_WRITE_MODE=write_enabled` | klient mutate (każde żądanie), `WriteGuard`, worker | `prepare_only` – nic nie jest wysyłane |
| Konto na allowliście `PP_GOOGLE_ADS_WRITE_CUSTOMERS` | klient mutate, `WriteGuard`, zgoda właściciela | pusta = żadne konto, także OhSoFresh |
| Zgoda właściciela dla połączonego konta | `google_ads_connections.write_enabled` | wyłączona; wygasa przy zmianie konta |
| Uprawnienie „wykonawca zmian” | `organization_users.can_execute_changes`, sprawdzane też w workerze | brak |
| Zatwierdzenie konkretnej wersji + ręczne polecenie wdrożenia | Change Center, worker | – |
| Zgodność Customer ID | `ResourceName::isValid`, `WriteGuard`, `GoogleAdsMutateClient::foreignCustomers` | zawsze |
| Blokada emisji reklam | Change Center (przycisk), worker (odczyt stanu), klient mutate | **włączona** |

Sam wpis na allowliście nie włącza zapisu. Worker sprawdza tryb, allowlistę, zgodę i uprawnienia **przed każdym
żądaniem API**. Customer ID nigdy nie pochodzi z formularza, adresu ani żądania HTTP – zestaw jest związany z połączeniem
i kontem, a każdy resource name w operacjach musi należeć do tego konta. Opcjonalne blokady kont:
`PP_GOOGLE_ADS_BLOCKED_CUSTOMERS` (konto z tej listy nie dostaje żadnego żądania mutate, także walidacyjnego).

## 3. Blokada emisji reklam i kontrola budżetów (`PP_GOOGLE_ADS_ALLOW_SERVING`, domyślnie wyłączone)

- **Nowe** kampanie, grupy reklam i reklamy powstają wyłącznie jako **wstrzymane** (próba utworzenia aktywnych jest
  odrzucana już przy przygotowaniu; plan z Campaign Plannera wdraża się w całości jako wstrzymany).
- Zmiany **istniejących** zasobów, które mogą uruchomić emisję lub zwiększyć wydatki – włączenie kampanii, grupy lub
  reklamy, zmiana budżetu aktywnej kampanii, aktywne słowo w aktywnej grupie i kampanii – **można przygotować, przejrzeć
  i zatwierdzić**. Wdrożenie jest zablokowane: Change Center pokazuje sekcję „Wdrożenie zablokowane w trybie
  bezpieczeństwa”, przycisk „Wdróż do Google Ads” jest niedostępny, a nawet wymuszona kolejka zostanie zatrzymana przez
  worker (odczyt stanu tuż przed wysłaniem) i klient mutate (status ENABLED).
- Przykład: budżet 100 zł → 150 zł w aktywnej kampanii – podgląd „obecnie / po zmianie”, +50%, szacunek miesięczny,
  wysokie ryzyko, zatwierdzenie możliwe; wdrożenie czeka na świadome zdjęcie blokady.
- Wstrzymywanie, zmiany nazw, ustawień, słów kluczowych, wykluczeń i reklam we wstrzymanych zasobach nie są blokowane.
- Brak płatności na koncie nie jest traktowany jako zabezpieczenie.

## 4. Zakres edycji istniejących kampanii (Search)

| Obszar | Operacje |
|---|---|
| Kampanie | nazwa, status (włączenie – patrz blokada emisji), sieci, strategia standardowa, lokalizacje, języki, podgląd różnic |
| Grupy reklam | nowe (wstrzymane), nazwa, status, stawka CPC |
| Słowa kluczowe | dodanie, usunięcie, wstrzymanie/włączenie, stawka, zmiana dopasowania (atomowe zastąpienie), z rekomendacji |
| Wykluczenia | dodanie i usunięcie na poziomie kampanii i grupy, kontrola konfliktów z aktywnymi słowami |
| Reklamy RSA | nowa (wstrzymana), nowa wersja (nowa + wstrzymanie starej – treść RSA jest w Google Ads niezmienna), wstrzymanie/włączenie, usunięcie (nieodwracalne), podgląd treści |
| Budżety | obecny i nowy, różnica kwotowa i %, szacunek miesięczny, ryzyko, walidacja, zatwierdzenie; wdrożenie przy spełnionych warunkach |
| Konwersje | ustawienia wg `ConversionFieldPolicy`, główna/dodatkowa, cele konwersji, ostrzeżenia o Smart Bidding |

Niedostępne: Performance Max, Display, wideo, zakupowe, aplikacje (tylko odczyt), budżety współdzielone (tworzenie,
przepinanie), strategie portfelowe, harmonogramy, korekty stawek, zasoby (rozszerzenia), tworzenie kont, płatności.

## 5. Konfiguracja (na razie NIE włączamy zapisu)

Stan docelowy przed pierwszym testem: kod wdrożony, `PREPARE_ONLY`, allowlista pusta. Pierwszy kontrolowany zapis
(dopiero po wyraźnej decyzji) – w `wp-config.php` na serwerze:

```php
define('PP_GOOGLE_ADS_WRITE_MODE', 'write_enabled');
define('PP_GOOGLE_ADS_WRITE_CUSTOMERS', '689-970-4535'); // tylko konto OhSoFresh
// PP_GOOGLE_ADS_ALLOW_SERVING – nie ustawiaj (blokada emisji zostaje włączona)
// PP_GOOGLE_ADS_API_VALIDATION – opcjonalnie true: walidacja validate_only przy zatwierdzaniu
```

Potem w aplikacji: Google Ads Management → „Uprawnienia do zapisu” → „Odczytaj dane konta” → właściciel wpisuje
Customer ID → „Włącz zapis” → nadanie flagi „wykonawca zmian”. `wp acorn pp:google-ads:check` pokazuje tryb, allowlistę,
blokady i stan blokady emisji (bez sekretów).

## 6. Pierwszy kontrolowany zapis (krok po kroku, każdy po osobnej zgodzie)

1. Wybierz zmianę niskiego ryzyka na **wstrzymanym** zasobie (np. nazwa wstrzymanej grupy reklam albo dodanie
   wykluczenia frazowego w kampanii) – bez budżetów i bez włączania.
2. Przygotuj zmianę w Google Ads Management → szkic w Change Center.
3. „Odczytaj stan z Google Ads” → sprawdź podgląd „przed / po”.
4. Wyślij do przeglądu → zatwierdź (administrator).
5. Upewnij się, że sekcja „Uprawnienia do zapisu” pokazuje „Czy możesz wdrażać zmiany: Tak”.
6. „Wdróż do Google Ads” (wykonawca, potwierdzenie) → worker (`pp:sync:work` z crona albo `wp acorn pp:changes:work`).
7. Sprawdź: status „Wdrożony” → „Zweryfikowany”, request-id w historii, zmianę w historii zmian konta Google Ads.
8. Przygotuj cofnięcie i przejdź ten sam proces.
9. Po każdym kroku – decyzja, czy iść dalej.

## 7. Wyłączenie zapisu

- **Natychmiast (aplikacja):** „Wyłącz zapis dla konta” – zestawy w kolejce wracają do „Gotowy do wdrożenia”, trwające
  wdrożenie zatrzymuje się przed kolejnym żądaniem.
- **Serwer:** usuń `PP_GOOGLE_ADS_WRITE_MODE` (domyślnie `prepare_only`) albo wyczyść `PP_GOOGLE_ADS_WRITE_CUSTOMERS`.
  Działa od następnego procesu PHP; przebieg workera, który już trwa (do ok. 4 min), widzi starą konfigurację – przy
  pilnym zatrzymaniu najpierw wyłącz zgodę w aplikacji.

## 8. Dostęp do API

Developer tokeny zostały wycofane 9.09.2026 (nagłówek jest ignorowany); poziom dostępu należy do projektu Google Cloud
klienta OAuth. Zapis na koncie produkcyjnym wymaga co najmniej poziomu Explorer (do 2 880 operacji dziennie) – jeśli
synchronizacja OhSoFresh działa przez API, projekt ma co najmniej Explorer (sprawdź: Google Cloud Console → Google Ads
API → Overview). Użytkownik Google połączony przez OAuth musi mieć na koncie dostęp Standard lub Administrator.

## 9. Ryzyka pozostające

- Reguły słów kluczowych w aktywnych grupach i budżetów aktywnych kampanii wymagają odczytu stanu – egzekwuje je Change
  Center i worker, a nie sam klient mutate (klient egzekwuje: tryb, allowlistę, blokady, Customer ID, status kampanii,
  grup i reklam).
- Ręczna zmiana w interfejsie Google Ads między odczytem a wysłaniem – ograniczona ponownym odczytem tuż przed
  wysłaniem (konflikt = stop), ale nie da się jej wykluczyć w 100%.
- Pierwsze prawdziwe zapisy mogą ujawnić różnice między atrapą a API (np. zasady reklamowe) – dlatego krok po kroku.
- OhSoFresh to konto produkcyjne z historią – każda zmiana wpływa na prawdziwe kampanie; dlatego zaczynamy od zasobów
  wstrzymanych i operacji odwracalnych.

## 10. Testy (atrapa API, bez sieci)

`tests/Integration/ManualApprovalWriteTest.php` (A: manual approval, B: konto OhSoFresh, C: istniejące kampanie,
D: blokada emisji, E: Change Center), `tests/Unit/WriteSafetyTest.php`, `ChangeCenterTest`, `GoogleAdsManagementTest`,
`ConversionEditabilityTest`, testy HTTP `tests/Http/run.sh`.

## Źródła

- [Access levels and permissible use](https://developers.google.com/google-ads/api/docs/api-policy/access-levels)
- [Developer token (sunset 9.09.2026)](https://developers.google.com/google-ads/api/docs/api-policy/developer-token)
- googleapis v25: `resources/campaign.proto`, `campaign_budget.proto`, `ad_group_criterion.proto`, `ad_group_ad.proto`,
  `services/google_ads_service.proto` (MutateGoogleAdsRequest).
