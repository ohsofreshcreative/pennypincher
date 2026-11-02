# Nawigacja 2.0 – architektura informacji

Nowa nawigacja grupuje istniejące moduły w sześć sekcji głównych. **Żaden moduł nie zmienił adresu, kontrolera,
widoku ani uprawnień** – zmienia się tylko menu boczne. Definicja: `app/PennyPincher/Support/Navigation.php`
(`GROUPS`, `SECTIONS`, `ALIASES`), widok: `resources/views/app/partials/navigation.blade.php`,
testy: `tests/Unit/NavigationTest.php`, `tests/Integration/NavigationTest.php`.

## Włączanie i rollback

Flaga `pp2_navigation` (`App\PennyPincher\Support\Feature`), domyślnie **wyłączona**:

- `define('PP2_NAVIGATION', true);` w `wp-config.php` (środowisko), albo
- filtr `add_filter('pennypincher/feature/pp2_navigation', '__return_true');` (ma pierwszeństwo przed stałą).

Rollback: usunięcie stałej/filtra przywraca stare menu (18 pozycji). Trasy, dane i aliasy działają w obu wersjach.

Kolejne flagi przebudowy (`pp2_dashboard`, `pp2_campaigns`, `pp2_optimization`, `pp2_action_plan`, `pp2_conversions`,
`pp2_management`, `pp2_assistant`) korzystają z tego samego mechanizmu – stała `PP2_*` albo filtr `pennypincher/feature/{nazwa}`.

## Sekcje i podwidoki

Aktywna sekcja jest rozwinięta (podwidoki pod nią), pozostałe prowadzą do swojego pierwszego podwidoku.
Licznik nowych alertów jest przy „Plan działania” (gdy sekcja zwinięta) i przy „Monitoring”.

| Sekcja | Podwidok | Stary moduł | Adres (bez zmian) |
|---|---|---|---|
| Centrum dowodzenia | Przegląd | Przegląd | `/app/projects/{id}/` |
| Kampanie | Lista kampanii | Kampanie (+ szczegóły kampanii) | `campaigns/`, `campaigns/{campaign}/` |
| | Wyszukiwane hasła | Wyszukiwane hasła | `search-terms/` |
| | Planowanie | Campaign Planner (+ Budget Planner jako zakładka planu, eksport) | `planner/`, `planner/{plan}/?tab=…`, `planner/{plan}/export/{format}/` |
| Optymalizacja | Audyt | Audyt (+ historia przebiegów) | `audits/`, `audits/{run}/` |
| | Wycieki budżetu | Budget Leaks | `budget-leaks/` |
| | Słowa kluczowe | Keyword Opportunities (4 zakładki) | `keywords/?tab=opportunities\|clusters\|negatives\|dataforseo` |
| | Rekomendacje | Rekomendacje | `recommendations/` |
| Plan działania | Zadania | Action Plan | `action-plan/` |
| | Monitoring | Monitoring | `monitoring/` |
| | Zmiany na koncie | Change Center (szkice, zatwierdzenia, wdrożenia, historia) | `changes/`, `changes/{set}/` |
| Konwersje | Klasyfikacja | Konwersje | `conversions/` |
| | Konfiguracja w Google Ads | Conversion Management | `conversion-management/` |
| Zarządzanie | Ustawienia projektu | Ustawienia (+ kontekst biznesowy) | `settings/` |
| | Integracja Google Ads | Google Ads (OAuth, konto, synchronizacja) | `google-ads/`, `/app/google-ads/callback/` |
| | Zarządzanie kontem | Google Ads Management (+ kampania, grupa reklam) | `management/`, `management/campaigns/{id}/`, `management/ad-groups/{id}/` |
| | Importy | Importy (+ podgląd i podsumowanie) | `imports/`, `imports/{import}/` |
| | Projekty | Projekty (+ nowy projekt, demo, członkowie organizacji) | `/app/projects/`, `/app/projects/new/`, `/app/organizations/{id}/members/` |

Nazwa „Optymalizacja” bez „AI”: w kodzie są wyłącznie reguły deterministyczne (decyzja z etapu 2).
Członkowie organizacji nie mają osobnej pozycji – są pod Projektami (przycisk „Członkowie”), sekcja jest wtedy podświetlona.

## Aliasy sekcji głównych

