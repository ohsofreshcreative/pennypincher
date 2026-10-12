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
| Keyword Intelligence 2.0 (edycja fraz źródłowych, klastry, Opportunity Score 2.0 z pewnością, statusy fraz, waluty NBP) | 4 | gotowe |
| Klasyfikacja konwersji (lead / potencjalny kontakt / sprzedaż / mikro…) | 3 | gotowe (podstawa Conversion Intelligence) |
| Recommendations | 3 | gotowe (statusy, notatki, historia, deduplikacja) |
| Integracja DataForSEO | 3 | zaimplementowana, przetestowana tylko na atrapie – wymaga danych dostępowych |
| Campaign Planner + Budget Planner | 4 | gotowe (plany wyłącznie w aplikacji – bez tworzenia czegokolwiek w Google Ads) |
| Action Plan | 4 | gotowe |
| Monitoring i alerty (centrum powiadomień, e-mail opt-in) | 4 | gotowe; e-mail wymaga działającej poczty `wp_mail` na serwerze |
| Conversion Intelligence (import potwierdzonych leadów z CRM) | 5 | planowane |

Wszystkie moduły z tabeli (poza planowanymi) są w nawigacji aplikacji.

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

Penny Pincher nie zmienia kampanii samodzielnie – od Promptu 5 zmiany są możliwe wyłącznie przez Change Center (przegląd, zatwierdzenie, świadome wdrożenie; domyślnie zapis wyłączony).

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

## Zakres etapu 4

- **Keyword Intelligence 2.0**: edycja fraz źródłowych DataForSEO (usuń, dodaj, popraw, przywróć automatyczne / ostatnio użyte, licznik i szacunek kosztu, maks. 20); automatyczne frazy z kontekstu biznesowego i konwertujących haseł z odrzucaniem fraz w innym języku, nazw firm, marek konkurencji, zapytań informacyjnych i darmowych, fraz spoza oferty (własna marka dozwolona, ręczne frazy tylko z ostrzeżeniem); klastry fraz o tym samym znaczeniu i intencji; Opportunity Score 2.0 (dopasowanie do oferty, intencja, grupa docelowa, historia Google Ads, pokrycie, CPC, wolumen, konkurencja, jakość danych) z osobną pewnością i wyjaśnieniem; statusy fraz (Nowa, Do sprawdzenia, Zaakceptowana, Odrzucona, W planie kampanii, Wykorzystana) trwałe między analizami; CPC w oryginalnej walucie z przeliczeniem kursem NBP.
- **Campaign Planner** (`docs/CAMPAIGN_PLANNER.md`): plany → kampanie → grupy reklam → słowa kluczowe, z Keyword Opportunities albo od zera; dopasowania (bez automatycznego przybliżonego), wykluczenia na 3 poziomach z kontrolą konfliktów, reklamy RSA z walidacją, statusy planu, kopia, eksport CSV i HTML.
- **Budget Planner** (`docs/BUDGET_PLANNER.md`): podział budżetu wg priorytetów, historii, jakości konwersji, potencjału, CPC i niepewności; scenariusze Ostrożny / Zrównoważony / Eksperymentalny; prognozy w zakresach; koszt pozyskania leada tylko przy wiarygodnym pomiarze i historii.
- **Action Plan**: lista działań z audytu, Budget Leaks, rekomendacji i słów kluczowych, w kolejności z zależnościami (najpierw dane i pomiar), z priorytetem, pewnością, nakładem, efektem, statusami, notatkami i historią; „Wykonane” wyłącznie ręcznie.
- **Monitoring** (`docs/MONITORING.md`): alerty o wzroście CPC, spadku CTR, wydatkach bez konwersji, braku aktywności, tempie budżetu, spadku leadów, pogorszeniu pomiaru, problemach synchronizacji, nieaktualnych danych i nowych problemach audytu – z ochroną przed fałszywymi alarmami; statusy, wyciszanie, historia, licznik nowych alertów, e-mail tylko po włączeniu.
- **SaaS**: izolacja organizacji we wszystkich nowych modułach, nowe uprawnienia (edycja i zatwierdzanie planów, budżet, płatne analizy, alerty), kontrola kosztów DataForSEO per projekt i organizacja z blokadą równoległych zleceń, paginacja i indeksy.

