#!/usr/bin/env bash
#
# Penny Pincher – wdrożenie motywu (aplikacji) na istniejącego WordPressa na Hostingerze.
#
# Co robi:
#   1. buduje czystą paczkę z commita (git archive) + composer --no-dev + build Vite,
#   2. sprawdza serwer TYLKO DO ODCZYTU (WordPress, PHP, WP-CLI, baza, stała szyfrowania) i pokazuje podsumowanie,
#   3. po potwierdzeniu: kopia zapasowa motywu i eksport bazy na serwerze, wgranie plików, migracje (addytywne),
#   4. weryfikacja HTTP (logowanie, ochrona /app/, blokada plików motywu, XML-RPC).
#
# Czego NIE robi: nie instaluje WordPressa, nie kopiuje lokalnej bazy, nie zmienia wp-config.php,
# nie usuwa danych w bazie, nie dotyka kampanii Google Ads.
#
# Użycie:
#   scripts/deploy.sh --check          tylko budowa paczki i kontrola serwera (bez zmian na serwerze)
#   scripts/deploy.sh                  pełne wdrożenie (pyta o potwierdzenie)
#   scripts/deploy.sh --ref <commit>   wdrożenie wskazanego commita/tagu (domyślnie HEAD)
#
# Konfiguracja: scripts/deploy.env (wzór: scripts/deploy.env.example). Wycofanie: scripts/rollback.sh.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
THEME_SLUG="pennypincher"
# Odcisk klucza SSH ujawnionego w historii repozytorium – nie wolno go używać do wdrożeń.
COMPROMISED_FINGERPRINT="SHA256:G3BAf5zZ4Vff3QXCHmboFPmVKNlR5KSQ5Rlm8UlpVy0"

MODE="deploy"
REF="HEAD"