Sekcje, które nie mają jeszcze własnego ekranu, mają adres przekierowujący (302) do pierwszego podwidoku z zachowaniem
parametrów zapytania. Działają niezależnie od flagi.

| Alias | Cel |
|---|---|
| `/app/projects/{id}/command-center/`, `/app/command-center/` | `/app/projects/{id}/` |
| `/app/projects/{id}/optimization/`, `/app/optimization/` | `audits/` (z flagą `pp2_optimization`: własny ekran, docs/OPTYMALIZACJA.md) |
| `/app/projects/{id}/administration/`, `/app/administration/` | `settings/` |

Sekcje „Kampanie”, „Plan działania” i „Konwersje” mają już adres modułu o tej samej nazwie (`campaigns/`, `action-plan/`,
`conversions/`). Stare skróty `/app/{moduł}/` (16) i przełącznik `/app/switch/?project=&section=` działają bez zmian.
Alias cudzego projektu daje 404 (`AccessService::project`).

## Uprawnienia

Każdy ekran GET projektu wymaga tylko `ViewProject` (rola co najmniej `member`), więc każdy członek organizacji może
otworzyć każdą pozycję menu – nawigacja nie ukrywa pozycji. Pozostałe uprawnienia z tabeli decydują o przyciskach
i akcjach w widoku oraz o POST-ach (403 przy zbyt niskiej roli). Minimalne role: `Domain/Access/Ability::minimumRole()`.

## Trasy i uprawnienia (stan kodu)

Wygenerowane statycznie z `routes/app.php` i kontrolerów (`Ability::…` w metodzie i jej pomocnikach).
„—” = tylko zalogowany użytkownik aplikacji (middleware `Authenticate`) albo trasa publiczna (logowanie).
Wszystkie POST-y przechodzą przez `VerifyNonce`.

