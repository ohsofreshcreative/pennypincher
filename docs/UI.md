# System UI aplikacji `/app/`

Zasady wyglądu widoków aplikacji (Tailwind 4 + klasy `pp-*` z `resources/css/pp-app.css` + komponenty `<x-pp.*>`).
Ciemny sidebar i jasny obszar roboczy zostają; zmiany są warstwą prezentacji – bez wpływu na silniki analizy i dane.

## Tokeny (`@theme` w `pp-app.css`)

| Grupa | Tokeny |
|---|---|
| Kolory | `paper` (tło), `surface` (karty), `ink` / `ink-soft` / `ink-mute` (tekst), `line` / `line-strong`, `mint` (akcja, sukces), `copper` (wydatki, akcent), `danger`, `warn`, `info` (+ `*-soft`), `sidebar*` |
| Promienie | `--radius-card` (karty, alerty), `--radius-control` (przyciski, pola) |
| Typografia | `text-2xs` 11,5 px (liczniki, etykiety osi), `text-caption` 12,5 px (metadane, nagłówki tabel, badge), `text-meta` 13 px (opisy pomocnicze, małe przyciski), `text-sm` 14 px, `text-body` 15 px (tekst bazowy, tytuły kart), `text-lg`/`text-xl` (nagłówki), `pp-display` (logo, liczby hero) |

Drobnych rozmiarów nie zapisuj wartościami arbitralnymi (`text-[13px]` itd.) – pilnuje tego `tests/Unit/UiTokensTest.php`.
Tokeny tekstu nie ustawiają wysokości linii (dziedziczą ją albo biorą z `leading-*`), tak jak wcześniejsze wartości arbitralne.
Liczby zawsze z `pp-num` (cyfry tabelaryczne), kwoty przez `Support\Format`, brak danych = „—”.

## Komponenty

| Komponent / klasa | Zastosowanie |
|---|---|
| `pp-card`, `pp-card-header`, `pp-card-title` | Karta z nagłówkiem; tytuł sekcji strony = `<h2 class="pp-card-title">` |
| `<x-pp.button>` (`primary`, `secondary`, `ghost`, `danger`, `size="sm"`, `icon`) | Wszystkie przyciski i linki-przyciski |
| `pp-table-wrap` + `pp-table` (`pp-col-num`, `pp-col-sticky`), `<x-pp.sort-header>`, `<x-pp.pagination>` | Tabele danych |
| `<x-pp.filter-bar>`, `<x-pp.field>`, `pp-input`, `pp-label`, `pp-help`, `pp-error` | Filtry i formularze |
| `<x-pp.date-range>` (metryki: Przegląd, Kampanie, Hasła), `<x-pp.period-select>` (moduły analizy) | Wybór okresu w nagłówku – ten sam wygląd, osobne presety (patrz „Znane ograniczenia”) |
| `<x-pp.stat-card>` | KPI w siatce `<dl>` |
| `<x-pp.status-badge>`, `pp-badge pp-badge-{ok,warn,danger,info,neutral,demo}` | Statusy |
| `<x-pp.source-badge :source :demo>` | **Nowy.** Źródło liczb: „Google Ads API”, „Google Ads API + import”, „Import”, „Dane demonstracyjne” (wartości `ResolvedPeriods::source()`) |
| `<x-pp.breadcrumbs :items>` | **Nowy.** Okruszki; ostatni element = bieżąca strona |
| `<x-pp.alert type="success\|error\|warning\|info">` | Komunikaty, stany błędu i ostrzeżenia w treści |
| `<x-pp.empty-state title icon>` + slot `actions` | Stan pusty (brak danych, brak analizy, brak połączenia) z jednym krokiem dalej |
| `app.pages.error` (`Responses::error`) | Strony 403 (brak uprawnień), 404, 500 |
| `form[data-busy]` + `data-busy-label` | Stan „w toku” przy dłuższych operacjach (blokada podwójnego wysłania) |
| `form[data-confirm]` | Potwierdzenie przed wysłaniem |

## Nagłówek strony

- Stare menu: „Organizacja / Projekt” nad tytułem z kontrolera (`pageTitle`).
- Nawigacja 2.0 (flaga `pp2_navigation`): okruszki **Projekt › Sekcja › Podwidok › szczegół**; na stronie startowej
  podwidoku nagłówek to nazwa z menu (np. „Wycieki budżetu”), na stronach szczegółów – tytuł z kontrolera.
  `<title>` dokumentu bez zmian. Wyliczane w `layouts/app.blade.php` z `Support\Navigation`.

## Zaktualizowane ekrany (Penny Pincher 2.0, dokument 03 „Spójny system UI”)

Tokeny typografii zamiast wartości arbitralnych (328 miejsc, 42 pliki): wszystkie strony w `views/app/pages`
(akcje, audyt, Budget Leaks, kampanie i szczegóły, Change Center i zestaw zmian, Conversion Management, konwersje,
Przegląd, Google Ads, importy i podgląd, Keyword Opportunities, Management – konto, kampania, grupa reklam, Monitoring,
członkowie, Planner – lista i plan, projekty, rekomendacje, hasła, ustawienia), partiale audytu, Change Center,
Przeglądu, pokrycia danych i nawigacji oraz komponenty `date-range`, `period-select`, `stat-card`, `line-chart`
i klasy w `pp-app.css` (`pp-btn-sm`, `pp-help`, `pp-error`, `pp-table th`, `pp-badge`).
Różnica wizualna: teksty 12 px → 12,5 px i 11 px → 11,5 px (ujednolicenie skali), pozostałe bez zmian.
Oznaczenie źródła danych: pasek pokrycia na Przeglądzie, liście i szczegółach kampanii oraz w wyszukiwanych hasłach.
Okruszki i nagłówki z menu: wszystkie ekrany projektu przy włączonej `pp2_navigation`.

## Znane ograniczenia (świadomie poza tym etapem)

- Brak skeletonów i stanu offline – strony renderuje serwer; jedynym stanem ładowania jest `data-busy` przy formularzach.
- Dwa selektory okresu (`DateRange` i `AnalysisPeriod`) mają osobne presety i osobno zapamiętany wybór – scalenie
  zmienia logikę okresów, więc wymaga decyzji (etap Centrum dowodzenia / Optymalizacja).
- Część ekranów (zestaw zmian, dostęp do zapisu, Conversion Management) ma własne mapy statusów na `pp-badge`
  zamiast `<x-pp.status-badge>` – statusy domenowe Change Center, do ujednolicenia przy etapie Plan działania.
- Komunikaty „Brak analizy” są powtarzane na ekranach modułów – jeden punkt wejścia to zakres etapu Optymalizacja.
- Ocena wizualna tylko serwerowa (bez zrzutów ekranu).

## Rollback

- Okruszki i nagłówki: wyłączenie `pp2_navigation`.
- Tokeny i oznaczenie źródła: zmiana czysto wizualna bez flagi – cofnięcie przez `git revert` zmian w `resources/`
  i ponowny `yarn build` (`public/build`).
