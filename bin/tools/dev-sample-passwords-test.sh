#!/usr/bin/env bash
#
# Checks bin/dev-setup.sh --with-sample-data (spec.md §10 *Development
# stack*, Phase 33.1): two runs print two different pairs of passwords, the
# latest pair signs in, the earlier one no longer does, and a forgotten-
# password email for `demo` reaches Mailpit. Runs the real dev stack on
# SQLite, so it needs Docker; it leaves the stack running.
#
#   bin/tools/dev-sample-passwords-test.sh
#
set -euo pipefail
cd "$(dirname "$0")/../.."

PORT="${APP_PORT:-8090}"
BASE="http://localhost:$PORT"
MAILPIT="http://localhost:${MAILPIT_PORT:-8025}"
JAR="$(mktemp "${TMPDIR:-/tmp}/logbook-jar.XXXXXX")"
trap 'rm -f "$JAR"' EXIT

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

# Prints the demo password a run reported.
run_setup() {
    ./bin/dev-setup.sh --sqlite --with-sample-data --yes "$@" | sed 's/\x1b\[[0-9;]*m//g' \
        | sed -n 's/.*Sign in with *demo \/ \([A-Za-z0-9]\{20\}\).*/\1/p'
}

# HTTP status of signing in as $1 with $2 (303 = signed in).
sign_in() {
    rm -f "$JAR"
    local page name token
    page="$(curl -s -c "$JAR" "$BASE/login")"
    name="$(printf '%s' "$page" | sed -n 's/.*name="csrf_name" value="\([^"]*\)".*/\1/p' | head -1)"
    token="$(printf '%s' "$page" | sed -n 's/.*name="csrf_value" value="\([^"]*\)".*/\1/p' | head -1)"
    curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
        --data-urlencode "csrf_name=$name" --data-urlencode "csrf_value=$token" \
        --data-urlencode "username=$1" --data-urlencode "password=$2" "$BASE/login"
}

first="$(run_setup --reset)"
[ "${#first}" -eq 20 ] || fail "the first run printed no password"
second="$(run_setup)"
[ "${#second}" -eq 20 ] || fail "the second run printed no password"
[ "$first" != "$second" ] || fail "both runs printed the same password"
grep -q "$second" var/dev-credentials || fail "var/dev-credentials does not hold the latest password"
[ "$(stat -f '%Lp' var/dev-credentials 2>/dev/null || stat -c '%a' var/dev-credentials)" = "600" ] \
    || fail "var/dev-credentials is not mode 600"

[ "$(sign_in demo "$second")" = "303" ] || fail "the latest password does not sign in"
[ "$(sign_in demo "$first")" = "422" ] || fail "the earlier password still signs in"

# A forgotten-password email for demo lands in Mailpit.
curl -s -X DELETE "$MAILPIT/api/v1/messages" >/dev/null
rm -f "$JAR"
page="$(curl -s -c "$JAR" "$BASE/forgot-password")"
name="$(printf '%s' "$page" | sed -n 's/.*name="csrf_name" value="\([^"]*\)".*/\1/p' | head -1)"
token="$(printf '%s' "$page" | sed -n 's/.*name="csrf_value" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf_name=$name" --data-urlencode "csrf_value=$token" \
    --data-urlencode "login=demo" "$BASE/forgot-password"
sleep 2
curl -s "$MAILPIT/api/v1/messages" | grep -q 'Reset your Logbook password' || fail "no reset email in Mailpit"

printf 'OK: two runs, two passwords (%s… then %s…); the latest signs in; the reset email is in Mailpit.\n' \
    "${first:0:4}" "${second:0:4}"
