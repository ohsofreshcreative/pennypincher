#!/usr/bin/env bash
# Testy HTTP formularzy Promptu 5 – WYŁĄCZNIE lokalnie, na jednorazowej kopii bazy i atrapie Google Ads.
#
#   SOURCE_DB=fresh TEST_DB=pp_httptest PP_HTTP_PROJECT=31 WP_ROOT=/srv/pp ROUTER=/srv/router.php \
#   PP_HTTP_PASS=… PP_HTTP_MEMBER=eve PP_HTTP_MEMBER_PASS=… tests/Http/run.sh
#
# Wymaga: mysql/mysqldump z dostępem root (lokalnie), PHP z curl, router WordPressa dla serwera PHP,
# lokalny wp-config.php biorący WP_HOME/WP_SITEURL z PP_WP_HOME (adres serwera testowego) i PP_DB (nazwa bazy).
# Skrypt tworzy kopię bazy, łączy projekt z atrapą konta Google Ads, uruchamia serwer PHP z tymczasową wtyczką
# must-use atrapy (usuwaną na końcu) i wysyła prawdziwe formularze. Nic nie wychodzi do Google.
set -euo pipefail
THEME="$(cd "$(dirname "$0")/../.." && pwd)"
WP_ROOT="${WP_ROOT:-$(cd "$THEME/../../.." && pwd)}"
ROUTER="${ROUTER:?Podaj router serwera PHP dla WordPressa}"
SOURCE_DB="${SOURCE_DB:?Podaj lokalną bazę źródłową}"
TEST_DB="${TEST_DB:-pp_httptest}"
PORT="${PORT:-8081}"
[[ "$TEST_DB" == *test* ]] || { echo "TEST_DB musi zawierać „test”."; exit 1; }

export PP_FAKE_GOOGLE_ADS_STATE="${PP_FAKE_GOOGLE_ADS_STATE:-$(mktemp)}"
export PP_GOOGLE_ADS_CLIENT_ID="http-test.apps.googleusercontent.com" PP_GOOGLE_ADS_CLIENT_SECRET="http-test-secret"
PP_ENCRYPTION_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
export PP_ENCRYPTION_KEY
unset PP_GOOGLE_ADS_WRITE_MODE PP_GOOGLE_ADS_API_VALIDATION

MU="$WP_ROOT/wp-content/mu-plugins/pp-http-fake.php"
SERVER=""
cleanup() {
	[[ -n "$SERVER" ]] && kill "$SERVER" 2>/dev/null || true
	rm -f "$MU" "$PP_FAKE_GOOGLE_ADS_STATE"
	mysql -uroot -e "DROP DATABASE IF EXISTS \`$TEST_DB\`"
}
trap cleanup EXIT

mysql -uroot -e "DROP DATABASE IF EXISTS \`$TEST_DB\`; CREATE DATABASE \`$TEST_DB\`"
mysqldump -uroot "$SOURCE_DB" | mysql -uroot "$TEST_DB"
SETUP="$(cd "$WP_ROOT" && PP_WP_HOME="http://127.0.0.1:$PORT" PP_DB="$TEST_DB" php "$THEME/tests/Http/setup.php" | tail -1)"
echo "setup: $SETUP"
[[ "$SETUP" == \{* ]] || { echo "Przygotowanie bazy testowej nie powiodło się."; exit 1; }

mkdir -p "$WP_ROOT/wp-content/mu-plugins"
printf '<?php\nrequire %s;\n' "'$THEME/tests/Http/fake-google-ads.php'" > "$MU"
# Zmienne przekazane jawnie w poleceniu: wbudowany serwer PHP nie udostępnia skryptom zmiennych tylko eksportowanych.
if curl -s -o /dev/null "http://127.0.0.1:$PORT/"; then echo "Port $PORT jest zajęty – zatrzymaj poprzedni serwer testowy."; exit 1; fi
(cd "$WP_ROOT" && exec env PP_WP_HOME="http://127.0.0.1:$PORT" PP_DB="$TEST_DB" PP_FAKE_GOOGLE_ADS=1 PP_FAKE_GOOGLE_ADS_STATE="$PP_FAKE_GOOGLE_ADS_STATE" \
	PP_ENCRYPTION_KEY="$PP_ENCRYPTION_KEY" PP_GOOGLE_ADS_CLIENT_ID="$PP_GOOGLE_ADS_CLIENT_ID" PP_GOOGLE_ADS_CLIENT_SECRET="$PP_GOOGLE_ADS_CLIENT_SECRET" php -S "127.0.0.1:$PORT" "$ROUTER" >/tmp/pp-http-server.log 2>&1) &
SERVER=$!
sleep 2
PP_HTTP_BASE="http://127.0.0.1:$PORT" PP_HTTP_SETUP="$SETUP" php "$THEME/tests/Http/forms_test.php"
