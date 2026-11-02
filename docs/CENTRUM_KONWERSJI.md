# Centrum konwersji

Flaga `pp2_conversions` (stała `PP2_CONVERSIONS` albo filtr `pennypincher/feature/pp2_conversions`), domyślnie wyłączona.
Z flagą sekcja Konwersje ma pierwszy podwidok „Centrum konwersji” (`conversions/center/?tab=`); bez flagi adres
przekierowuje do `conversions/` z zachowanym okresem. Strony Konwersje (klasyfikacja) i Conversion Management
działają bez zmian pod dotychczasowymi adresami – Centrum składa się z tych samych partiali
(`resources/views/app/partials/conversions/*`), więc liczby, formularze i uprawnienia są wspólne.

## Zakładki

| Zakładka | Zawartość | Źródło |
|---|---|---|
| Wyniki | pokrycie i źródło danych (API / import, raport okresowy vs dzienny), podsumowanie konta, konwersje per kampania | `ConversionBreakdown::forPeriod` dla wybranego okresu, `MetricsRepository::resolve('conversion_metrics')` |
| Cele i zdarzenia | odczyt z Google Ads i tryb zapisu, modele atrybucji, klasyfikacja Penny Pincher, tabela działań z edycją (szkic), nowe działanie | `ConversionsController@update`, `ConversionManagementService::overview` (liczby działań: ostatnie 90 dni – opisane) |
| Diagnostyka | Conversion Health Check, cele konta i kampanii | `ConversionHealthCheck` (bez zmian) |
| Historia | zestawy Change Center z operacjami `conversion_action.*` / `*_conversion_goal.update`, ostatnie ręczne klasyfikacje | `change_sets` + `change_operations`, `conversion_actions.classified_at/by` |

## Zasady

- Konwersje Google Ads (kolumna „Konwersje”, model atrybucji ustawiony w Google Ads) są pokazywane osobno od klas
  Penny Pincher; kampanie nie są sumowane do wyniku konta; brak danych = „Brak danych”, nie 0.
- Okres z nagłówka dotyczy Wyników; tabela działań zawsze pokazuje ostatnie 90 dni (napisane w widoku).
- Uprawnienia bez zmian: klasyfikacja – `UpdateProject`, przygotowanie zmian – `PrepareChanges` + tryb zapisu.
  Zmiany w Google Ads powstają wyłącznie jako szkic w Change Center (podgląd → zatwierdzenie → wdrożenie).
- Formularze z Centrum wysyłają pole `return=center:{zakładka}`; po zapisie wracają do zakładki tylko z flagą
  (`ConversionCenterService::back`, inne wartości ignorowane).
- Historia klasyfikacji: Penny Pincher przechowuje tylko ostatnią ręczną zmianę (brak tabeli historii – bez migracji).

## Testy

`tests/Integration/ConversionCenterTest` (zgodność liczb i fragmentów HTML ze starymi stronami, powroty formularzy,
uprawnienia, historia, flaga, izolacja).

Rollback: wyłączenie `pp2_conversions`.
