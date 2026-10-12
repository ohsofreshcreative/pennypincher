# Penny Pincher – wdrożenie produkcyjne (Hostinger)

Stan: 12.10.2026 (Prompt 2). **Wdrożenie nie zostało wykonane.** Jest wstrzymane do czasu wymiany ujawnionego
klucza SSH (`docs/SECURITY.md`) i Twojej zgody. Skrypt `scripts/deploy.sh` przetestowano lokalnie tylko do etapu
budowy paczki (połączenie z serwerem nie było nawiązywane).

| Środowisko | Adres | Rola |
|---|---|---|
| Lokalne (LocalWP) | `http://pennypincher.local` (dla OAuth: `https://`) | Rozwój i testy |
| Produkcja | `https://pennypincher.ohsofresh.top` | Tylko wdrożenia z repozytorium – nie edytujemy tam kodu |

Serwer: Hostinger, SSH `u679500043@147.93.60.77`, port `65002`, WordPress w
`/home/u679500043/domains/pennypincher.ohsofresh.top/public_html`.

## Zasady

- Wykorzystujemy **istniejącą instalację WordPressa** na Hostingerze – skrypt kończy się błędem, jeśli jej nie ma,
  i niczego nie instaluje.
- **Lokalna baza nie jest kopiowana.** Na produkcji uruchamiane są wyłącznie migracje Penny Pincher
  (addytywne: nowe tabele/kolumny/klucze, bez usuwania danych). Stare skrypty `deploy/deploy-db.sh` (nadpisują
  bazę produkcyjną lokalną) **nie wolno używać** – zostają tylko jako lokalne, ignorowane przez Git pliki.
- Przed każdą zmianą: kontrola serwera tylko do odczytu, kopia motywu i eksport bazy na serwerze.
- Na serwer trafia czysta paczka z commita (`git archive`), bez `tests/`, `scripts/`, plików `key`, `deploy/`,
  dokumentacji agentów i `node_modules`.
- Sekrety (hasła bazy, klucze Google, klucz szyfrowania) są tylko w `wp-config.php` na serwerze.

## Jednorazowe przygotowanie serwera

1. **Klucz SSH** – nowy klucz (krok 2–4 planu w `docs/SECURITY.md`), dodany w hPanelu → SSH Access.
   Skrypt odmówi użycia klucza z repozytorium (sprawdza odcisk).
2. **PHP ≥ 8.2** z rozszerzeniami `sodium`, `mysqli`, `zip`, `xml`, `mbstring`, `intl` (hPanel → PHP Configuration);
   `imagick` wymaga zależność motywu `spatie/pdf-to-image`.
3. **SSL** – hPanel → SSL → certyfikat dla `pennypincher.ohsofresh.top` i *Force HTTPS*. W WordPressie
   adresy `https://` (Ustawienia → Ogólne albo `WP_HOME`/`WP_SITEURL`).
4. **Bezpośrednie odnośniki** inne niż „Prosty” (Ustawienia → Bezpośrednie odnośniki) – wymagane przez `/app/`.
5. **`wp-config.php`** – dopisz (wartości uzupełniasz na serwerze, nie w repozytorium):

```php
define('WP_ENVIRONMENT_TYPE', 'production');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);          // albo ścieżka POZA public_html, np. '/home/u679500043/logs/wp-debug.log'
define('DISALLOW_FILE_EDIT', true);     // brak edytora plików w panelu
define('FORCE_SSL_ADMIN', true);
define('DISABLE_WP_CRON', true);        // WP-Cron uruchamia systemowy cron (niżej)
define('WP_AUTO_UPDATE_CORE', 'minor');

// Penny Pincher
define('PP_ENCRYPTION_KEY', '…');       // wp acorn pp:key:generate – osobny klucz dla produkcji
define('PP_GOOGLE_ADS_CLIENT_ID', '…');
define('PP_GOOGLE_ADS_CLIENT_SECRET', '…');
// DataForSEO (opcjonalnie – docs/DATAFORSEO.md); bez tych stałych płatne zapytania są niemożliwe
// define('PP_DATAFORSEO_LOGIN', '…');
// define('PP_DATAFORSEO_PASSWORD', '…');  // hasło API z panelu DataForSEO
```

   Sprawdź też, że klucze `AUTH_KEY`… `NONCE_SALT` są unikalne (nie domyślne) i że prefiks tabel jest ustawiony.
   Uprawnienia: `chmod 600 wp-config.php` (lub 640, jeśli serwer tego wymaga).
