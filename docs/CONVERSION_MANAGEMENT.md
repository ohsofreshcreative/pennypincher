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
| `ConversionAction` (WEBPAGE / AD_CALL / WEBSITE_CALL / CLICK_TO_CALL / UPLOAD_*) należący do konta | update | nazwa, kategoria, zliczanie, główna/dodatkowa, okna konwersji, wartość domyślna (zakres pól zależy od typu – `OperationCatalog::CONVERSION_EDITABLE`) |
| `ConversionAction` | create | **tylko typ WEBPAGE**; domyślnie dodatkowa; ostrzeżenie: bez tagu Google (gtag.js) lub konfiguracji w Google Tag Manager konwersja nie będzie rejestrowana |
| `CustomerConversionGoal` (`kategoria~źródło`) | update | `biddable` (czy cel konta jest używany w optymalizacji) |
| `CampaignConversionGoal` (`kampania~kategoria~źródło`) | update | `biddable` |

Działania importowane z GA4: tylko główna/dodatkowa, zliczanie i okna (nazwę i kategorię zmienia się w GA4). Tylko do odczytu: działania systemowe i należące do innego konta (np. Google Hosted, wizyty w sklepie, działania menedżera – brak `owner_customer` lub typ bez pól edytowalnych), typ działania (pole niezmienne), usuwanie działań (Penny Pincher ich nie usuwa – zamiast tego „dodatkowa”). Cele konta/kampanii nie są tworzone – Google tworzy je automatycznie.

## Conversion Health Check

`Domain/Conversions/ConversionHealthCheck` wykrywa:

| Problem | Propozycja |
|---|---|
| mikrokonwersja jako główna (kategoria/klasa mikro lub nazwa: „30 sekund”, scroll, czas na stronie, wyświetlenie strony, „klik w realizacje”…) | ustaw jako dodatkową (dalej mierzona w „Wszystkie konwersje”) |
| leady liczone „każda” | „jedna na kliknięcie” |
| możliwe duplikaty (dwa główne działania o podobnych nazwach/kategoriach) | tylko wyjaśnienie – wybór należy do użytkownika |
| główne działanie bez konwersji w 90 dni | sprawdź tag; ewentualnie dodatkowa |
| cel niepasujący do oferty (np. zakup przy firmie usługowej) | dodatkowa |
| kampania optymalizuje cel wyłączony na koncie / inny niż konto | wyłącz cel kampanii |
| brak wiarygodnego pomiaru leadów | utwórz konwersję formularza + tag, potem dopiero ustaw jako główną |

Przykład OhSoFresh: „30 sekund – Bezpłatna konsultacja” jako konwersja główna. Przepływ: wykrycie → wyjaśnienie (dlaczego to uczy Smart Bidding złych sygnałów) → propozycja (dodatkowa) → lista kampanii, których dotyczy → szkic w Change Center, który użytkownik może edytować → odczyt stanu z API → **osobne potwierdzenie zmiany celu** (`confirm_goal_change` w operacji + potwierdzenie Smart Bidding przy zatwierdzeniu, ryzyko wysokie) → wdrożenie dopiero po świadomym kliknięciu wykonawcy.

## Bezpieczeństwo

Zmiany celów i konwersji głównych zawsze mają ryzyko wysokie i wymagają dwóch potwierdzeń. Nie ma automatycznych zmian po wykryciu problemu. Klasyfikacja w module Konwersje nie generuje żadnych operacji (test `testSystemConversionIsReadOnlyAndClassificationNeverTouchesGoogleAds`).
