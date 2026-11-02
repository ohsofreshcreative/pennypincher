# Plan działania 2.0 – wspólna kolejka

Flaga `pp2_action_plan` (stała `PP2_ACTION_PLAN` albo filtr `pennypincher/feature/pp2_action_plan`), domyślnie wyłączona.
Z flagą sekcja Plan działania ma pierwszy podwidok „Kolejka” (`action-plan/inbox/`); bez flagi adres przekierowuje
do dotychczasowego Action Plan. Action Plan, Monitoring i Change Center działają bez zmian pod swoimi adresami.

## Zakładki = istniejące statusy (bez nowych statusów)

| Zakładka | Rekomendacje | Zadania Action Plan | Zestawy Change Center | Alerty |
|---|---|---|---|---|
| Do oceny | Nowa, Do sprawdzenia | – | Szkic, Do przeglądu | – |
| Zaakceptowane | Zaakceptowana | Do zrobienia | Zatwierdzony, Walidacja, Gotowy do wdrożenia | – |
| W toku | – | W trakcie, **Zablokowane** | Wdrażanie, Wymaga uzgodnienia, Błąd wdrożenia | – |
| Wykonane | Wykonana | Wykonane | Wdrożony, Częściowo wdrożony | – |
| Odrzucone | Odrzucona | Odrzucone | Anulowany | – |
| Monitoring i historia | – | – | – (efekty: Monitoring) | Nowy, Potwierdzony |

Mapowanie: `Domain/ActionPlan/Inbox::MAP`. Otwarte zakładki pokazują tylko aktualne rekomendacje i zadania
(jak domyślne widoki modułów). „Obserwowane” z dokumentu UX nie istnieje jako status – nie jest dodawane (decyzja).
Kolejka jest tylko do odczytu: status zmienia się w module źródłowym (link z `open=` i kotwicą).
„Wykonane” dla rekomendacji/zadań ustawia wyłącznie użytkownik – wdrożenie zmiany na koncie go nie zmienia.

## Ścieżka rekomendacji (`action-plan/trace/{id}/`)

1. Rekomendacja: źródło, okres, status i historia decyzji (osoba, czas, notatka).
2. Zadania Action Plan powiązane z rekomendacją (historia statusów).
3. Zestawy Change Center (źródło: rekomendacja albo jej zadanie): kto zatwierdził i kiedy, kto zlecił wdrożenie,
   operacje i ich wynik, kontakt z Google Ads API (rodzaj, wynik, request-id, kod błędu), weryfikacja odczytem.
4. Wynik: porównanie przed/po z `ChangeEffectsService` (dopiero po pełnych dniach; „porównanie, nie dowód przyczynowości”).
5. Alerty monitoringu powiązane z rekomendacją.

## Zapis do Google Ads

Bez zmian: wyłącznie Change Center – podgląd „przed / po” → zatwierdzenie (odcisk wersji) → świadome polecenie
wdrożenia z potwierdzeniem → worker (ponowna kontrola zgody, trybu, allowlisty, konfliktów, blokady emisji) →
wynik API → weryfikacja odczytem → log. Przed etapem naprawiono bezpieczniki (docs/GOOGLE_ADS_WRITE_TESTING.md §3):
blokada zmian zwiększających wydatki aktywnych kampanii, wyjście ze stanów „Wymaga uzgodnienia”/„Walidacja”/kolejka,
konflikt rozstrzygany ponownym odczytem.

## Testy

`tests/Integration/ActionPlanInboxTest` (pełny cykl na atrapie konta, liczniki = listy, flaga, izolacja),
`tests/Integration/WriteSafetyFixesTest`, `tests/Unit/ServingGuardSpendTest`.

Rollback: wyłączenie `pp2_action_plan`.