6. **Motyw** – po pierwszym wgraniu aktywuj `pennypincher` (Wygląd → Motywy albo `wp theme activate pennypincher`)
   i uruchom skrypt ponownie (migracje wykonują się tylko przy aktywnym motywie).

## Wdrożenie

```bash
cp scripts/deploy.env.example scripts/deploy.env   # raz; plik jest w .gitignore
scripts/deploy.sh --check                           # budowa + kontrola serwera, bez zmian
scripts/deploy.sh                                   # pełne wdrożenie (potwierdzenie słowem „wdrażam”)
scripts/deploy.sh --ref v1.2.0                      # konkretny tag/commit
```

Kroki skryptu:

1. Odrzuca klucz SSH z repozytorium / o odcisku ujawnionego klucza.
2. Buduje paczkę: `git archive` → `composer install --no-dev --optimize-autoloader` → `yarn install --frozen-lockfile && yarn build`
   → usunięcie `node_modules`. Wdrażany jest commit, nie niezapisane zmiany (ostrzega o nich).
3. Kontrola serwera (tylko odczyt): `wp-config.php`, WP-CLI, PHP ≥ 8.2, `sodium`, połączenie z bazą, aktywny motyw,
   wersja schematu, obecność stałych `PP_*`, `DISALLOW_FILE_EDIT`, `WP_DEBUG_DISPLAY`.
4. Kopie: `~/backups/pennypincher/theme-<czas>.tar.gz` i `db-<czas>.sql.gz` (eksport; zostaje 10 ostatnich).
5. `rsync --delete --delay-updates` do `wp-content/themes/pennypincher/` (pliki, których nie ma w paczce,
   są usuwane z katalogu motywu na serwerze – także ewentualna stara kopia `key`).
6. Blokada PHP w `wp-content/uploads` (`scripts/server/uploads.htaccess`) – tylko gdy nie ma tam `.htaccess`.
7. `wp acorn optimize:clear`, `wp acorn pp:migrate`, `wp acorn pp:google-ads:check`.
8. Weryfikacja HTTP: `/app/login/` 200, `/app/` → 302, HTTP → HTTPS, pliki motywu (`composer.json`, kod `app/`,
   `key`, `.git`) → 403/404, XML-RPC zablokowany, `/wp-json/wp/v2/users` bez logowania → 401, nagłówki HSTS i
   `X-Frame-Options`.

## Cron (hPanel → Zaawansowane → Cron Jobs)

```
*/10 * * * * cd /home/u679500043/domains/pennypincher.ohsofresh.top/public_html && wp acorn pp:sync:work --time=240 >> /home/u679500043/logs/pp-sync.log 2>&1
*/15 * * * * cd /home/u679500043/domains/pennypincher.ohsofresh.top/public_html && wp cron event run --due-now >/dev/null 2>&1
15 3 * * *   cd /home/u679500043/domains/pennypincher.ohsofresh.top/public_html && wp acorn pp:imports:prune >/dev/null 2>&1
```

- `pp:sync:work` – codzienna synchronizacja Google Ads, dokańczanie backfillu, ponowienia, a po każdej
  zakończonej synchronizacji analiza (audyt, Budget Leaks, słowa kluczowe, rekomendacje) dla ostatnich 30 dni.
  W pozostałym czasie wykonuje zadania DataForSEO zatwierdzone ręcznie przez użytkownika (nigdy nie tworzy ich sam).
  Czas pracy 240 s mieści się w odstępie 10 minut; równoległe uruchomienia są bezpieczne (blokada na połączenie,
  atomowe przejmowanie zadań DataForSEO).
