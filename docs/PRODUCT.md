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
| Google Ads Integration (OAuth, wybór konta, synchronizacja 12 mies. + codzienna) | 2 | zaimplementowane, przetestowane tylko na atrapie API – wymaga konfiguracji Google Cloud |
| Ads Audit (23 reguły deterministyczne, Audit Score, statusy problemów, wybór okresu) | 2–3 | gotowe |
| Wdrożenie produkcyjne (Hostinger) | 2 | przygotowane, niewykonane (blokada: klucz SSH) |
| Budget Leaks | 3 | gotowe (reguły hasła/słowa/kampanie/pomiar, pewność statystyczna, szacunek oszczędności) |
| Keyword Opportunities (Opportunity Score, propozycje wykluczeń) | 3 | gotowe; frazy z DataForSEO zweryfikowane tylko na atrapie API |
| Klasyfikacja konwersji (lead / potencjalny kontakt / sprzedaż / mikro…) | 3 | gotowe (podstawa Conversion Intelligence) |
| Recommendations | 3 | gotowe (statusy, notatki, historia, deduplikacja) |
| Integracja DataForSEO | 3 | zaimplementowana, przetestowana tylko na atrapie – wymaga danych dostępowych |
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

## Zakres etapu 2

- połączenie projektu z kontem Google Ads (OAuth 2.0, tylko odczyt), wybór konta (także pod MCC), status, ponowna autoryzacja, rozłączenie,
- synchronizacja: kampanie z ustawieniami, lokalizacje, grupy reklam, słowa kluczowe, wyszukiwane hasła, działania konwersji z ustawieniami, metryki dzienne; historia 12 miesięcy, potem codziennie; ręcznie, z crona i z CLI; postęp, liczba rekordów, błędy, ponowienie,
- dane z API mają pierwszeństwo przed importami za te same dni (bez podwójnego liczenia), importy dalej uzupełniają brakujące okresy,
- audyt: 22 reguły w kategoriach pomiar konwersji, ustawienia kampanii, budżet, jakość danych, skuteczność; każdy problem z dowodami i rekomendacją; statusy Nowy/Przejrzany/Pominięty; historia audytów,
- Audit Score 0–100 (higiena konta, nie ROAS) z rozbiciem na obszary i uzasadnieniem kar; brak wyniku, gdy danych jest za mało,
- dashboard: stan połączenia, ostatnia synchronizacja, Audit Score, problemy krytyczne, tempo budżetu w miesiącu (z prognozą oznaczoną jako szacunek), ostrzeżenia o jakości danych, najważniejsze wyniki audytu,
- przygotowanie produkcji: skrypt wdrożenia i wycofania, utwardzenie WordPressa, blokada plików motywu, cron.

Penny Pincher nigdy nie zmienia kampanii na koncie Google Ads – rekomendacje wdraża użytkownik.

## Zakres etapu 3

- **Okres analizy** dla audytu i modułów: 30 / 90 dni, 6 / 12 miesięcy, własny zakres; zapamiętywany per użytkownik i projekt; historia przebiegów per okres; rozróżnienie: brak danych / brak aktywności (kampanie wyłączone – informacja, nie błąd) / niepełne dane / pełne dane.
- **Budget Leaks**: wyszukiwane hasła, słowa kluczowe, kampanie i jakość pomiaru; dla każdego problemu: kategoria, kampania/grupa, okres, wydatki, kliknięcia, konwersje Google i wartościowe, pewność, priorytet, uzasadnienie, działanie, SZACUNEK oszczędności; filtry i statusy.
- **Rekomendacje**: łączenie podobnych problemów, deduplikacja między modułami, priorytet z wpływu, pewności, istotności i kosztu; statusy Nowa / Do sprawdzenia / Zaakceptowana / Odrzucona / Wykonana (ręcznie), notatki, historia, nieaktualność.
- **Kontekst biznesowy** projektu (wszystkie pola opcjonalne): opis, produkty/usługi, usługi priorytetowe, grupa docelowa, klient idealny, niepożądani odbiorcy, lokalizacje, język, przewagi, średnia wartość, cele, landing page.
- **Klasyfikacja konwersji** bez zmian w Google Ads i bez ponownej synchronizacji; metryki Google i biznesowe osobno.
- **Keyword Opportunities**: nowe frazy z Opportunity Score 0–100 (heurystyka, nie ROI), propozycje wykluczeń z informacją, czy fraza jest już wykluczona.
- **DataForSEO**: pomysły słów kluczowych po zgodzie użytkownika, w miesięcznym limicie, w tle, z pamięcią podręczną.
- **Dashboard**: największe problemy budżetowe, nowe rekomendacje, nowe możliwości, szacowany potencjał optymalizacji – albo stan pusty, gdy analiza nie była uruchamiana.

Penny Pincher nadal niczego nie zmienia na koncie Google Ads: nie dodaje słów ani wykluczeń, nie zmienia budżetów ani ustawień konwersji.

## Zasady dotyczące danych

- Nie pokazujemy fikcyjnych wyników poza projektem demo.
- Brak wartości w eksporcie to „—”, nigdy 0.
- Nie dzielimy raportu okresowego na dni i nie sumujemy nakładających się eksportów.
- Koszty z raportu wyszukiwanych haseł nie są dodawane do kosztów z raportu kampanii.
- Każda metryka ma źródło (import lub API), ziarnistość i zakres dat; dla dni z danymi z API importy są pomijane.
- Dzień bez wierszy w oknie zsynchronizowanym z API oznacza brak aktywności (0); poza takim oknem brak danych to „—”.

## Plan dalszego rozwoju

1. ~~**Prompt 2** – Google Ads API, synchronizacja, silnik audytu, wdrożenie~~ (zrobione poza prawdziwym połączeniem z API i samym wdrożeniem – `docs/PROGRESS.md`).
2. ~~**Prompt 3** – Budget Leaks, rekomendacje, Keyword Opportunities, DataForSEO, rozbudowa audytu~~ (zrobione; DataForSEO i nowe zapytania Google Ads zweryfikowane na atrapach – `docs/PROGRESS.md`).
3. **Prompt 4** – Conversion Intelligence (import potwierdzonych leadów z CRM), planer kampanii, monitoring z alertami, cykliczne odświeżanie DataForSEO.
