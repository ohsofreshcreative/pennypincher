# Asystent

Flaga `pp2_assistant` (stała `PP2_ASSISTANT` albo filtr `pennypincher/feature/pp2_assistant`), domyślnie wyłączona.
Z flagą sekcja Optymalizacja ma ostatni podwidok „Asystent” (`assistant/`); bez flagi adres prowadzi do Rekomendacji
(z `?recommendation=` – do otwartej rekomendacji).

## Co robi

- **Co warto zrobić dzisiaj** – TOP 3 aktualnych rekomendacji do oceny wg `priority_score`, zestawy Change Center
  czekające na zgodę (do przeglądu, gotowe do wdrożenia, wymagające uzgodnienia), otwarte alerty, aktualność danych
  (ostatnia analiza i synchronizacja). Bez analizy – jeden krok konfiguracji.
- **Wyjaśnienie rekomendacji** (`?recommendation={id}`) – co wykryto, na podstawie czego (źródło, okres, pewność,
  dowody zapisane przez regułę), szacunek wyraźnie oznaczony, zastrzeżenia (niska pewność, nieaktualność, brak dowodów)
  i następny krok wg statusu (`Domain/Assistant/Explainer`).

## Zasady

- **Asystent nie jest źródłem liczb** – przytacza wyłącznie opis, działanie, dowody i szacunek zapisane przez reguły
  (audyt, Budget Leaks, analizy) oraz daty okresu; test jednostkowy sprawdza, że w wyjaśnieniu nie pojawia się żadna
  liczba spoza tych danych.
- **Tylko odczyt** – brak formularzy zapisu i zmian statusów. Następny krok prowadzi do modułu źródłowego; zmiany
  na koncie są oznaczone „wymaga Twojej zgody” i przechodzą przez Change Center (szkic → zatwierdzenie → polecenie
  wdrożenia).
- **Bez AI (fallback = jedyny tryb)** – wyjaśnienia tworzą reguły (`Explainer::ENGINE`). Zewnętrzny model AI nie jest
  podłączony: wymagałby decyzji o dostawcy, kluczu w konfiguracji serwera i zgodzie na przekazywanie danych klienta.
- **Bez nowego harmonogramu** – dane odświeża istniejący `pp:sync:work` (systemowy cron): synchronizacja → analiza →
  monitoring (idempotentny per przebieg). WP-Cron ma jedno zdarzenie (`pennypincher_prune_staged_imports`).

## Testy

`tests/Unit/AssistantExplainerTest` (brak nowych liczb, następny krok i zgoda wg statusu, zastrzeżenia),
`tests/Integration/AssistantTest` (tylko odczyt – stan tabel i cron bez zmian, TOP 3, przepływ akceptacja → szkic →
zatwierdzenie bez zapisu do Google Ads, fallback bez flagi, izolacja; harmonogramy bez duplikatów).

Rollback: wyłączenie `pp2_assistant`.
