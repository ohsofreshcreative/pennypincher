# Conversion Management

Moduł `/app/projects/{id}/conversion-management` pokazuje **konfigurację konwersji w Google Ads** obok **klasyfikacji Penny Pincher** i pozwala przygotować zmiany konfiguracji przez [Change Center](CHANGE_CENTER.md).

Dwa światy są rozdzielone:
- **klasyfikacja Penny Pincher** (moduł Konwersje: lead / potencjalny lead / sprzedaż / mikro, „cel biznesowy”) – służy wyłącznie analizie, nigdy nie zmienia Google Ads,
- **konfiguracja Google Ads** (`ConversionAction`, `CustomerConversionGoal`, `CampaignConversionGoal`) – zmieniana tylko przez zatwierdzone zestawy zmian.

## Lista działań

Nazwa, typ i źródło (origin), status, główna/dodatkowa (`primary_for_goal`), kategoria, sposób zliczania, okna po kliknięciu / wyświetleniu, wartość domyślna, model atrybucji, klasyfikacja PP, konwersje z 90 dni, kampanie z konwersjami i informacja, które pola **można zmienić przez API**. Dane z synchronizacji uzupełnia odczyt „Odczytaj konwersje i cele z Google Ads” (GAQL, bez zapisu); wartości z API mają pierwszeństwo.

## Co można zmienić (zweryfikowane z modelem API v25)

| Zasób | Operacja | Pola |
|---|---|---|
| `ConversionAction` (WEBPAGE / AD_CALL / WEBSITE_CALL / CLICK_TO_CALL / UPLOAD_* / GOOGLE_ANALYTICS_4_*) należący do konta | update | nazwa, kategoria, zliczanie, główna/dodatkowa, okna konwersji, wartość domyślna – zakres pól zależy od typu (`ConversionFieldPolicy::BY_TYPE`) |
| `ConversionAction` | create | **tylko typ WEBPAGE**; domyślnie dodatkowa; ostrzeżenie: bez tagu Google (gtag.js) lub konfiguracji w Google Tag Manager konwersja nie będzie rejestrowana |
| `CustomerConversionGoal` (`kategoria~źródło`) | update | `biddable` (czy cel konta jest używany w optymalizacji) |
| `CampaignConversionGoal` (`kampania~kategoria~źródło`) | update | `biddable` |

### Centralna polityka pól: `Domain/Conversions/ConversionFieldPolicy`

Jedno źródło prawdy o tym, co wolno zmienić w działaniu konwersji. Korzystają z niej: formularze Conversion Management, Conversion Health Check, reguły audytu CT001/CT003, rekomendacje i Action Plan (`ChangeProposalService::recommendationBlocker`, `ChangeLinkService`), generator operacji (`ChangeProposalService::conversionUpdate`), Change Center (`addOperation`, `updateOperation`, walidacja, widok) i budowanie żądania `googleAds:mutate` (`OperationCatalog::build` – ostatnia linia obrony).

Każde pole ma jeden ze statusów:

| Status | Znaczenie | Zachowanie |
|---|---|---|
| `editable` | API pozwala zmienić pole dla tego typu | pole w formularzu, operacja w Change Center |
| `confirm` | edytowalne, ale wpływa na Smart Bidding (`primary_for_goal`) | operacja wymaga zaznaczenia potwierdzenia (sekcja „Wymaga Twojego potwierdzenia”) |
| `read_only` | Google Ads API nie pozwala zmienić pola dla tego działania | pole ukryte, generator odmawia, rekomendacja = „Działanie ręczne” |
| `unsupported` | API mogłoby pozwolić, ale Penny Pincher świadomie tego nie obsługuje (konwersje aplikacji, Firebase, LSA, pola spoza zakresu) | jak wyżej, z innym komunikatem |

