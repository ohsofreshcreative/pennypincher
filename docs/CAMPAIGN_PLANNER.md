# Campaign Planner

Campaign Planner (`/app/projects/{id}/planner/`) służy do projektowania kampanii Google Ads w aplikacji – **bez tworzenia czegokolwiek na koncie Google Ads**. Wynik to plan roboczy do przeglądu, zatwierdzenia i ręcznego wdrożenia (eksport CSV / dokument HTML do druku).

## Model

```
campaign_plans (plan)
 └─ plan_campaigns (kampanie: nazwa, typ search/brand, URL, notatki, budżet miesięczny)
     └─ plan_ad_groups (grupy reklam: nazwa, URL, klucz klastra)
         ├─ plan_keywords (słowa: fraza, dopasowanie, intencja, score, pewność, CPC + waluta, wolumen, źródło, historia z konta)
         └─ plan_ads (reklama RSA: nagłówki, opisy, ścieżki, URL, CTA, notatki)
plan_negatives (wykluczenia na poziomie planu / kampanii / grupy; unikalny scope_key + fraza + dopasowanie)
plan_events (historia planu)
```

Pola planu: nazwa, projekt, cel (z listy celów kontekstu biznesowego), usługa, grupa docelowa, lokalizacja, język, budżet miesięczny, scenariusz budżetu, status, data utworzenia, autor (`created_by`), data zmiany.

Statusy: **Szkic → Do przeglądu → Zatwierdzony → Zarchiwizowany**. Edytować można tylko szkic i plan do przeglądu; zatwierdzony i zarchiwizowany są tylko do odczytu (można je przywrócić do szkicu albo skopiować).

## Operacje

| Operacja | Gdzie | Uprawnienie |
|---|---|---|
| Pusty plan | lista planów | członek (`EditPlans`) |
| Plan z Keyword Opportunities (zaznaczone frazy lub całe klastry) | Keyword Opportunities → „Dodaj do planu kampanii” | członek |
| Dodanie fraz / klastrów do istniejącego planu | jw. (wybór planu) | członek |
| Kampanie i grupy: dodaj, zmień nazwę/URL, przenieś grupę, usuń | plan → Struktura | członek |
| Słowa: dodaj (jedno w linii), zmień dopasowanie, przenieś do innej grupy, usuń | plan → Struktura | członek |
| Wykluczenia: ręczne i z propozycji Keyword Opportunities, 3 poziomy | plan → Wykluczenia | członek |
| Reklamy: propozycja z kontekstu, edycja, walidacja | plan → Reklamy | członek |
| Kopia planu (nowy szkic z całą strukturą) | nagłówek planu | członek |
| Do przeglądu / Szkic | nagłówek planu | członek |
| Zatwierdzenie, archiwizacja | nagłówek planu | administrator (`ApprovePlans`) |
| Budżet planu, scenariusz, budżety kampanii, zastosowanie podziału | plan → Budżet i prognozy | administrator (`ManageBudget`) |
| Eksport CSV / HTML | nagłówek planu | każdy członek organizacji |

Dodanie z Keyword Opportunities: kampania wg usługi klastra (albo „Nowe frazy”), grupa reklam = klaster, dane frazy (score, pewność, CPC w oryginalnej walucie i przeliczone, wolumen, intencja, historia z konta) z najnowszej analizy. Fraza spoza analizy jest „ręczna” – bez wymyślonych metryk. Fraza dodana do planu dostaje status „W planie kampanii”; usunięta z planu wraca do „Zaakceptowana”. Ponowne dodanie tej samej frazy z tym samym dopasowaniem do grupy jest pomijane.

Limity Google Ads sprawdzane przy dodawaniu: słowo maks. 80 znaków i 10 słów; w planie maks. 100 słów na grupę.

## Typy dopasowania

- Propozycja (`MatchType::suggest`) nigdy nie jest przybliżona: **ścisłe** dla fraz z konwersjami w historii konta albo długich (≥ 5 słów), pozostałe **do wyrażenia**.
- Przybliżone można wybrać ręcznie. Gdy pomiar konwersji nie jest wiarygodny (`CampaignPlanService::measurement`: brak danych per działanie, brak konwersji celów biznesowych w 90 dniach albo ≥ 50% mikrokonwersji), przy słowie i w nagłówku planu wyświetla się ostrzeżenie.
- Przy każdym słowie: tekst w notacji Google (`[ścisłe]`, `"do wyrażenia"`, przybliżone), dopasowanie, intencja, score, szacowany CPC, wolumen, źródło, pewność.