- Jeśli `wp` nie jest w `PATH` crona, użyj pełnej ścieżki (`which wp` w SSH), np. `/usr/local/bin/wp`.
- Utwórz katalog logów: `mkdir -p ~/logs`.

## Wycofanie (rollback)

```bash
scripts/rollback.sh --list                 # kopie na serwerze
scripts/rollback.sh 20261012-101500        # pliki motywu z kopii (bieżące zostają jako .pennypincher.rollback-…)
scripts/rollback.sh 20261012-101500 --database   # dodatkowo baza – NADPISUJE dane zapisane po kopii
```

Migracje są addytywne, więc zwykle wystarczy przywrócić pliki: starszy kod ignoruje nowe tabele i kolumny.
Przywrócenie bazy tylko przy uszkodzeniu danych – przed nim skrypt robi kopię bieżącej bazy.

## Bezpieczeństwo produkcji – lista kontrolna

| Wymaganie | Jak spełnione |
|---|---|
| Bez HTTP Basic Auth na całej stronie | Brak – strona publiczna dostępna normalnie. |
| `/app/` tylko po zalogowaniu | Middleware `Authenticate` na wszystkich trasach poza `/app/login/`. |
| Brak publicznej rejestracji | `Hardening`: `users_can_register` wymuszone na 0, konta zakłada administrator. |
| Autoryzacja i izolacja organizacji | `AccessService` + `project_id` w każdym zapytaniu (testy izolacji). |
| CSRF | Nonce w każdym POST (`VerifyNonce`), OAuth z jednorazowym state. |
| Limit logowania | `LoginThrottle` (5/15 min na login+IP, 20 na IP). |
| Bezpieczne sesje | Ciasteczka WordPressa (`HttpOnly`, `Secure` przy HTTPS dzięki `FORCE_SSL_ADMIN`/adresom https). |
| HTTPS | Force HTTPS w hPanelu + HSTS w odpowiedziach `/app/`. |
| Brak ujawniania błędów | `WP_DEBUG_DISPLAY=false`, strona 500 bez szczegółów w `/app/`, komunikaty API bez tokenów. |
| Brak dostępu do sekretów i konfiguracji | `.htaccess` motywu (tylko assety), sekrety wyłącznie w `wp-config.php`, `wp-config.php` poza zasięgiem odczytu (PHP). |
| `uploads` | Blokada wykonywania PHP; importy nie zapisują plików w `uploads`. |
| Ograniczenie endpointów | XML-RPC → 403, `/wp/v2/users` i `?author=N` niedostępne anonimowo, brak wersji WP w nagłówkach. |
| Subdomena nie jest zabezpieczeniem | Wszystkie powyższe mechanizmy działają niezależnie od adresu. |

## Rozwiązywanie problemów

| Objaw | Rozwiązanie |
|---|---|
| `/app/` zwraca 404 | Bezpośrednie odnośniki na „Prosty” – zmień; wyczyść cache (`wp acorn optimize:clear`, LiteSpeed Cache). |
| Biała strona / 500 | `WP_DEBUG_LOG` ze ścieżką poza `public_html`, sprawdź log; `wp acorn pp:migrate`; wersja PHP. |
| „Error locating autoloader” | Paczka bez `vendor/` – wdrażaj przez `scripts/deploy.sh` (composer w kroku budowy). |
| Brak stylów `/app/` | Brak `public/build` lub blokada `.htaccess` – sprawdź `curl -I …/public/build/assets/<plik>.css`. |
| 500 po wgraniu `.htaccess` motywu | Serwer nie pozwala na `Options` w `.htaccess` – usuń linię `Options -Indexes`. |
| LiteSpeed Cache serwuje `/app/` | Dodaj `/app/` do wykluczeń cache (aplikacja wysyła `Cache-Control: no-store`, ale wtyczki mogą to nadpisać). |
| Synchronizacja nie postępuje | Cron `pp:sync:work`, log `~/logs/pp-sync.log`, `wp acorn pp:google-ads:check`. |
