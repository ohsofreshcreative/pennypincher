# Optymalizacja – jeden punkt wejścia (Penny Pincher 2.0)

Flaga `pp2_optimization` (stała `PP2_OPTIMIZATION` albo filtr `pennypincher/feature/pp2_optimization`), domyślnie wyłączona.
Adres: `/app/projects/{id}/optimization/` (`OptimizationController@index`, widok `app.pages.optimization`).
Bez flagi adres działa jak dotychczasowy alias sekcji: 302 do `audits/` z zachowaniem parametrów.
Z flagą (i `pp2_navigation`) sekcja Optymalizacja ma pierwszy podwidok „Przegląd analiz” (`Navigation::groups()`).

## Przepływ

1. **Okres** – wspólny selektor w nagłówku (`AnalysisPeriodRequest`, zapamiętany per użytkownik i projekt; ten sam co w modułach).
2. **Tryb** – wyłącznie „pełna analiza” (istniejący `AnalysisService::run`: audyt → Budget Leaks → słowa kluczowe →
   rekomendacje → Action Plan). Tryby Budżet/wykluczenia, Rozwój, Pomiar z dokumentu UX **nie są zaimplementowane**
   (decyzja: wspólny punkt wejścia bez nowych trybów) i nie są pokazywane jako kontrolki.
3. **Uruchomienie** – istniejący formularz `partials/analysis/run-form` (POST `analysis`, nonce, `Ability::ImportData`),
   `return=optimization` wraca na ten ekran z okresem.
4. **Zakres wyników** – całe konto albo kampania (`?campaign=`, tylko kampanie projektu). Kampania zawęża wycieki i
   rekomendacje; audyt i słowa kluczowe są liczone dla konta i to jest napisane na ekranie.
5. **Wyniki** – karty modułów dla dokładnie tego okresu (te same daty co przebieg): Audit Score i liczby wyników wg
   ważności, wycieki wg priorytetu + SZACUNEK oszczędności (`BudgetLeakService::savings`), frazy i wykluczenia do oceny,
   liczba rekomendacji. Brak jakiejkolwiek analizy okresu = jeden komunikat z jednym krokiem.
6. **Dowody** – „Wyniki reguł” (po 5 najważniejszych z audytu i wycieków) linkują do `audits/{run}#finding-…` i
   `budget-leaks?…#leak-…`; „Rekomendacje do decyzji” do `recommendations?…&open=…`. Statusy zmienia się wyłącznie
   w modułach źródłowych.

## Twarde wyniki a hipotezy

- Wyniki reguł audytu (23 reguły, `RuleRegistry::VERSION` 1.1 – bez zmian) i Budget Leaks są deterministyczne.
- Hipotezami/szacunkami są: propozycje słów kluczowych, kwoty oszczędności i wpływu rekomendacji (oznaczone „SZACUNEK”).
- W aplikacji nie ma modelu AI – ekran mówi to wprost. Asystent jest osobnym etapem roadmapy.

## Parytet

`tests/Integration/OptimizationHubTest`: przekierowanie bez flagi, jeden komunikat w pustym okresie, liczby i linki
zgodne z repozytoriami modułów (także po zawężeniu do kampanii), powrót po uruchomieniu analizy, nawigacja z flagą,
404 dla cudzego projektu. Moduły źródłowe renderują się identycznie z flagą i bez.

Rollback: wyłączenie `pp2_optimization`.
