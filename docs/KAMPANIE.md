# Sekcja Kampanie (Penny Pincher 2.0)

Flaga `pp2_campaigns` (stała `PP2_CAMPAIGNS` albo filtr `pennypincher/feature/pp2_campaigns`), domyślnie wyłączona.
Sekcja w menu (`pp2_navigation`): Lista kampanii, Wyszukiwane hasła, Planowanie – istniejące moduły pod starymi adresami.

## Kampania w kontekście

Na szczegółach kampanii (i na liście haseł filtrowanej po kampanii) flaga dodaje zakładki
(`<x-pp.campaign-tabs>`), które przenoszą identyfikator kampanii między widokami:

| Zakładka | Adres | Źródło |
|---|---|---|
| Wyniki | `campaigns/{id}/` | dotychczasowe szczegóły kampanii (KPI, wykresy dzienne, źródła importów, top hasła) |
| Wyszukiwane hasła | `search-terms/?campaign={id}` | pełny moduł haseł z filtrem kampanii – wszystkie filtry, sortowanie i paginacja bez zmian |
| Optymalizacja | `campaigns/{id}/optimization/` (nowa, tylko odczyt) | wycieki tej kampanii z ostatniej analizy Budget Leaks i jej aktywne rekomendacje; zmiana statusów w modułach źródłowych (linki z `?campaign=` i kotwicą) |
| Planowanie | `campaigns/{id}/planning/` (nowa, tylko odczyt) | przejście do Google Ads Management dla tej kampanii (zmiany wyłącznie przez Change Center) oraz plany Campaign Planner |

Zakres dat jest wspólny dla Wyników i Haseł (`DateRangeRequest`, zapamiętany per projekt). Wycieki i rekomendacje
mają okres swojej analizy – pokazany przy liście, link otwiera Budget Leaks z tym okresem i filtrem kampanii.

## Ograniczenia (świadomie)

- Plany Campaign Plannera nie są powiązane z istniejącymi kampaniami (nie ma takiej relacji w danych) –
  zakładka Planowanie pokazuje wszystkie plany projektu i to mówi.
- Lista wyszukiwanych haseł pozostaje raportem tylko do odczytu; akcje na hasłach (wykluczenia) są w
  Słowa kluczowe › Propozycje wykluczeń i w Change Center.
- Zarządzanie kampanią wymaga połączenia Google Ads i identyfikatora kampanii z API/eksportu.

## Parytet

`tests/Integration/CampaignWorkspaceTest`: te same KPI kampanii, tabela haseł (z filtrem i sortowaniem) i lista
kampanii z flagą i bez; zakładki z aktywną pozycją i zachowanym filtrem kampanii; Optymalizacja pokazuje dokładnie
wycieki/rekomendacje danej kampanii; 404 dla kampanii z innego projektu.

Rollback: wyłączenie `pp2_campaigns` (nowe adresy zakładek dalej działają, ale nie są nigdzie linkowane).