while [[ $# -gt 0 ]]; do
	case "$1" in
		--check) MODE="check" ;;
		--ref) REF="$2"; shift ;;
		-h|--help) sed -n '2,22p' "$0"; exit 0 ;;
		*) echo "Nieznana opcja: $1" >&2; exit 2 ;;
	esac
	shift
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✔\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✘ %s\033[0m\n' "$*" >&2; exit 1; }

[[ -f "$ROOT/scripts/deploy.env" ]] || die "Brak scripts/deploy.env – skopiuj scripts/deploy.env.example i uzupełnij."
# shellcheck source=/dev/null
source "$ROOT/scripts/deploy.env"

for var in DEPLOY_HOST DEPLOY_PORT DEPLOY_USER DEPLOY_WP_PATH DEPLOY_URL DEPLOY_SSH_KEY DEPLOY_BACKUP_DIR; do
	[[ -n "${!var:-}" ]] || die "Brak zmiennej $var w scripts/deploy.env."
done
DEPLOY_WP_CLI="${DEPLOY_WP_CLI:-wp}"
THEME_PATH="$DEPLOY_WP_PATH/wp-content/themes/$THEME_SLUG"

# --- Bezpieczeństwo klucza SSH -------------------------------------------------------------
say "Klucz SSH"
KEY_REAL="$(cd "$(dirname "$DEPLOY_SSH_KEY")" 2>/dev/null && pwd)/$(basename "$DEPLOY_SSH_KEY")"
[[ -f "$KEY_REAL" ]] || die "Nie znaleziono klucza $DEPLOY_SSH_KEY."
case "$KEY_REAL" in
	"$ROOT"/*) die "Klucz SSH leży w repozytorium ($KEY_REAL). Użyj nowego klucza spoza repozytorium (docs/SECURITY.md → Rotacja klucza SSH)." ;;
esac
FINGERPRINT="$(ssh-keygen -lf "$KEY_REAL" 2>/dev/null | awk '{print $2}')"
[[ "$FINGERPRINT" != "$COMPROMISED_FINGERPRINT" ]] || die "Ten klucz został ujawniony w historii repozytorium i jest zablokowany. Wykonaj rotację klucza (docs/SECURITY.md)."
ok "klucz spoza repozytorium, odcisk $FINGERPRINT"

SSH=(ssh -i "$KEY_REAL" -p "$DEPLOY_PORT" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 "$DEPLOY_USER@$DEPLOY_HOST")
remote() { "${SSH[@]}" "$@"; }

# --- Budowa paczki ----------------------------------------------------------------------------
say "Budowa paczki z $REF"
command -v composer >/dev/null || die "Brak composera lokalnie."
command -v yarn >/dev/null || die "Brak yarn lokalnie."
COMMIT="$(git -C "$ROOT" rev-parse --short "$REF")"
[[ -z "$(git -C "$ROOT" status --porcelain)" ]] || warn "Masz niezacommitowane zmiany – wdrażany jest commit $COMMIT, nie stan roboczy."

BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
git -C "$ROOT" archive "$REF" | tar -x -C "$BUILD"

# Pliki, które nigdy nie trafiają na serwer.
rm -rf "$BUILD/key" "$BUILD/key.pub" "$BUILD/deploy" "$BUILD/tests" "$BUILD/scripts" "$BUILD/.yarn" \
	"$BUILD/AGENTS.md" "$BUILD/CLAUDE.md" "$BUILD/PRZENOSINY.md" "$BUILD/pnpm-lock.yaml" "$BUILD/pnpm-workspace.yaml" "$BUILD/package-lock.json"

# ext-imagick jest wymagane przez spatie/pdf-to-image (część motywu, nie aplikacji) – sprawdzamy je na serwerze, nie lokalnie.
(cd "$BUILD" && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --ignore-platform-req=ext-imagick --quiet)
(cd "$BUILD" && yarn install --frozen-lockfile --silent && yarn build >/dev/null)
rm -rf "$BUILD/node_modules"
[[ -f "$BUILD/public/build/manifest.json" ]] || die "Build Vite nie utworzył public/build/manifest.json."
[[ -f "$BUILD/.htaccess" ]] || die "Brak .htaccess motywu w paczce."
echo "$COMMIT" > "$BUILD/REVISION"
ok "paczka gotowa ($(du -sh "$BUILD" | cut -f1)), commit $COMMIT"

# --- Kontrola serwera (tylko odczyt) -----------------------------------------------------------
say "Kontrola serwera (bez zmian)"
remote "test -f '$DEPLOY_WP_PATH/wp-config.php'" || die "Na serwerze nie ma wp-config.php w $DEPLOY_WP_PATH – nie instaluję nowego WordPressa."
ok "istniejąca instalacja WordPressa"

WP="cd '$DEPLOY_WP_PATH' && $DEPLOY_WP_CLI"
remote "$WP --version" >/dev/null || die "WP-CLI ($DEPLOY_WP_CLI) nie działa na serwerze."
ok "WP-CLI: $(remote "$WP --version")"

PHP_VERSION="$(remote "php -r 'echo PHP_VERSION;'")"
remote "php -r 'exit(version_compare(PHP_VERSION, \"8.2.0\", \">=\") ? 0 : 1);'" || die "PHP $PHP_VERSION – wymagane co najmniej 8.2 (zmień w hPanelu)."
ok "PHP $PHP_VERSION"
remote "php -m" | grep -qi '^sodium$' || die "Brak rozszerzenia PHP sodium (szyfrowanie tokenów)."
ok "rozszerzenie sodium"
remote "php -m" | grep -qi '^imagick$' && ok "rozszerzenie imagick" || warn "Brak rozszerzenia imagick (wymagane przez spatie/pdf-to-image w motywie)."

remote "$WP db query 'SELECT 1' --skip-column-names" >/dev/null || die "WP-CLI nie łączy się z bazą danych."
ok "połączenie z bazą danych"

ACTIVE_THEME="$(remote "$WP option get stylesheet" 2>/dev/null || true)"
SCHEMA="$(remote "$WP option get pp_schema_version" 2>/dev/null || echo 'brak (pierwsze wdrożenie)')"
THEME_EXISTS="$(remote "test -d '$THEME_PATH' && echo tak || echo nie")"
ok "aktywny motyw: ${ACTIVE_THEME:-?}, katalog motywu istnieje: $THEME_EXISTS, wersja schematu Penny Pincher: $SCHEMA"

for constant in PP_ENCRYPTION_KEY DISALLOW_FILE_EDIT; do
	if remote "$WP config has $constant" >/dev/null 2>&1; then ok "wp-config: $constant ustawione"; else warn "wp-config: brak $constant (docs/DEPLOYMENT.md)"; fi
done
for constant in PP_GOOGLE_ADS_CLIENT_ID PP_GOOGLE_ADS_CLIENT_SECRET; do
	if remote "$WP config has $constant" >/dev/null 2>&1; then ok "wp-config: $constant ustawione"; else warn "wp-config: brak $constant – integracja Google Ads będzie nieaktywna"; fi
done
DEBUG_DISPLAY="$(remote "$WP config get WP_DEBUG_DISPLAY" 2>/dev/null || echo '')"
[[ "$DEBUG_DISPLAY" == "1" || "$DEBUG_DISPLAY" == "true" ]] && warn "WP_DEBUG_DISPLAY jest włączone – na produkcji wyłącz (błędy nie mogą być wyświetlane)."
[[ "$(remote "$WP option get users_can_register" 2>/dev/null || echo 0)" == "0" ]] || warn "users_can_register=1 w bazie (aplikacja i tak wymusza 0)."

if [[ "$MODE" == "check" ]]; then
	say "Tryb --check: nic nie zostało zmienione na serwerze."
	exit 0
fi

echo
read -r -p "Wdrożyć commit $COMMIT do $THEME_PATH ($DEPLOY_URL)? Wpisz 'wdrażam': " CONFIRM
[[ "$CONFIRM" == "wdrażam" ]] || die "Przerwano."

# --- Kopie zapasowe ------------------------------------------------------------------------------
STAMP="$(date -u +%Y%m%d-%H%M%S)"
say "Kopie zapasowe na serwerze ($DEPLOY_BACKUP_DIR)"
remote "mkdir -p '$DEPLOY_BACKUP_DIR' && chmod 700 '$DEPLOY_BACKUP_DIR'"
if [[ "$THEME_EXISTS" == "tak" ]]; then
	remote "tar -C '$(dirname "$THEME_PATH")' -czf '$DEPLOY_BACKUP_DIR/theme-$STAMP.tar.gz' '$THEME_SLUG'"
	ok "motyw: theme-$STAMP.tar.gz"
fi
remote "$WP db export - --single-transaction --quiet | gzip > '$DEPLOY_BACKUP_DIR/db-$STAMP.sql.gz' && chmod 600 '$DEPLOY_BACKUP_DIR/db-$STAMP.sql.gz'"
ok "baza danych: db-$STAMP.sql.gz (eksport, baza nie jest zmieniana)"
# Zostawiamy 10 najnowszych kopii każdego rodzaju.
remote "cd '$DEPLOY_BACKUP_DIR' && ls -1t theme-*.tar.gz 2>/dev/null | tail -n +11 | xargs -r rm -f; ls -1t db-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f"

# --- Wgranie plików ------------------------------------------------------------------------------
say "Wgrywanie plików"
remote "mkdir -p '$THEME_PATH'"
rsync -az --delete --delay-updates --chmod=D755,F644 \
	-e "ssh -i '$KEY_REAL' -p $DEPLOY_PORT -o IdentitiesOnly=yes -o BatchMode=yes" \
	"$BUILD/" "$DEPLOY_USER@$DEPLOY_HOST:$THEME_PATH/"
ok "pliki motywu zsynchronizowane"

UPLOADS="$DEPLOY_WP_PATH/wp-content/uploads"
if remote "test -d '$UPLOADS' && test ! -e '$UPLOADS/.htaccess'"; then
	scp -q -i "$KEY_REAL" -P "$DEPLOY_PORT" -o IdentitiesOnly=yes "$ROOT/scripts/server/uploads.htaccess" "$DEPLOY_USER@$DEPLOY_HOST:$UPLOADS/.htaccess"
	ok "dodano blokadę PHP w wp-content/uploads"
else
	warn "wp-content/uploads/.htaccess już istnieje – nie nadpisuję (porównaj ze scripts/server/uploads.htaccess)"
fi

# --- Migracje i cache ----------------------------------------------------------------------------
say "Migracje i cache"
if [[ "$ACTIVE_THEME" != "$THEME_SLUG" ]]; then
	warn "Motyw $THEME_SLUG nie jest aktywny – aktywuj go w panelu (Wygląd → Motywy) lub: $DEPLOY_WP_CLI theme activate $THEME_SLUG, potem uruchom ponownie skrypt."
else
	remote "$WP acorn optimize:clear" >/dev/null 2>&1 || true
	remote "$WP acorn pp:migrate"
	remote "$WP acorn pp:google-ads:check" || warn "Konfiguracja Google Ads niekompletna (integracja nieaktywna do czasu uzupełnienia)."
fi

# --- Weryfikacja HTTP ----------------------------------------------------------------------------
say "Weryfikacja $DEPLOY_URL"
status() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$@"; }
check() { local name="$1" expected="$2" actual="$3"; if [[ " $expected " == *" $actual "* ]]; then ok "$name ($actual)"; else warn "$name: oczekiwano $expected, jest $actual"; FAILED=1; fi; }
FAILED=0
THEME_URL="$DEPLOY_URL/wp-content/themes/$THEME_SLUG"

check "HTTPS strony logowania /app/login/" "200" "$(status "$DEPLOY_URL/app/login/")"
check "/app/ bez logowania przekierowuje" "302" "$(status "$DEPLOY_URL/app/")"
check "HTTP przekierowuje na HTTPS" "301 302 307 308" "$(status "${DEPLOY_URL/https:/http:}/app/login/")"
check "blokada composer.json motywu" "403 404" "$(status "$THEME_URL/composer.json")"
check "blokada kodu aplikacji" "403 404" "$(status "$THEME_URL/app/PennyPincher/Services/AuditService.php")"
check "blokada pliku key" "403 404" "$(status "$THEME_URL/key")"
check "blokada .git" "403 404" "$(status "$THEME_URL/.git/config")"
check "XML-RPC wyłączony" "403 404 405" "$(status -X POST "$DEPLOY_URL/xmlrpc.php")"
check "REST /wp/v2/users bez logowania" "401 403 404" "$(status "$DEPLOY_URL/wp-json/wp/v2/users")"
check "wp-config.php niedostępny" "200 403 404" "$(status "$DEPLOY_URL/wp-config.php")"
LOGIN_HEADERS="$(curl -sI --max-time 20 "$DEPLOY_URL/app/login/")"
grep -qi '^strict-transport-security' <<<"$LOGIN_HEADERS" && ok "nagłówek HSTS" || { warn "brak nagłówka HSTS"; FAILED=1; }
grep -qi '^x-frame-options' <<<"$LOGIN_HEADERS" && ok "nagłówek X-Frame-Options" || { warn "brak X-Frame-Options"; FAILED=1; }

say "Gotowe: commit $COMMIT, kopie: theme-$STAMP.tar.gz, db-$STAMP.sql.gz"
[[ "$FAILED" == "0" ]] || warn "Część kontroli nie przeszła – sprawdź powyżej. Wycofanie: scripts/rollback.sh $STAMP"
