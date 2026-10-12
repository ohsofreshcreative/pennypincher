#!/usr/bin/env bash
#
# Penny Pincher – wycofanie wdrożenia do kopii zapasowej utworzonej przez scripts/deploy.sh.
#
#   scripts/rollback.sh --list                 lista kopii na serwerze
#   scripts/rollback.sh <STAMP>                przywraca pliki motywu z theme-<STAMP>.tar.gz
#   scripts/rollback.sh <STAMP> --database     dodatkowo przywraca bazę z db-<STAMP>.sql.gz
#
# Przywrócenie bazy NADPISUJE dane zapisane po wykonaniu kopii (np. nowe importy i synchronizacje),
# dlatego wymaga osobnej flagi i potwierdzenia. Migracje Penny Pincher są addytywne, więc zwykle
# wystarczy przywrócić same pliki – starszy kod ignoruje nowe kolumny i tabele.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
THEME_SLUG="pennypincher"
COMPROMISED_FINGERPRINT="SHA256:G3BAf5zZ4Vff3QXCHmboFPmVKNlR5KSQ5Rlm8UlpVy0"

die() { printf '\n\033[31m✘ %s\033[0m\n' "$*" >&2; exit 1; }
ok()  { printf '  \033[32m✔\033[0m %s\n' "$*"; }

[[ -f "$ROOT/scripts/deploy.env" ]] || die "Brak scripts/deploy.env."
# shellcheck source=/dev/null
source "$ROOT/scripts/deploy.env"
DEPLOY_WP_CLI="${DEPLOY_WP_CLI:-wp}"

[[ "$(ssh-keygen -lf "$DEPLOY_SSH_KEY" 2>/dev/null | awk '{print $2}')" != "$COMPROMISED_FINGERPRINT" ]] || die "Ujawniony klucz SSH jest zablokowany."
SSH=(ssh -i "$DEPLOY_SSH_KEY" -p "$DEPLOY_PORT" -o IdentitiesOnly=yes -o BatchMode=yes "$DEPLOY_USER@$DEPLOY_HOST")
THEMES="$DEPLOY_WP_PATH/wp-content/themes"
WP="cd '$DEPLOY_WP_PATH' && $DEPLOY_WP_CLI"

if [[ "${1:-}" == "--list" || -z "${1:-}" ]]; then
	"${SSH[@]}" "ls -lh '$DEPLOY_BACKUP_DIR'"
	exit 0
fi

STAMP="$1"
WITH_DB="${2:-}"
[[ "$STAMP" =~ ^[0-9]{8}-[0-9]{6}$ ]] || die "Nieprawidłowy identyfikator kopii: $STAMP"
"${SSH[@]}" "test -f '$DEPLOY_BACKUP_DIR/theme-$STAMP.tar.gz'" || die "Brak theme-$STAMP.tar.gz na serwerze."

read -r -p "Przywrócić pliki motywu z $STAMP? Wpisz 'przywracam': " CONFIRM
[[ "$CONFIRM" == "przywracam" ]] || die "Przerwano."

# Rozpakowanie obok i zamiana katalogów – bieżąca wersja zostaje jako $THEME_SLUG.rollback-<czas>.
NOW="$(date -u +%Y%m%d-%H%M%S)"
"${SSH[@]}" "set -e; TMP=\$(mktemp -d '$THEMES/.pp-restore-XXXX'); tar -xzf '$DEPLOY_BACKUP_DIR/theme-$STAMP.tar.gz' -C \"\$TMP\"; \
	mv '$THEMES/$THEME_SLUG' '$THEMES/.$THEME_SLUG.rollback-$NOW'; mv \"\$TMP/$THEME_SLUG\" '$THEMES/$THEME_SLUG'; rmdir \"\$TMP\""
ok "pliki przywrócone (poprzednia wersja: wp-content/themes/.$THEME_SLUG.rollback-$NOW – usuń ją po sprawdzeniu)"

if [[ "$WITH_DB" == "--database" ]]; then
	"${SSH[@]}" "test -f '$DEPLOY_BACKUP_DIR/db-$STAMP.sql.gz'" || die "Brak db-$STAMP.sql.gz."
	read -r -p "UWAGA: przywrócenie bazy usunie zmiany zapisane po $STAMP. Wpisz 'nadpisuję bazę': " CONFIRM_DB
	[[ "$CONFIRM_DB" == "nadpisuję bazę" ]] || die "Baza nie została zmieniona."
	"${SSH[@]}" "$WP db export - --single-transaction --quiet | gzip > '$DEPLOY_BACKUP_DIR/db-before-rollback-$NOW.sql.gz'"
	ok "kopia bieżącej bazy: db-before-rollback-$NOW.sql.gz"
	"${SSH[@]}" "gunzip -c '$DEPLOY_BACKUP_DIR/db-$STAMP.sql.gz' | ($WP db import -)"
	ok "baza przywrócona z db-$STAMP.sql.gz"
fi

"${SSH[@]}" "$WP acorn optimize:clear" >/dev/null 2>&1 || true
ok "cache Acorn wyczyszczony"
