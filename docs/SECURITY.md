# Penny Pincher – bezpieczeństwo

Stan kontroli: 12.10.2026 (Prompt 2 – Google Ads API, produkcja).

## Mechanizmy aplikacji `/app/`

| Obszar | Stan |
|---|---|
| Logowanie | Konta i ciasteczka WordPressa (`wp_signon`), własny formularz, jeden komunikat dla złego loginu i hasła (bez ujawniania, czy konto istnieje), `redirect_to` tylko w obrębie `/app/`. |
| Limit prób logowania | `LoginThrottle`: 5 nieudanych prób na parę IP + login i 20 na IP w oknie 15 minut; odblokowanie po wypadnięciu prób z okna (bez blokady na stałe), reset po udanym logowaniu. Działa na filtrze `authenticate`, więc obejmuje też `wp-login.php` i XML-RPC. Odpowiedź w `/app/login/`: HTTP 429. Konfiguracja: filtry `pennypincher/login_throttle/limits` i `pennypincher/login_throttle/ip` (np. za zaufanym proxy). |
| Autoryzacja | Projekty i organizacje tylko przez `AccessService` (JOIN z członkostwem bieżącego użytkownika). Cudzy zasób = 404, za niska rola = 403. Administrator WordPressa nie ma automatycznego dostępu do danych organizacji. Projekt demo wymaga tego samego uprawnienia co zakładanie organizacji. |
| CSRF | Nonce WordPressa w każdym POST (`VerifyNonce`, HTTP 419). Wylogowanie tylko przez POST. |
| Izolacja danych | Wszystkie tabele danych mają `project_id`; każde zapytanie repozytorium filtruje po projekcie, filtry z parametrów (np. kampania) są sprawdzane w obrębie projektu. Pokryte testami (`IsolationTest`, `ConversionImportTest`). |
| SQL | Parametry `?` escapowane przez `mysqli_real_escape_string` (z obsługą NULL), sortowanie z białej listy, `LIMIT/OFFSET` rzutowane na int, `LIKE` przez `esc_like`. |
| Import plików | Limit rozmiaru, biała lista rozszerzeń, kontrola sygnatury (XLSX = ZIP z `xl/workbook.xml`; CSV ≠ ZIP/PDF/ELF/PHP), wykrywanie danych binarnych, ochrona przed zip bombą i ścieżkami `..`, odrzucanie XML z `DOCTYPE`/`ENTITY` (XXE), `LIBXML_NONET`, limit 100 000 wierszy. Plik nie trafia do `uploads`. |
| Walidacja danych | Ustawienia projektu (`ProjectValidator`), mapowanie kolumn (istnienie kolumny, brak duplikatów, wymagane pola), wartości w każdym wierszu (`RowNormalizer`) – błąd = nic nie jest zapisywane. |
| Obsługa błędów | 403/404/419/429 z własnymi stronami; inne wyjątki w `/app/` przy wyłączonym debugu → ogólna strona 500 bez szczegółów (szczegóły w logu). Błędy bazy w imporcie są logowane, użytkownik widzi ogólny komunikat. |
| Nagłówki | `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-store`, `Permissions-Policy`, `noindex`; przez HTTPS także `Strict-Transport-Security`. |
| Google Ads – OAuth | Autoryzacja kodem z PKCE (S256); `state` 256-bitowy, jednorazowy, 10 minut, powiązany z użytkownikiem i projektem; uprawnienie właściciela/administratora sprawdzane przy starcie i przy powrocie. Projekt demo nie może się połączyć. |
| Google Ads – tokeny | Szyfrowane libsodium (`SecretBox`, `v1:` + nonce) kluczem `PP_ENCRYPTION_KEY` spoza repozytorium i bazy, z rotacją przez `PP_ENCRYPTION_KEY_PREVIOUS`. Tokeny nie są zwracane w danych połączenia, logach ani komunikatach. Rozłączenie unieważnia token w Google i usuwa go z bazy. |
| Google Ads – ruch wychodzący | Tylko HTTPS do `oauth2.googleapis.com`, `googleads.googleapis.com`, `accounts.google.com` (biała lista w `GoogleHttp`); odczyt (`searchStream`, `listAccessibleCustomers`); zapis (`googleAds:mutate`) wyłącznie w `GoogleAdsMutateClient`, tylko z workera Change Center, tylko przy `PP_GOOGLE_ADS_WRITE_MODE=write_enabled` + zgodzie właściciela dla konta + zatwierdzonej wersji zestawu + wykonawcy z uprawnieniem. |
| WordPress | `Hardening`: rejestracja zawsze wyłączona, XML-RPC → 403, `/wp/v2/users` i `?author=N` niedostępne anonimowo, bez wersji WP w nagłówkach. |
| Pliki motywu | `.htaccess` motywu przepuszcza tylko `public/build/assets/*`, `resources/images|fonts` i `style.css`/`screenshot.png`; kod, `vendor`, `composer.*`, testy, dokumentacja, `key` → 403. |
| Audyt | Wyłącznie odczyt danych; statusy problemów zmienia członek organizacji (nonce, izolacja projektu). |

