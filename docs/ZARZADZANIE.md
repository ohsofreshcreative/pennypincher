# Zarządzanie – przegląd i konfiguracja

Flaga `pp2_management` (stała `PP2_MANAGEMENT` albo filtr `pennypincher/feature/pp2_management`), domyślnie wyłączona.
Z flagą adres sekcji `projects/{id}/administration/` (i skrót `/app/administration`) otwiera przegląd zamiast
przekierowania do Ustawień; w nawigacji pierwszy podwidok to „Przegląd i konfiguracja”. Bez flagi adres prowadzi
do `settings/` jak dotąd. Wszystkie dotychczasowe podwidoki działają bez zmian pod swoimi adresami:

| Podwidok | Adres | Zakres |
|---|---|---|
| Ustawienia projektu (dane, budżet, kontekst biznesowy) | `settings/` | projekt |
| Integracja Google Ads (OAuth, konto, synchronizacja, diagnostyka, tryb zapisu) | `google-ads/` | projekt |
| Zarządzanie kontem (operacje przez Change Center) | `management/` | projekt |
| Importy CSV (upload, mapowanie, historia) | `imports/` | projekt |
| Projekty (lista, tworzenie, demo) | `/app/projects/` | organizacje użytkownika |
| Członkowie i wykonawcy zmian | `/app/organizations/{id}/members` | organizacja |

## Onboarding integracji

Kroki liczone wyłącznie ze stanu w bazie i konfiguracji serwera (`ManagementOverviewService`) – strona nie łączy się
z Google Ads i nie czyta tokenów (tylko publiczne kolumny połączenia): konfiguracja serwera (nazwy brakujących
ustawień, bez wartości), połączenie z kontem Google, wybór konta, pierwsza synchronizacja, importy (opcjonalnie),
budżet i kontekst biznesowy (brak budżetu = brak, bez wartości domyślnej).

Przycisk „Diagnostyka połączenia” wysyła do istniejącej akcji `google-ads/diagnose` (uprawnienie `UpdateProject`)
z polem `return=administration` – wynik wraca na przegląd. Diagnostyka wykonuje tylko odczyty API; nie rozłącza
konta i nie usuwa autoryzacji (odświeżenie tokenu dostępu jak przy synchronizacji; jeśli Google odrzuci
autoryzację, połączenie jest oznaczane „Wymaga ponownej autoryzacji” – zachowanie istniejące, bez zmian).

## Ustawienia projektu / organizacji / serwera

- **Projekt** – nazwa, waluta, strefa czasowa, budżet, kontekst biznesowy, połączenie i tryb zmian projektu.
- **Organizacja** – rola, liczba członków i wykonawców zmian Google Ads; zarządzanie członkami tylko z `ManageMembers`.
- **Serwer** – wersja Google Ads API, tryb zapisu serwera, szyfrowanie, brakujące ustawienia: tylko odczyt
  (ustawiane w wp-config.php serwera, nigdy w aplikacji).

## Testy

`tests/Integration/AdministrationOverviewTest` – podwidoki osiągalne, brak ruchu sieciowego i zmian w tabelach,
brak sekretów w HTML, diagnostyka bez zmiany autoryzacji (ten sam refresh token, status aktywny), uprawnienia,
flaga, skrót, izolacja.

Rollback: wyłączenie `pp2_management`.