| Metoda | Ścieżka | Kontroler | Uprawnienia w kodzie | Miejsce w nawigacji 2.0 |
|---|---|---|---|---|
| GET | `/app/login/` | `AuthController@show` | — | poza nawigacją |
| POST | `/app/login/` | `AuthController@login` | — | poza nawigacją |
| POST | `/app/logout/` | `AuthController@logout` | — | poza nawigacją |
| GET | `/app/` | `HomeController@index` | — | poza nawigacją |
| GET | `/app/switch/` | `HomeController@switchProject` | ViewProject | poza nawigacją |
| GET | `/app/projects/` | `ProjectsController@index` | tylko własne projekty (`AccessService::projects`) | Zarządzanie > Projekty |
| GET | `/app/projects/new/` | `ProjectsController@create` | CreateProject | Zarządzanie > Projekty |
| POST | `/app/projects/` | `ProjectsController@store` | CreateProject | Zarządzanie > Projekty |
| POST | `/app/projects/demo/` | `ProjectsController@demo` | `canCreateOrganizations` | Zarządzanie > Projekty |
| POST | `/app/organizations/` | `OrganizationsController@store` | `canCreateOrganizations` | Zarządzanie > Projekty |
| GET | `/app/organizations/{organization}/members/` | `OrganizationsController@members` | ViewProject | Zarządzanie > Projekty |
| POST | `/app/organizations/{organization}/members/` | `OrganizationsController@addMember` | ManageMembers | Zarządzanie > Projekty |
| POST | `/app/organizations/{organization}/members/{user}/remove/` | `OrganizationsController@removeMember` | ManageMembers | Zarządzanie > Projekty |
| GET | `/app/google-ads/callback/` | `GoogleAdsController@callback` | UpdateProject | poza nawigacją |
| GET | `/app/projects/{project}/` | `DashboardController@show` | ViewProject | Centrum dowodzenia > Przegląd |
| GET | `/app/projects/{project}/campaigns/` | `CampaignsController@index` | ViewProject | Kampanie > Lista kampanii |
| GET | `/app/projects/{project}/campaigns/{campaign}/` | `CampaignsController@show` | ViewProject | Kampanie > Lista kampanii |
| GET | `/app/projects/{project}/campaigns/{campaign}/optimization/` | `CampaignsController@optimization` | ViewProject | Kampanie > Lista kampanii (zakładka kampanii) |
| GET | `/app/projects/{project}/campaigns/{campaign}/planning/` | `CampaignsController@planning` | ViewProject | Kampanie > Lista kampanii (zakładka kampanii) |
| GET | `/app/projects/{project}/search-terms/` | `SearchTermsController@index` | ViewProject | Kampanie > Wyszukiwane hasła |
| GET | `/app/projects/{project}/imports/` | `ImportsController@index` | ViewProject | Zarządzanie > Importy |
| POST | `/app/projects/{project}/imports/` | `ImportsController@store` | ImportData | Zarządzanie > Importy |
| GET | `/app/projects/{project}/imports/{import}/` | `ImportsController@show` | ViewProject | Zarządzanie > Importy |
| POST | `/app/projects/{project}/imports/{import}/` | `ImportsController@confirm` | ImportData | Zarządzanie > Importy |
| POST | `/app/projects/{project}/imports/{import}/cancel/` | `ImportsController@cancel` | ImportData | Zarządzanie > Importy |
| GET | `/app/projects/{project}/google-ads/` | `GoogleAdsController@show` | ViewProject (przyciski: UpdateProject) | Zarządzanie > Integracja Google Ads |
| POST | `/app/projects/{project}/google-ads/connect/` | `GoogleAdsController@connect` | UpdateProject | Zarządzanie > Integracja Google Ads |
| POST | `/app/projects/{project}/google-ads/account/` | `GoogleAdsController@selectAccount` | UpdateProject | Zarządzanie > Integracja Google Ads |
| POST | `/app/projects/{project}/google-ads/sync/` | `GoogleAdsController@syncNow` | UpdateProject | Zarządzanie > Integracja Google Ads |
| POST | `/app/projects/{project}/google-ads/sync/{run}/retry/` | `GoogleAdsController@retry` | UpdateProject | Zarządzanie > Integracja Google Ads |
| POST | `/app/projects/{project}/google-ads/disconnect/` | `GoogleAdsController@disconnect` | UpdateProject | Zarządzanie > Integracja Google Ads |
| POST | `/app/projects/{project}/google-ads/diagnose/` | `GoogleAdsController@diagnose` | UpdateProject | Zarządzanie > Integracja Google Ads |
| GET | `/app/projects/{project}/optimization/` | `OptimizationController@index` | ViewProject (przycisk analizy: ImportData) | Optymalizacja > Przegląd analiz (pp2_optimization; bez flagi 302 do `audits/` jak alias) |
| GET | `/app/projects/{project}/assistant/` | `AssistantController@index` | ViewProject | Optymalizacja > Asystent (pp2_assistant; `?recommendation=` – wyjaśnienie; bez flagi 302 do `recommendations/`) |
| GET | `/app/projects/{project}/audits/` | `AuditsController@index` | ViewProject (przyciski: ImportData) | Optymalizacja > Audyt |
| POST | `/app/projects/{project}/audits/` | `AuditsController@store` | ImportData | Optymalizacja > Audyt |
| GET | `/app/projects/{project}/audits/{run}/` | `AuditsController@show` | ViewProject (przyciski: ImportData) | Optymalizacja > Audyt |
| POST | `/app/projects/{project}/audits/findings/{finding}/status/` | `AuditsController@updateStatus` | ImportData | Optymalizacja > Audyt |
| POST | `/app/projects/{project}/google-ads/backfill/` | `GoogleAdsController@backfill` | UpdateProject | Zarządzanie > Integracja Google Ads |
| POST | `/app/projects/{project}/analysis/` | `AnalysisController@store` | ImportData | Optymalizacja (przycisk „Uruchom analizę” w modułach analizy) |
| GET | `/app/projects/{project}/budget-leaks/` | `BudgetLeaksController@index` | ViewProject (przyciski: ImportData) | Optymalizacja > Wycieki budżetu |
| POST | `/app/projects/{project}/budget-leaks/{leak}/status/` | `BudgetLeaksController@updateStatus` | ImportData | Optymalizacja > Wycieki budżetu |
| GET | `/app/projects/{project}/recommendations/` | `RecommendationsController@index` | ViewProject (przyciski: ImportData) | Optymalizacja > Rekomendacje |
| POST | `/app/projects/{project}/recommendations/{recommendation}/status/` | `RecommendationsController@updateStatus` | ImportData | Optymalizacja > Rekomendacje |
| POST | `/app/projects/{project}/recommendations/{recommendation}/note/` | `RecommendationsController@updateNote` | ImportData | Optymalizacja > Rekomendacje |
| POST | `/app/projects/{project}/recommendations/{recommendation}/obsolete/` | `RecommendationsController@obsolete` | ImportData | Optymalizacja > Rekomendacje |
| GET | `/app/projects/{project}/keywords/` | `KeywordOpportunitiesController@index` | ViewProject (przyciski: RunPaidAnalysis, ImportData, ManageKeywords, EditPlans) | Optymalizacja > Słowa kluczowe |
| POST | `/app/projects/{project}/keywords/dataforseo/test/` | `KeywordOpportunitiesController@testDataForSeo` | RunPaidAnalysis | Optymalizacja > Słowa kluczowe |
| POST | `/app/projects/{project}/keywords/dataforseo/` | `KeywordOpportunitiesController@requestDataForSeo` | RunPaidAnalysis | Optymalizacja > Słowa kluczowe |
| POST | `/app/projects/{project}/keywords/dataforseo/limit/` | `KeywordOpportunitiesController@updateLimit` | RunPaidAnalysis | Optymalizacja > Słowa kluczowe |
| POST | `/app/projects/{project}/keywords/dataforseo/seeds/` | `KeywordOpportunitiesController@updateSeeds` | RunPaidAnalysis | Optymalizacja > Słowa kluczowe |
| POST | `/app/projects/{project}/keywords/status/` | `KeywordOpportunitiesController@updateStatus` | ManageKeywords | Optymalizacja > Słowa kluczowe |
| POST | `/app/projects/{project}/keywords/clusters/status/` | `KeywordOpportunitiesController@updateClusterStatus` | ManageKeywords | Optymalizacja > Słowa kluczowe |
| GET | `/app/projects/{project}/action-plan/` | `ActionPlanController@index` | ViewProject (przyciski: ManageKeywords, ImportData) | Plan działania > Zadania |
| GET | `/app/projects/{project}/action-plan/inbox/` | `ActionInboxController@index` | ViewProject | Plan działania > Kolejka (pp2_action_plan; bez flagi 302 do `action-plan/`) |
| GET | `/app/projects/{project}/action-plan/trace/{recommendation}/` | `ActionInboxController@trace` | ViewProject | Plan działania > Kolejka (ścieżka rekomendacji) |
| POST | `/app/projects/{project}/action-plan/generate/` | `ActionPlanController@generate` | ImportData | Plan działania > Zadania |
| POST | `/app/projects/{project}/action-plan/{item}/status/` | `ActionPlanController@updateStatus` | ManageKeywords | Plan działania > Zadania |
| POST | `/app/projects/{project}/action-plan/{item}/note/` | `ActionPlanController@updateNote` | ManageKeywords | Plan działania > Zadania |
| GET | `/app/projects/{project}/monitoring/` | `MonitoringController@index` | ViewProject (przyciski: ManageAlerts) | Plan działania > Monitoring |
| POST | `/app/projects/{project}/monitoring/check/` | `MonitoringController@check` | ManageAlerts | Plan działania > Monitoring |
| POST | `/app/projects/{project}/monitoring/preferences/` | `MonitoringController@preferences` | ViewProject | Plan działania > Monitoring |
| POST | `/app/projects/{project}/monitoring/{alert}/status/` | `MonitoringController@updateStatus` | ManageAlerts | Plan działania > Monitoring |
| GET | `/app/projects/{project}/planner/` | `CampaignPlannerController@index` | ViewProject (przyciski: EditPlans) | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/` | `CampaignPlannerController@store` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/from-keywords/` | `CampaignPlannerController@fromKeywords` | EditPlans | Kampanie > Planowanie |
| GET | `/app/projects/{project}/planner/{plan}/` | `CampaignPlannerController@show` | ViewProject (przyciski: EditPlans, ApprovePlans, ManageBudget) | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/` | `CampaignPlannerController@update` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/status/` | `CampaignPlannerController@status` | EditPlans, ApprovePlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/copy/` | `CampaignPlannerController@copy` | EditPlans | Kampanie > Planowanie |
| GET | `/app/projects/{project}/planner/{plan}/export/{format}/` | `CampaignPlannerController@export` | ViewProject | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/campaigns/` | `CampaignPlannerController@addCampaign` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/campaigns/{campaign}/` | `CampaignPlannerController@updateCampaign` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/campaigns/{campaign}/delete/` | `CampaignPlannerController@deleteCampaign` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/campaigns/{campaign}/budget/` | `CampaignPlannerController@campaignBudget` | ManageBudget | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/groups/` | `CampaignPlannerController@addGroup` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/groups/{group}/` | `CampaignPlannerController@updateGroup` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/groups/{group}/delete/` | `CampaignPlannerController@deleteGroup` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/groups/{group}/keywords/` | `CampaignPlannerController@addKeywords` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/groups/{group}/ad/` | `CampaignPlannerController@saveAd` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/groups/{group}/ad/suggest/` | `CampaignPlannerController@suggestAd` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/keywords/{keyword}/` | `CampaignPlannerController@updateKeyword` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/negatives/` | `CampaignPlannerController@addNegatives` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/negatives/{negative}/delete/` | `CampaignPlannerController@deleteNegative` | EditPlans | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/budget/` | `CampaignPlannerController@updateBudget` | ManageBudget | Kampanie > Planowanie |
| POST | `/app/projects/{project}/planner/{plan}/budget/apply/` | `CampaignPlannerController@applyBudget` | ManageBudget | Kampanie > Planowanie |
| GET | `/app/projects/{project}/changes/` | `ChangeCenterController@index` | ViewProject (przyciski: PrepareChanges) | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/` | `ChangeCenterController@store` | PrepareChanges | Plan działania > Zmiany na koncie |
| GET | `/app/projects/{project}/changes/{set}/` | `ChangeCenterController@show` | ViewProject (przyciski: PrepareChanges, ApproveChanges) | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/operations/{operation}/` | `ChangeCenterController@updateOperation` | PrepareChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/operations/{operation}/delete/` | `ChangeCenterController@deleteOperation` | PrepareChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/read-state/` | `ChangeCenterController@readState` | PrepareChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/submit/` | `ChangeCenterController@submit` | PrepareChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/approve/` | `ChangeCenterController@approve` | ApproveChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/reject/` | `ChangeCenterController@reject` | ApproveChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/deploy/` | `ChangeCenterController@deploy` | ExecuteChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/cancel/` | `ChangeCenterController@cancel` | PrepareChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/reconcile/` | `ChangeCenterController@reconcile` | ApproveChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/rollback/` | `ChangeCenterController@rollback` | PrepareChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/changes/{set}/retry/` | `ChangeCenterController@retry` | PrepareChanges | Plan działania > Zmiany na koncie |
| POST | `/app/projects/{project}/recommendations/{recommendation}/prepare-change/` | `ChangeCenterController@fromRecommendation` | PrepareChanges | Optymalizacja > Rekomendacje |
| POST | `/app/projects/{project}/budget-leaks/{leak}/prepare-change/` | `ChangeCenterController@fromLeak` | PrepareChanges | Optymalizacja > Wycieki budżetu |
| POST | `/app/projects/{project}/action-plan/{item}/prepare-change/` | `ChangeCenterController@fromActionItem` | PrepareChanges | Plan działania > Zadania |
| POST | `/app/projects/{project}/keywords/negatives/prepare-change/` | `ChangeCenterController@fromNegatives` | PrepareChanges | Optymalizacja > Słowa kluczowe |
| POST | `/app/projects/{project}/planner/{plan}/deploy/` | `ChangeCenterController@fromPlan` | PrepareChanges | Kampanie > Planowanie |
| GET | `/app/projects/{project}/management/` | `GoogleAdsManagementController@index` | ViewProject (przyciski: ManageWriteAccess, PrepareChanges, ApproveChanges) | Zarządzanie > Zarządzanie kontem |
| GET | `/app/projects/{project}/management/campaigns/{campaign}/` | `GoogleAdsManagementController@campaign` | ViewProject (przyciski: PrepareChanges, ManageWriteAccess, ApproveChanges) | Zarządzanie > Zarządzanie kontem |
| GET | `/app/projects/{project}/management/ad-groups/{group}/` | `GoogleAdsManagementController@adGroup` | ViewProject (przyciski: PrepareChanges, ManageWriteAccess, ApproveChanges) | Zarządzanie > Zarządzanie kontem |
| POST | `/app/projects/{project}/management/read/` | `GoogleAdsManagementController@read` | PrepareChanges | Zarządzanie > Zarządzanie kontem |
| POST | `/app/projects/{project}/management/campaigns/new/` | `GoogleAdsManagementController@newCampaign` | PrepareChanges | Zarządzanie > Zarządzanie kontem |
| POST | `/app/projects/{project}/management/campaigns/{campaign}/change/` | `GoogleAdsManagementController@campaignChange` | PrepareChanges | Zarządzanie > Zarządzanie kontem |
| POST | `/app/projects/{project}/management/ad-groups/{group}/change/` | `GoogleAdsManagementController@adGroupChange` | PrepareChanges | Zarządzanie > Zarządzanie kontem |
| POST | `/app/projects/{project}/management/write-access/` | `GoogleAdsManagementController@writeAccess` | ManageWriteAccess | Zarządzanie > Zarządzanie kontem |
| POST | `/app/projects/{project}/management/write-access/check/` | `GoogleAdsManagementController@accountCheck` | ManageWriteAccess | Zarządzanie > Zarządzanie kontem |
| POST | `/app/projects/{project}/management/executor/` | `GoogleAdsManagementController@executor` | ManageWriteAccess | Zarządzanie > Zarządzanie kontem |
| GET | `/app/projects/{project}/conversion-management/` | `ConversionManagementController@index` | ViewProject (przyciski: PrepareChanges) | Konwersje > Konfiguracja w Google Ads |
| POST | `/app/projects/{project}/conversion-management/read/` | `ConversionManagementController@read` | PrepareChanges | Konwersje > Konfiguracja w Google Ads |
| POST | `/app/projects/{project}/conversion-management/create/` | `ConversionManagementController@create` | PrepareChanges | Konwersje > Konfiguracja w Google Ads |
| POST | `/app/projects/{project}/conversion-management/{action}/update/` | `ConversionManagementController@update` | PrepareChanges | Konwersje > Konfiguracja w Google Ads |
| POST | `/app/projects/{project}/conversion-management/goal/` | `ConversionManagementController@goal` | PrepareChanges | Konwersje > Konfiguracja w Google Ads |
| POST | `/app/projects/{project}/conversion-management/health/` | `ConversionManagementController@health` | PrepareChanges | Konwersje > Konfiguracja w Google Ads |
| GET | `/app/projects/{project}/conversions/` | `ConversionsController@index` | ViewProject (przyciski: UpdateProject) | Konwersje > Klasyfikacja |
| GET | `/app/projects/{project}/conversions/center/` | `ConversionCenterController@index` | ViewProject | Konwersje > Centrum konwersji (pp2_conversions; bez flagi 302 do `conversions/` z zachowanym okresem) |
| POST | `/app/projects/{project}/conversions/` | `ConversionsController@update` | UpdateProject | Konwersje > Klasyfikacja |
| POST | `/app/projects/{project}/settings/context/` | `SettingsController@updateContext` | UpdateProject | Zarządzanie > Ustawienia projektu |
| GET | `/app/projects/{project}/settings/` | `SettingsController@edit` | ViewProject (przyciski: UpdateProject) | Zarządzanie > Ustawienia projektu |
| GET | `/app/projects/{project}/administration/` | `AdministrationController@index` | ViewProject | Zarządzanie > Przegląd i konfiguracja (pp2_management; bez flagi 302 do `settings/` z zachowanymi parametrami) |
| POST | `/app/projects/{project}/settings/` | `SettingsController@update` | UpdateProject | Zarządzanie > Ustawienia projektu |
| GET | `/app/projects/{project}/{group}/` | `HomeController@group` | ViewProject | aliasy sekcji głównych |
| GET | `/app/{section}/` | `HomeController@shortcut` | — | poza nawigacją |
| ANY | `/app/{any}/` | `HomeController@notFound` | — | poza nawigacją |

Razem: 131 tras (przed przebudową 122). Nowe: alias sekcji głównej w projekcie (`HomeController@group`), zakładki kampanii `optimization/` i `planning/` (docs/KAMPANIE.md) `optimization/` (docs/OPTYMALIZACJA.md) oraz `action-plan/inbox/` i `action-plan/trace/{id}/` (docs/PLAN_DZIALANIA.md), `conversions/center/` (docs/CENTRUM_KONWERSJI.md), `administration/` (docs/ZARZADZANIE.md), `assistant/` (docs/ASYSTENT.md); skrót `/app/{section}/` przyjmuje dodatkowo 3 aliasy.