Konfiguracja produkcji (wp-config, HTTPS, cron, blokada PHP w `uploads`): `docs/DEPLOYMENT.md`.

Uwagi poza kodem aplikacji:

- Lokalne skrypty `deploy/*.sh` (ignorowane przez Git) zawierają dane dostępowe do serwera i bazy. Nie są w repozytorium, ale warto przenieść hasło bazy do zmiennej środowiskowej lub `~/.my.cnf`.
- `WP_DEBUG` musi być wyłączony na produkcji (inaczej Acorn pokaże szczegóły błędów).

## Zmiany Google Ads (Change Center)

- Tryb efektywny = najbardziej restrykcyjny z: stałej serwera `PP_GOOGLE_ADS_WRITE_MODE` (domyślnie `prepare_only`, nieznana wartość = `read_only`) i zgody właściciela zapisanej przy połączeniu. Przełącznik w UI nie włącza zapisu, gdy serwer go nie pozwala; zgoda wygasa przy zmianie konta i rozłączeniu.
- Role: członek przygotowuje, administrator zatwierdza, wdraża tylko administrator z flagą „wykonawca zmian” nadaną przez właściciela. Uprawnienia sprawdzane w kontrolerze, w serwisie i ponownie w workerze (w imieniu osoby, która zleciła wdrożenie).
- Zestaw jest na stałe związany z organizacją, projektem, połączeniem i kontem Google Ads; identyfikator konta nie przychodzi z żądania HTTP. Zasoby spoza konta zestawu są odrzucane (`ResourceName::isValid`).
- Zatwierdzana jest konkretna wersja (odcisk SHA-256 operacji); każda zmiana unieważnia zatwierdzenie. Przed wysłaniem: ponowny odczyt stanu (dryf → stop), blokada `claim` + `GET_LOCK`, jednorazowy `deploy_key`.
- Wynik nieznany (timeout, 5xx) nigdy nie jest automatycznie ponawiany – wymaga uzgodnienia stanu odczytem.
- Historia (`change_events`, `change_attempts`) bez tokenów i pełnych odpowiedzi API (`scrub`).
- **MANUAL APPROVAL**: żadna rekomendacja, szkic ani zatwierdzenie nie wysyła zmian; worker wykonuje tylko zestaw z ręcznym poleceniem wdrożenia wydanym po zatwierdzeniu tej wersji.
- **Allowlista kont** `PP_GOOGLE_ADS_WRITE_CUSTOMERS` (pusta = brak zapisu; sam wpis nie włącza zapisu) i opcjonalna **lista blokad** `PP_GOOGLE_ADS_BLOCKED_CUSTOMERS` (bez niekonfigurowalnych blokad w kodzie) – sprawdzane w kliencie mutate przy każdym żądaniu (konto z listy blokad nie dostaje nawet żądań `validate_only`), w `WriteGuard` i przy udzielaniu zgody. Wszystkie resource name w operacjach muszą należeć do konta żądania.
- **Blokada emisji reklam** (domyślna, `PP_GOOGLE_ADS_ALLOW_SERVING` ≠ 1): nowe kampanie, grupy i reklamy tylko wstrzymane; włączenie istniejących zasobów, aktywne słowa w aktywnych grupach i zmiany budżetów aktywnych kampanii można przygotować i zatwierdzić, ale wdrożenie blokują: Change Center (brak przycisku, odmowa polecenia), worker (z odczytem stanu przed wysłaniem) i klient mutate. Szczegóły: [GOOGLE_ADS_WRITE_TESTING.md](GOOGLE_ADS_WRITE_TESTING.md).
- Wyłączenie zgody zdejmuje zestawy z kolejki; worker sprawdza zgodę, allowlistę i tryb przed każdym żądaniem.

## Klucz SSH `key` w repozytorium

Wynik sprawdzenia (bez odczytu zawartości klucza):

| Pytanie | Odpowiedź |
|---|---|
| Czy plik istnieje? | Tak: `key` (prywatny klucz OpenSSH, ED25519) i `key.pub` w katalogu głównym motywu. |
| Czy jest śledzony przez Git? | Tak, oba pliki. |
| Czy jest w historii? | Tak – od commita `1cc3f31 Initial commit`; jest też na `origin/main` (GitHub `ohsofreshcreative/pennypincher`) oraz w lokalnych referencjach `refs/agents/*` (punkty kontrolne narzędzia agenta). |
| Czy jest w `.gitignore`? | Od Prompt 2 tak (`key`, `key.pub`, `*.pem`, `id_ed25519*`, `scripts/deploy.env`) – ale to nie usuwa już śledzonego pliku: potrzebne `git rm --cached key key.pub` i commit (krok 5). |
| Odcisk klucza publicznego | `SHA256:G3BAf5zZ4Vff3QXCHmboFPmVKNlR5KSQ5Rlm8UlpVy0` (ED25519) – po nim znajdziesz klucz w GitHub/Hostinger. |
| Czy kod motywu go używa? | Nie. Skrypty `deploy/*.sh` łączą się z serwerem bez `-i key`, czyli domyślnymi kluczami z `~/.ssh`. Klucz najpewniej służy jako *deploy key* (GitHub → serwer, „Automatyczny deploy”) albo był wygenerowany do takiego celu. |