Podstawa (googleapis, `google/ads/googleads/v25`): `type` jest IMMUTABLE; `origin`, `owner_customer`, ustawienia GA4/Firebase/aplikacji – OUTPUT_ONLY; `owner_customer = null` oznacza działanie systemowe; typy oznaczone w `ConversionActionTypeEnum` jako **„Read only”** nie są edytowalne (błąd `ConversionActionError.UPDATE_NOT_SUPPORTED`):
`SMART_CAMPAIGN_AD_CLICKS_TO_CALL`, `SMART_CAMPAIGN_MAP_CLICKS_TO_CALL`, `SMART_CAMPAIGN_MAP_DIRECTIONS`, `SMART_CAMPAIGN_TRACKED_CALLS`, `GOOGLE_HOSTED`, `LEAD_FORM_SUBMIT`, `STORE_VISITS`, `STORE_SALES`, `FLOODLIGHT_ACTION`, `FLOODLIGHT_TRANSACTION`, `SEARCH_ADS_360`, `ANDROID_APP_PRE_REGISTRATION`, `ANDROID_INSTALLS_ALL_OTHER_APPS`, `WEBPAGE_CODELESS` (oraz wycofane: Universal Analytics, Salesforce).

Pola zależne od typu: okno po wyświetleniu – tylko WEBPAGE i GA4; kategoria – WEBPAGE i UPLOAD_CLICKS; nazwa, kategoria i wartość importów GA4 są zarządzane w Google Analytics. Działania usunięte lub ukryte (`REMOVED`, `HIDDEN`) nie są edytowane. Bez odczytu z Google Ads polityka korzysta z typu i statusu z synchronizacji; po odczycie – z danych API (w tym `owner_customer`).

Istniejące zestawy zmian **nie są zmieniane automatycznie**. Jeśli zawierają operacje na polach tylko do odczytu, Change Center pokazuje sekcję „Operacje nieobsługiwane przez Google Ads API” z listą operacji do usunięcia; wysłanie do przeglądu i zatwierdzenie są zablokowane do czasu ich usunięcia.

Usuwanie działań: Penny Pincher ich nie usuwa – zamiast tego „dodatkowa”. Cele konta/kampanii nie są tworzone – Google tworzy je automatycznie.

## Conversion Health Check

`Domain/Conversions/ConversionHealthCheck` wykrywa:

| Problem | Propozycja |
|---|---|
| mikrokonwersja jako główna (kategoria/klasa mikro lub nazwa: „30 sekund”, scroll, czas na stronie, wyświetlenie strony, „klik w realizacje”…) | ustaw jako dodatkową (dalej mierzona w „Wszystkie konwersje”) |
| leady liczone „każda” | „jedna na kliknięcie” (dla typów tylko do odczytu, np. połączenia z kampanii inteligentnych – działanie ręczne, bez operacji) |
| możliwe duplikaty (dwa główne działania o podobnych nazwach/kategoriach) | tylko wyjaśnienie – wybór należy do użytkownika |
| główne działanie bez konwersji w 90 dni | sprawdź tag; ewentualnie dodatkowa |
| cel niepasujący do oferty (np. zakup przy firmie usługowej) | dodatkowa |
| kampania optymalizuje cel wyłączony na koncie / inny niż konto | wyłącz cel kampanii |
| brak wiarygodnego pomiaru leadów | utwórz konwersję formularza + tag, potem dopiero ustaw jako główną |

Przykład OhSoFresh: „30 sekund – Bezpłatna konsultacja” jako konwersja główna. Przepływ: wykrycie → wyjaśnienie (dlaczego to uczy Smart Bidding złych sygnałów) → propozycja (dodatkowa) → lista kampanii, których dotyczy → szkic w Change Center, który użytkownik może edytować → odczyt stanu z API → **osobne potwierdzenie zmiany celu** (`confirm_goal_change` w operacji + potwierdzenie Smart Bidding przy zatwierdzeniu, ryzyko wysokie) → wdrożenie dopiero po świadomym kliknięciu wykonawcy.

## Bezpieczeństwo

Zmiany celów i konwersji głównych zawsze mają ryzyko wysokie i wymagają dwóch potwierdzeń. Nie ma automatycznych zmian po wykryciu problemu. Klasyfikacja w module Konwersje nie generuje żadnych operacji (test `testSystemConversionIsReadOnlyAndClassificationNeverTouchesGoogleAds`).
