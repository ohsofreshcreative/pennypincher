# Penny Pincher – produkt

Penny Pincher to aplikacja webowa do audytu i optymalizacji kampanii Google Ads. Ma pomagać wydawać budżet reklamowy rozsądniej: wykrywać nieefektywne wydatki, analizować kampanie i wyszukiwane hasła, pilnować jakości pomiaru konwersji i zamieniać to w konkretne rekomendacje.

Aplikacja działa pod `/app/` w motywie Sage 11 (bez osobnej wtyczki) i jest przygotowana na wiele organizacji i wiele kont reklamowych.

## Pojęcia

| Pojęcie | Znaczenie |
|---|---|
| Organizacja | Firma lub klient agencji. Grupuje projekty i ludzi. |
| Członek organizacji | Konto WordPress z rolą: właściciel, administrator albo członek. |
| Projekt | Jedno konto reklamowe (na razie Google Ads) z budżetem, walutą, strefą czasową i opisem biznesu. |
| Import | Jeden przesłany plik raportu Google Ads wraz z mapowaniem kolumn, zakresem dat i wynikiem walidacji. |
| Dane dzienne / okresowe | Raport z kolumną „Dzień” (każdy wiersz = dzień) albo suma za cały zakres raportu. |

## Pierwszy użytkownik: OhSoFresh Creative

Konfiguracja pierwszego projektu (to ustawienia projektu, a nie stałe aplikacji):

- usługa: projektowanie i tworzenie stron internetowych dla firm,
- segment: B2B, w tym firmy rozważające redesign,
- wartość projektów: 8 000–30 000 PLN+,
- maksymalny budżet: 3 000 PLN miesięcznie,
- lokalizacja kampanii na start: Mazowsze,
- landing page: https://ohsofresh.pl/strony-internetowe/.

## Moduły

| Moduł | Etap | Stan |
|---|---|---|
| Fundament: organizacje, projekty, role, logowanie, `/app/` | 1 | gotowe |
| Import raportów Google Ads (CSV/XLSX) | 1 | gotowe |
| Przegląd (dashboard), Kampanie, Wyszukiwane hasła, Ustawienia | 1 | gotowe |
| Google Ads Integration (API, synchronizacja) | 2+ | planowane |
| Ads Audit | 2 | planowane |
| Budget Leaks | 2–3 | planowane |
| Keyword Intelligence (m.in. propozycje wykluczeń) | 3 | planowane |
| Conversion Intelligence | 3+ | planowane |
| Recommendations | 3+ | planowane |
| Campaign Planner | 4 | planowane |
| Monitoring i alerty | 4 | planowane |

Niedziałające moduły są w nawigacji pokazane wyłącznie jako zablokowana lista „W kolejnych etapach” – bez linków.

## Zakres MVP (etap 1)

- logowanie własnym ekranem `/app/login/` (konta WordPress),
- organizacje, członkowie i role, projekty z pełnymi ustawieniami,
- import raportów: Skuteczność kampanii, Wyszukiwane hasła, Słowa kluczowe, Działania powodujące konwersję,
- podgląd pliku, automatyczne rozpoznanie typu i kolumn, ręczna korekta mapowania,
- dashboard na prawdziwych danych: KPI, budżet, wykresy dzienne, kampanie, hasła, ostatnie importy,
- lista kampanii (filtry, sortowanie, paginacja, szczegóły i źródła danych),
- lista wyszukiwanych haseł (wyszukiwarka, filtry, sortowanie, paginacja),
- projekt demonstracyjny z fikcyjnymi danymi w osobnej organizacji „Demo”.

Poza zakresem etapu 1: Google Ads API, silnik audytowy, AI, automatyczne wykluczenia, alerty.

## Zasady dotyczące danych

- Nie pokazujemy fikcyjnych wyników poza projektem demo.
- Brak wartości w eksporcie to „—”, nigdy 0.
- Nie dzielimy raportu okresowego na dni i nie sumujemy nakładających się eksportów.
- Koszty z raportu wyszukiwanych haseł nie są dodawane do kosztów z raportu kampanii.
- Każda metryka ma źródło (import), ziarnistość i zakres dat.

## Plan dalszego rozwoju

1. **Prompt 2** – Ads Audit: reguły audytowe na zaimportowanych danych (struktura kampanii, budżety, stawki, jakość ruchu), raport wyników z priorytetami.
2. **Prompt 3** – Keyword Intelligence i Budget Leaks: klasyfikacja wyszukiwanych haseł (intencja, dopasowanie do oferty i klienta docelowego z ustawień projektu), propozycje wykluczeń, wykrywanie wydatków bez konwersji.
3. **Prompt 4** – Google Ads API, Conversion Intelligence, rekomendacje, planer kampanii i monitoring.
