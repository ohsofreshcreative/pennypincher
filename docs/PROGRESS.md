# Penny Pincher – postęp prac

Stan na 12.10.2026 (etap 1 – Prompt 1).

## Zrobione

- Moduł aplikacji w motywie (`app/PennyPincher`, `routes/app.php`), routing Acorn pod `/app/` z obsługą 403/404/419.
- Logowanie własnym ekranem (konta WordPress), wylogowanie, ochrona przed open redirect, CSRF (nonce WP), nagłówki bezpieczeństwa.
- Organizacje, członkostwo i role (właściciel/administrator/członek), zarządzanie członkami, projekty z pełnymi ustawieniami, przełącznik projektu.
- 15 tabel `pp_*` z kluczami obcymi, wersjonowane migracje (automatycznie przy pierwszym wejściu na `/app/` lub `wp acorn pp:migrate`).
- Import CSV (UTF-8/UTF-16/Windows-1250, dowolny separator) i XLSX: rozpoznanie wiersza nagłówków, języka, zakresu dat i typu raportu, podgląd, ręczne mapowanie, walidacja całości bez częściowego zapisu, idempotentny ponowny import, ostrzeżenie o duplikacie, zgodność waluty.
- Typy raportów: Skuteczność kampanii, Wyszukiwane hasła, Słowa kluczowe, Działania powodujące konwersję.
- Dashboard (KPI, budżet proporcjonalny do zakresu, wykresy dzienne SVG, kampanie, hasła, importy, informacja o źródle i pokryciu dat), Kampanie (filtry, sortowanie, paginacja, szczegóły ze źródłami danych), Wyszukiwane hasła (wyszukiwarka, filtry kampanii i konwersji, sortowanie, paginacja), Ustawienia projektu.
- Projekt demonstracyjny w osobnej organizacji „Demo” (dane przechodzą przez prawdziwy importer).
- Komendy: `wp acorn pp:migrate`, `pp:organization:create`, `pp:import`, `pp:imports:prune`.
- 53 testy automatyczne (`tests/run.php`) + pliki przykładowe w `tests/fixtures`.
- Dokumentacja: `AGENTS.md` (= `CLAUDE.md`), `docs/PRODUCT.md`, `docs/ARCHITECTURE.md`, ten plik.

## Weryfikacja

Wykonane w kopii środowiska (WordPress 7.1.3 i wtyczki z LocalWP, motyw z `vendor/`, PHP 8.3, MariaDB 10.11):

- `php -l` dla wszystkich plików aplikacji – bez błędów,
- `yarn build` – przechodzi (ostrzeżenie o `::first-child` w istniejącym CSS strony, niezwiązane),
- testy: 53/53 (jednostkowe: parsowanie liczb i dat, kwoty, metryki, budżet, rozstrzyganie okresów, czytniki CSV/XLSX, normalizacja; integracyjne: izolacja organizacji, role, importy wszystkich typów, idempotencja, błędne pliki, waluta, dashboard, filtry i paginacja, projekt demo, migracje),
- migracje na pustej bazie: 15 tabel, 30 kluczy obcych, testy bez pozostawiania danych,
- test HTTP end-to-end (60/60): logowanie, 302/403/404/419, skróty URL, tworzenie organizacji i projektu, ustawienia, upload i zatwierdzenie CSV i XLSX, ponowny import, błędne pliki, anulowanie, zmiana typu raportu, izolacja i role drugiego użytkownika, wylogowanie,
- przegląd w przeglądarce (Chromium, 1440 px i 390 px): dashboard, kampanie, hasła, importy, podgląd importu, ustawienia, projekty – bez poziomego przewijania strony,
- komendy `pp:*` uruchomione przez kernel konsoli Acorn.

## Niezweryfikowane / znane problemy

- Nie udało się otworzyć `http://pennypincher.local/app/` w przeglądarce na komputerze (żądanie nie doszło do skutku – prawdopodobnie strona w LocalWP była zatrzymana), więc pierwsze uruchomienie migracji na bazie LocalWP i test na MySQL z LocalWP trzeba wykonać ręcznie (patrz niżej).
- Testy działały na MariaDB 10.11; LocalWP zwykle używa MySQL 8 – `VALUES()` w `ON DUPLICATE KEY UPDATE` generuje tam tylko ostrzeżenie o przestarzałej składni.
- Komendy uruchamiane były przez kernel Acorn, nie przez binarkę `wp` (brak WP-CLI w środowisku testowym).
- Czytnik XLSX jest własny (Packagist był niedostępny); obsługuje pierwszy arkusz pliku.
- Brak limitu prób logowania (jak w czystym WordPressie) – warto dodać wtyczkę bezpieczeństwa lub limit w etapie produkcyjnym.
- Import działa synchronicznie (limit 100 000 wierszy i rozmiaru pliku); kolejka będzie potrzebna przy synchronizacji z API.
- Istniejące, niezwiązane problemy motywu: ostrzeżenie PHP w JSON-LD w `layouts/app.blade.php` (`@context` interpretowane przez Blade), pozostałe z listy „Znane niespójności” w AGENTS.md; sekcja „Repository map” w AGENTS.md nadal opisuje motyw h2otwock.
- W repozytorium jest śledzony prywatny klucz SSH (`key`) – należy go usunąć z historii i unieważnić.

## Ręczna konfiguracja

1. Uruchom stronę w LocalWP; ustaw bezpośrednie odnośniki inne niż „Prosty”.
2. Wejdź na `/app/` jako administrator WordPressa – migracje utworzą tabele (albo `wp acorn pp:migrate`).
3. Utwórz organizację „OhSoFresh Creative” i projekt z ustawieniami z `docs/PRODUCT.md`.
4. Zaimportuj raporty z Google Ads (Kampanie z segmentem „Dzień”, Wyszukiwane hasła).
5. Inne osoby: konto WordPress + dodanie w „Członkowie” organizacji.
6. Produkcja: systemowy cron `wp acorn pp:imports:prune` raz dziennie; opcjonalnie filtr `pennypincher/import/max_bytes`.

## Następne kroki (Prompt 2)

- Silnik audytowy na zaimportowanych danych (reguły, priorytety, wyniki per projekt i zakres dat).
- Wykorzystanie opisu biznesu, klienta docelowego i wartości projektów z ustawień w regułach.
- Rozszerzenie widoku słów kluczowych i konwersji (dane są już importowane do bazy).
