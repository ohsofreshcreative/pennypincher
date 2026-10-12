# Monitoring i alerty

Monitoring (`/app/projects/{id}/monitoring/`) wykrywa niepokojące zmiany w danych projektu i zapisuje je jako alerty. Działa wyłącznie na danych z bazy – nie odpytuje Google Ads ani DataForSEO i niczego nie zmienia na koncie.

## Kiedy działa

- automatycznie po każdej **udanej synchronizacji** Google Ads (`SyncService::afterCompletion` → analiza → `MonitoringService::run(…, 'sync', id przebiegu)`), w ramach istniejącego workera `pp:sync:work` – bez osobnego crona,
- ręcznie: przycisk „Sprawdź teraz” albo `wp acorn pp:monitor {projekt}`.

Idempotencja: tabela `pp_monitoring_runs` ma unikalny klucz (projekt, przebieg synchronizacji) – ten sam przebieg nie jest monitorowany dwa razy.

## Reguły (`Domain/Monitoring/AlertDetector`)

Porównania dotyczą porównywalnych okresów (nie dzień do dnia): ostatnie 7 dni vs 28 poprzednich dni; leady – 14 vs 14 dni.

| Typ | Warunek | Ochrona przed fałszywym alarmem |
|---|---|---|
| `cpc_spike` | CPC 7 dni ≥ 1,4× CPC z 28 dni (≥ 1,8× – krytyczny) | ≥ 30 kliknięć w 7 dniach, ≥ 100 w 28 dniach, koszt ≥ progu (max(50 jedn. waluty, 1% tygodniowych wydatków)) |
| `ctr_drop` | CTR 7 dni ≤ 0,7× CTR z 28 dni | ≥ 1 000 wyświetleń w 7 dniach i ≥ 3 000 w 28 dniach |
| `spend_no_conversions` | 0 konwersji w 7 dniach | przy dotychczasowym współczynniku oczekiwane ≥ 3 konwersje, ≥ 4 konwersje w bazie, koszt ≥ 2× dotychczasowego kosztu konwersji |
| `no_activity` | kampania włączona, 0 wyświetleń przez 3 dni | wcześniej aktywna ≥ 14 z 28 dni i z wydatkami |
| `pacing` | prognoza miesiąca ≥ 115% budżetu (≥ 130% – krytyczny) | od 5. dnia miesiąca, prognoza liniowa oznaczona jako szacunek |
| `leads_drop` | konwersje celów biznesowych ≤ 50% poprzednich 14 dni | ≥ 6 leadów wcześniej; brak alarmu, gdy wydatki spadły o > 30% (świadome ograniczenie) |
| `measurement` | mikrokonwersje ≥ 50% konwersji Google (wcześniej < 30%) albo zniknęły cele biznesowe przy dalszych konwersjach Google | ≥ 5 konwersji Google |
| `sync_failed` | połączenie wymaga ponownej autoryzacji / błąd (krytyczny) albo ostatni przebieg nieudany | — |
| `stale_data` | brak udanej synchronizacji > 48 h (> 7 dni – krytyczny) | tylko dla aktywnego połączenia |
| `audit` | nowy krytyczny problem audytu, którego nie było w poprzednim audycie tego samego okresu | — |

Ogólne zasady: kampanie wstrzymane/usunięte nie generują alarmów o spadkach (świadoma decyzja); okna z niepełnymi danymi (brak pokrycia API/importów) wyłączają reguły skuteczności; „konwersje” Google nie są traktowane jak leady – spadek leadów liczony jest z działań oznaczonych jako cel biznesowy (`docs/ARCHITECTURE.md`, konwersje). Sezonowość: porównanie z 4 poprzednimi tygodniami (te same dni tygodnia) ogranicza wpływ wahań tygodniowych; dłuższych sezonów (np. rok do roku) reguły nie modelują.

## Alerty i statusy

Odcisk alertu = typ + kampania (albo konto / miesiąc pacingu / problem audytu) → jeden alert na problem, bez duplikatów; kolejne wykrycia zwiększają licznik `occurrences`.

| Status | Znaczenie |
|---|---|
| Nowy | wymaga uwagi (licznik w menu „Monitoring”) |
| Potwierdzony | ktoś się tym zajmuje – kolejne wykrycia nie zmieniają statusu |
| Wyciszony | do wybranej daty (albo bezterminowo); po terminie, jeśli problem trwa – wraca do „Nowy” |
| Rozwiązany | jeśli problem pojawi się ponownie – „Nowy” (zdarzenie „ponownie otwarty”) |

Gdy warunek przestaje występować, alert nie jest zamykany automatycznie – dostaje znacznik „warunek nieaktywny” i informację w historii. Każda zmiana trafia do `pp_alert_events` (wykrycie, zmiana statusu z notatką, ponowne otwarcie, koniec wyciszenia).

Centrum powiadomień: lista z filtrami (status, ważność, typ), szczegóły z dowodami i okresem, linki do kampanii, konwersji, strony Google Ads lub audytu (zależnie od typu), licznik nowych alertów w nawigacji.

Uprawnienia: przeglądanie – każdy członek organizacji; zmiana statusu i „Sprawdź teraz” – członek (`ManageAlerts`).

## Powiadomienia e-mail (`AlertNotifier`)

- wyłącznie po włączeniu przez użytkownika (opt-in per projekt, user meta `pp_alert_email`), wyłączane tym samym formularzem,
- próg ważności: tylko krytyczne albo krytyczne i ostrzeżenia,
- najwyżej jeden zbiorczy e-mail na dobę na użytkownika i projekt,
- deduplikacja: alert jest wysyłany raz; ponownie dopiero po ponownym otwarciu albo końcu wyciszenia; wyciszone, potwierdzone i rozwiązane – nigdy,
- odbiorca musi być członkiem organizacji projektu w chwili wysyłki,
- wysyłka przez `wp_mail` (konfiguracja SMTP serwera); błąd jest logowany bez adresów e-mail i treści.

## Kod i testy

`Domain/Monitoring/AlertDetector`, `Services/Monitoring/{MonitoringService,AlertNotifier}`, `Repositories/AlertRepository`, `Http/Controllers/MonitoringController`, `Console/MonitorCommand`, widok `pages/monitoring.blade.php`. Testy: `tests/Unit/ActionPlanAndAlertsTest.php`, `tests/Integration/MonitoringTest.php`, `GoogleAdsSyncTest::testSuccessfulSyncRunsAnalysisActionPlanAndMonitoringOnce`.
