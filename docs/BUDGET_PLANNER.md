# Budget Planner

Budget Planner to zakładka „Budżet i prognozy” w planie kampanii (`docs/CAMPAIGN_PLANNER.md`). Proponuje podział miesięcznego budżetu planu między kampanie i pokazuje orientacyjne prognozy. Nic nie zmienia na koncie Google Ads.

## Dane wejściowe kampanii (`BudgetAllocator::profile`)

Liczone ze słów kluczowych kampanii w planie:

- **historia konta**: kliknięcia, koszt i konwersje haseł z okresu analizy (pola `term_*` słów pochodzących z wyszukiwanych haseł),
- **potencjał**: średni score 5 najlepszych słów, lekko korygowany wolumenem (log),
- **wyniki**: współczynnik konwersji względem konta (od 20 kliknięć; bez historii – neutralnie 0,5, nowe frazy nie są z założenia lepsze),
- **niepewność**: udział słów bez historii i bez wysokiej pewności,
- **CPC**: z historii konta (koszt / kliknięcia), a gdy jej brak – mediana CPC z DataForSEO przeliczonego na walutę projektu (kurs NBP); bez tego – brak prognozy kliknięć,
- **dowód**: kampania „sprawdzona” ma ≥ 100 kliknięć i ≥ 3 konwersje w historii; pozostałe to „test”,
- **priorytet**: kampania brandowa albo zgodna z usługą priorytetową z kontekstu biznesowego.

## Podział (`BudgetAllocator::allocate`)

1. Budżet dzieli się na pulę **sprawdzonych** kampanii i pulę **testów** wg scenariusza (gdy brak sprawdzonych – całość to test z ostrzeżeniem).
2. W puli waga kampanii = priorytet × w1 + potencjał × w2 + wyniki × w3, pomnożona przez (1 − kara za niepewność × niepewność). Liczba kliknięć ani niski CPC same w sobie nie zwiększają udziału.
3. Limit udziału jednej kampanii (dywersyfikacja ryzyka), nadwyżka przechodzi na pozostałe.
4. Kwoty w pełnych jednostkach waluty; reszta z zaokrągleń trafia do największej kampanii – suma = budżet planu.

| Scenariusz | Udział sprawdzonych | Wagi priorytet / potencjał / wyniki | Kara za niepewność | Maks. udział kampanii |
|---|---|---|---|---|
| Ostrożny | 85% | 0,2 / 0,3 / 0,5 | 0,5 | 60% |
| Zrównoważony (domyślny) | 70% | 0,25 / 0,4 / 0,35 | 0,3 | 60% |
| Eksperymentalny | 50% | 0,25 / 0,55 / 0,2 | 0,1 | 70% |

Scenariusz jest konfigurowalny w planie („Dostosuj scenariusz”): wartości 0–1, wagi są normalizowane, minimalny limit udziału 20%.

## Prognozy (`BudgetAllocator::forecast`)

- miesięcznie i dziennie (miesiąc = 30,4 dnia),
- CPC jako zakres −20% / +30%, kliknięcia jako zakres z tego CPC,
- niepewność: niska (sprawdzona, ≥ 500 kliknięć), średnia (sprawdzona), wysoka (test),
- **konwersje i koszt pozyskania** tylko gdy pomiar jest wiarygodny **i** kampania ma udokumentowaną historię; przedział Wilsona (80%) dla współczynnika konwersji. W pozostałych przypadkach dokładnie: „Brak wystarczających danych do wiarygodnej prognozy kosztu pozyskania leada.”
- Za wiarygodny pomiar nie uznajemy samych konwersji Google Ads: wymagane są konwersje działań oznaczonych jako cel biznesowy, a mikrokonwersje (np. wizyty, czas na stronie, kliknięcia telefonu/e-maila) nie mogą stanowić większości (`CampaignPlanService::measurement`, 90 dni).

## Kontrola budżetu

- podsumowanie: budżet planu, przydzielono kampaniom, niewykorzystane, scenariusz,
- ostrzeżenie, gdy suma budżetów kampanii przekracza budżet planu (ręczna korekta jest dozwolona),
- ręczna korekta budżetu każdej kampanii i przycisk „Zastosuj proponowany podział”,
- informacja o działaniu budżetu dziennego w Google Ads: w pojedynczym dniu kampania może wydać do 2× budżetu dziennego, w miesiącu nie więcej niż ok. 30,4× budżet dzienny.

## Kod i testy

`Domain/Planner/BudgetAllocator.php`, `Services/Planner/BudgetPlannerService.php`; testy: `tests/Unit/PlannerDomainTest.php` (podział, scenariusze, prognozy, przedział Wilsona), `tests/Integration/CampaignPlannerTest.php` (zapis budżetu, zastosowanie podziału, przekroczenie).