Klucz należy traktować jako ujawniony: każdy, kto ma lub miał dostęp do repozytorium (także jego kopii i forków), mógł go skopiować. Samo usunięcie z historii nie cofa ujawnienia – konieczna jest wymiana.

### Bezpieczny plan (do wykonania ręcznie, w tej kolejności)

1. **Ustal, gdzie klucz jest zaufany** (po odcisku z tabeli):
   - GitHub → repozytorium → Settings → Deploy keys, oraz GitHub → Settings → SSH keys na koncie,
   - Hostinger (hPanel → Zaawansowane → SSH / GIT) i plik `~/.ssh/authorized_keys` na serwerze,
   - lokalnie: `ssh-keygen -lf ~/.ssh/*.pub` i `~/.ssh/config` – czy ten sam klucz nie jest używany gdzie indziej.
2. **Wygeneruj nowy klucz poza repozytorium**: `ssh-keygen -t ed25519 -C "pennypincher-deploy" -f ~/.ssh/pennypincher_deploy`.
3. **Dodaj nowy klucz publiczny** w tych samych miejscach (np. nowy deploy key w GitHub, nowy klucz w Hostingerze) i **sprawdź działanie** (`ssh -i ~/.ssh/pennypincher_deploy -p 65002 …`, testowy deploy).
4. **Usuń stary klucz publiczny** z GitHub/Hostinger/`authorized_keys`. Od tej chwili ujawniony klucz nic nie otwiera.
5. **Usuń pliki z bieżącej wersji** i zablokuj ponowne dodanie:
   `git rm --cached key key.pub`, dopisz do `.gitignore` linie `/key` i `/key.pub`, przenieś pliki poza repozytorium (np. do `~/.ssh/` lub usuń), commit.
6. **Opcjonalnie wyczyść historię** (zmienia identyfikatory commitów, wymaga force push i ponownego sklonowania u wszystkich): `git filter-repo --path key --path key.pub --invert-paths`, potem `git push --force` po uzgodnieniu z osobami pracującymi na repo; usuń też lokalne `refs/agents/*` (`git for-each-ref --format='%(refname)' refs/agents | xargs -n1 git update-ref -d`). Jeśli repo jest publiczne lub miało forki, poproś GitHub Support o usunięcie widoków w pamięci podręcznej.
7. **Sprawdź automatyczny deploy** po zmianach (Hostinger pobiera repo kluczem deploy – po wymianie musi mieć nowy klucz, inaczej wdrożenia przestaną działać).

Kroki 4 i 6 są nieodwracalne – dlatego nie zostały wykonane automatycznie.

### Blokada wdrożeń

`scripts/deploy.sh` i `scripts/rollback.sh` odmawiają pracy kluczem o odcisku `SHA256:G3BAf5zZ4Vff3QXCHmboFPmVKNlR5KSQ5Rlm8UlpVy0`
oraz kluczem leżącym w katalogu repozytorium. Paczka wdrożeniowa nigdy nie zawiera plików `key`/`key.pub`, a `rsync --delete`
usuwa ich ewentualną starą kopię z katalogu motywu na serwerze. Wdrożenie produkcyjne jest wstrzymane do wykonania kroków 1–4.

### Inne sekrety

Przeszukano pliki śledzone i historię Git (Prompt 1.1 i 2): poza `key` nie znaleziono kluczy prywatnych, tokenów Google
(`ya29.`, `1//`, `GOCSPX-`, `AIza…`), haseł ani plików `.env`. Hasła bazy są tylko w lokalnych, ignorowanych `deploy/*.sh`.
Test `DeploymentConfigTest::testNoPrivateKeysInSourceFilesOtherThanKnownLeakedKey` pilnuje tego przy każdym uruchomieniu testów.

### Rotacja klucza szyfrowania tokenów

1. `wp acorn pp:key:generate` → nowa wartość.
2. W `wp-config.php`: dotychczasowy `PP_ENCRYPTION_KEY` przenieś do `PP_ENCRYPTION_KEY_PREVIOUS`, nowy wpisz jako `PP_ENCRYPTION_KEY`.
3. Tokeny są odszyfrowywane starym kluczem; przy najbliższym odświeżeniu (synchronizacja, maks. ok. 1 h) access i refresh
   token zapisują się zaszyfrowane nowym kluczem. Po dobie z działającym cronem `PP_ENCRYPTION_KEY_PREVIOUS` można usunąć
   (połączenia nieodświeżone w tym czasie trzeba będzie połączyć ponownie).