## Wykluczenia i konflikty

Wykluczenia nie są dodawane automatycznie – użytkownik wybiera je ręcznie (lista propozycji z najnowszej analizy jest na zakładce Wykluczenia). `NegativeConflictChecker` sprawdza, czy wykluczenie zablokowałoby planowane słowa, według reguł Google Ads dla wykluczeń:

- przybliżone: wszystkie słowa wykluczenia występują w zapytaniu (dowolna kolejność),
- do wyrażenia: słowa w tej samej kolejności, mogą być inne słowa przed i po,
- ścisłe: dokładnie to samo zapytanie,
- bez wariantów (odmian, liczby mnogiej, literówek) – „dębowy” nie wyklucza „dębowa”,
- poziom planu dotyczy wszystkich kampanii, kampanii – jej grup, grupy – tylko jej słów.

Konflikt pojedynczego słowa blokującego kilka fraz ma wysoką wagę i podpowiedź (dłuższe wykluczenie albo ścisłe).

## Reklamy (RSA)

- Propozycja (`AdTemplates`) składa się wyłącznie z treści projektu: nazwa grupy, frazy grupy, usługi priorytetowe (+ lokalizacja planu), przewagi i opis firmy z kontekstu biznesowego, neutralne CTA. Nie dopisuje obietnic („najlepszy”, „gwarancja”, ceny), których nie ma w kontekście. Nie nadpisuje istniejącej reklamy.
- Walidacja (`AdValidator`): nagłówek ≤ 30, opis ≤ 90, ścieżka ≤ 15 znaków (znaki o podwójnej szerokości liczą się jako 2), 3–15 nagłówków, 2–4 opisy, ścieżka 2 wymaga ścieżki 1, URL http(s). Ostrzeżenia: wykrzyknik w nagłówku, duplikaty, słowa wielkimi literami, za mało nagłówków/opisów.
- Aplikacja **nie gwarantuje** akceptacji reklamy przez zasady Google Ads – sprawdza tylko limity i typowe problemy.

## Eksport

- **CSV**: UTF-8 z BOM, separator `;`, CRLF, każda komórka w cudzysłowie, stała liczba kolumn (`PlanExporter::COLUMNS`), kolumna „Typ wiersza” (Plan / Kampania / Grupa reklam / Słowo kluczowe / Nagłówek n / Opis n / Ścieżka URL / Wykluczenie). Ochrona przed wstrzyknięciem formuł: wartości zaczynające się od `=`, `+`, `-`, `@`, tabulatora lub CR dostają prefiks `'`. Kwoty z przecinkiem dziesiętnym, CPC z walutą oryginalną. Plik **nie jest** w formacie importu Google Ads Editor – to zestawienie do ręcznego wdrożenia.
- **HTML**: dokument do druku/PDF (struktura, słowa, wykluczenia, konflikty, reklamy, budżet i prognozy) z informacją, że plan nie został utworzony na koncie Google Ads.
- Eksport sprawdza członkostwo w organizacji projektu (osoba spoza organizacji dostaje 404).

## Kod

- Domena: `Domain/Planner/` (`MatchType`, `NegativeConflictChecker`, `AdValidator`, `AdTemplates`, `PlanStatus`, `BudgetAllocator`).
- Serwisy: `Services/Planner/CampaignPlanService` (struktura, słowa, wykluczenia, reklamy, pomiar), `BudgetPlannerService` (`docs/BUDGET_PLANNER.md`), `PlanExporter`.
- Repozytorium: `CampaignPlanRepository` (każde zapytanie zawężone do `project_id` i `plan_id`).
- Kontroler: `CampaignPlannerController`, widoki `resources/views/app/pages/planner/{index,show,export}.blade.php`.
- Testy: `tests/Unit/PlannerDomainTest.php`, `tests/Integration/CampaignPlannerTest.php`.
