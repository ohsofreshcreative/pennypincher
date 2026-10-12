# Google Ads Management

Moduł `/app/projects/{id}/management` – przegląd i przygotowanie zmian w strukturze konta. **Żadna akcja nie zapisuje niczego w Google Ads** – każda tworzy (lub uzupełnia wybrany) szkic w [Change Center](CHANGE_CENTER.md).

## Źródła danych

- lista kampanii, grup, słów i wykluczeń – z synchronizacji (`campaigns`, `ad_groups`, `keywords`, `negative_keywords`) + metryki 30/90 dni,
- reklamy i kryteria (lokalizacje, języki, wykluczenia z resource names) – odczyt na żądanie przyciskiem „Odczytaj … z Google Ads” (tylko GAQL, zapis w `google_ads_snapshots`),
- **stan „przed” każdej operacji zawsze czytany z API** (Change Center → „Odczytaj stan z Google Ads”, wymagany przed zatwierdzeniem, ważny 24 h) i ponownie tuż przed wysłaniem.

Zasoby z importu CSV nie mają identyfikatorów Google Ads – zmian dla nich nie da się przygotować (komunikat, bez pustego szkicu).

## Obsługiwane operacje (Search)

| Obszar | Operacje | Uwagi |
|---|---|---|
| Kampania | utworzenie, nazwa, wstrzymanie/włączenie, sieci (partnerzy, Display), strategia stawek (Ręczny CPC, Maks. kliknięć ± limit CPC, Maks. konwersji ± docelowy CPA), lokalizacje (ID geo), języki, wykluczenia, usunięcie kryterium | nowa kampania **zawsze PAUSED**, własny budżet `explicitlyShared=false`, `containsEuPoliticalAdvertising` wymagane; budżet + kampania + kierowanie w jednym atomowym żądaniu z tymczasowymi ID |
| Budżet | zmiana kwoty dziennej | `amount_micros` (wielokrotność 10 000), podgląd obecny/proponowany/różnica/×30,4, ostrzeżenie przy zwiększeniu, budżet współdzielony – lista kampanii i wymagane potwierdzenie; budżet planistyczny ≠ gwarantowany limit wydatków |
| Grupa reklam | utworzenie (domyślnie wstrzymana), nazwa, wstrzymanie/włączenie, domyślny maks. CPC | kontrola duplikatu nazwy |
| Słowa kluczowe | dodanie (EXACT / PHRASE / BROAD), wstrzymanie/włączenie, usunięcie (z potwierdzeniem), zmiana dopasowania | maks. 80 znaków i 10 słów, duplikaty (synchronizacja + API), konflikt z wykluczeniami kampanii/grupy; **zmiana dopasowania = nowe słowo + usunięcie starego w jednej grupie atomowej** – stare nie zniknie, jeśli nowe zostanie odrzucone |
| Wykluczenia | kampania i grupa: dodanie, usunięcie | lista aktywnych słów, które wykluczenie zablokuje; nigdy wdrażane automatycznie (także z pojedynczego drogiego zapytania) |
| Reklamy RSA | nowa reklama, „nowa wersja” (nowa + wstrzymanie starej atomowo), wstrzymanie/włączenie | walidacja: 3–15 nagłówków ≤ 30 znaków, 2–4 opisy ≤ 90, https final URL, ścieżki ≤ 15; Google może reklamę odrzucić (zasady) |

## Nieobsługiwane (celowo – oznaczone jako niedostępne, nigdy symulowane)

- edycja kampanii Performance Max, Display, Video, Shopping, App, Demand Gen (tylko odczyt),
- tworzenie budżetów współdzielonych i przepinanie kampanii między budżetami, strategie portfelowe, Target ROAS / tCPA jako osobne strategie, harmonogramy, korekty stawek urządzeń / lokalizacji, zasoby (rozszerzenia), listy wykluczeń konta,
- edycja treści istniejącej RSA w miejscu (zamiast tego „nowa wersja”),
- zmiany na stronie docelowej, UX, w tagach – to nie są operacje Google Ads.

## Adaptery (warstwy)

| Warstwa | Klasa | Rola |
|---|---|---|
| odczyt | `Infrastructure/GoogleAds/Changes/StateQueries` + `Services/GoogleAds/StateReader` | GAQL tylko z resource names o bezpiecznym alfabecie, normalizacja wierszy REST |
| budowa | `Domain/GoogleAds/Changes/OperationCatalog::build` | MutateOperation (REST JSON), updateMask tylko dla zmienianych pól |
| walidacja | `OperationCatalog::validate` + `ChangeCenterService::validateLocally / validateWithApi` | lokalna ≠ potwierdzenie Google; `validateOnly` tylko za zgodą |
| mutacja | `Infrastructure/GoogleAds/Mutate/GoogleAdsMutateClient` | jedyne miejsce z `googleAds:mutate`; własna kontrola trybu |
| interpretacja błędów | `GoogleAdsMutateClient::failures`, `MutateResult`, `AmbiguousMutateException` | błąd per operacja, wynik nieznany |
| weryfikacja | `ExecutionService::verify` | odczyt po zmianie i porównanie z oczekiwanym stanem |

Kontrolery (`GoogleAdsManagementController`, `ChangeCenterController`, `ConversionManagementController`) i widoki nigdy nie wysyłają mutacji.

## Uprawnienia OAuth / API

- zakres `https://www.googleapis.com/auth/adwords` (ten sam co do odczytu – zapis nie wymaga nowego zakresu, ale **użytkownik Google musi mieć na koncie dostęp Standard lub Administrator**; dostęp „tylko do odczytu” → błąd uprawnień),
- dostęp do API: konto testowe działa z poziomem Test; konta produkcyjne wymagają co najmniej poziomu Explorer (limit operacji dziennie) – sprawdź poziom w Google Cloud / API Center,
- koszt: mutacje i odczyty Google Ads API są bezpłatne (limity operacji), nie ma nowych płatnych usług.

## Zgoda na zapis i wykonawca

Sekcja „Uprawnienia do zapisu” (właściciel): włączenie zgody dla połączonego konta wymaga wpisania jego ID i działa **tylko gdy serwer ma `PP_GOOGLE_ADS_WRITE_MODE=write_enabled`**. Zgoda wygasa przy zmianie konta lub rozłączeniu. Flaga „wykonawca zmian” – tylko dla administratorów/właściciela.