Penny Pincher nadal niczego nie zmienia na koncie Google Ads – plany kampanii, budżety, wykluczenia i reklamy wdraża użytkownik.

## Zasady dotyczące danych

- Nie pokazujemy fikcyjnych wyników poza projektem demo.
- Brak wartości w eksporcie to „—”, nigdy 0. Brak CPC lub wolumenu to „Brak danych”, nie zero i nie „brak popytu”.
- Kwot w różnych walutach nie porównujemy bez kursu (kurs, data i źródło przy każdej przeliczonej wartości).
- Prognozy są zakresami i szacunkami; konwersji i kosztu pozyskania nie prognozujemy bez wiarygodnego pomiaru.
- Nie dzielimy raportu okresowego na dni i nie sumujemy nakładających się eksportów.
- Koszty z raportu wyszukiwanych haseł nie są dodawane do kosztów z raportu kampanii.
- Każda metryka ma źródło (import lub API), ziarnistość i zakres dat; dla dni z danymi z API importy są pomijane.
- Dzień bez wierszy w oknie zsynchronizowanym z API oznacza brak aktywności (0); poza takim oknem brak danych to „—”.

## Plan dalszego rozwoju

1. ~~**Prompt 2** – Google Ads API, synchronizacja, silnik audytu, wdrożenie~~ (zrobione poza prawdziwym połączeniem z API i samym wdrożeniem – `docs/PROGRESS.md`).
2. ~~**Prompt 3** – Budget Leaks, rekomendacje, Keyword Opportunities, DataForSEO, rozbudowa audytu~~ (zrobione; DataForSEO i nowe zapytania Google Ads zweryfikowane na atrapach – `docs/PROGRESS.md`).
3. ~~**Prompt 4** – Keyword Intelligence 2.0, Campaign Planner, Budget Planner, Action Plan, Monitoring, fundamenty SaaS~~ (zrobione – `docs/PROGRESS.md`).
4. Dalej: Conversion Intelligence (import potwierdzonych leadów z CRM), cykliczne odświeżanie DataForSEO, eksport w formacie Google Ads Editor po weryfikacji formatu, model semantyczny dla klastrów.

## Prompt 5 – Google Ads Management, Change Center, One-Click Optimization

- **Change Center**: zestawy zmian ze statusami (Szkic → Do przeglądu → Zatwierdzony → Walidacja → Gotowy → Wdrażanie → Wdrożony / Częściowo / Błąd / Wymaga uzgodnienia / Anulowany), podgląd przed/po, ryzyko, wpływ na budżet, edycja, odrzucenie, historia, próby API, efekty, cofnięcie jako nowy szkic.
- **Google Ads Management**: kampanie w sieci wyszukiwania (tworzenie wstrzymanych, nazwa, status, budżet, sieci, strategia, lokalizacje, języki), grupy, słowa (z kontrolą duplikatów i konfliktów, zmiana dopasowania jako zamiana), wykluczenia, RSA. Pozostałe typy kampanii tylko do odczytu.
- **Conversion Management**: konfiguracja konwersji Google Ads obok klasyfikacji PP, tworzenie konwersji WEBPAGE, edycja dozwolonych pól, cele konta i kampanii, Conversion Health Check (np. „30 sekund” jako główna).
- „Przygotuj zmianę” w Rekomendacjach, Budget Leaks, Action Plan, Keyword Opportunities, Campaign Plannerze (wdrożenie zatwierdzonego planu) i Monitoringu.
- Tryby READ_ONLY / PREPARE_ONLY (domyślny) / WRITE_ENABLED; zapis tylko przez worker po zatwierdzeniu.
