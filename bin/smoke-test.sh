#!/bin/sh
# End-to-end smoke test of the production image (used by CI; runnable locally).
#
#   bin/smoke-test.sh pgsql     # docker-compose.yml, app at /logbook behind nginx
#   bin/smoke-test.sh mysql     # docker-compose.mysql.yml, app at /
#
# Assumes the image logbook:local exists (docker build -t logbook:local .).
set -eu

cd "$(dirname "$0")/.."
variant="${1:-pgsql}"
export APP_PORT="${APP_PORT:-18080}" PROXY_PORT="${PROXY_PORT:-18081}"

fail() { echo "SMOKE FAIL: $*" >&2; $compose logs --no-color >&2 || true; $compose down -v >/dev/null 2>&1 || true; exit 1; }

expect() { # expect <url> <status> [body-substring]
    body="$(curl -s -o /tmp/smoke.body -w '%{http_code}' "$1")" || fail "request to $1 failed"
    [ "$body" = "$2" ] || fail "$1 returned $body, expected $2"
    if [ -n "${3:-}" ]; then grep -q -- "$3" /tmp/smoke.body || fail "$1 body lacks: $3"; fi
    echo "ok  $2  $1"
}

case "$variant" in
    pgsql)
        compose="docker compose -p logbook-smoke -f docker-compose.yml -f docker/smoke/compose.subpath.yml"
        $compose up -d --wait --no-build || fail "stack did not become healthy"
        base="http://localhost:$PROXY_PORT/logbook"
        expect "$base/health" 200 '"database":"ok"'
        expect "$base/" 200 'Welcome to Logbook'
        expect "$base/diagnostics/deep/link" 200 'href="/logbook/"'
        expect "$base/assets/css/app.css" 200
        expect "http://localhost:$PROXY_PORT/stripped/diagnostics/deep/link" 200 'Deep link works'
        expect "$base/no/such/page" 404 'Page not found'
        ;;
    mysql)
        compose="docker compose -p logbook-smoke -f docker-compose.mysql.yml"
        $compose up -d --wait --no-build || fail "stack did not become healthy"
        base="http://localhost:$APP_PORT"
        expect "$base/health" 200 '"database":"ok"'
        expect "$base/" 200 'Welcome to Logbook'
        expect "$base/diagnostics/deep/link" 200 'Deep link works'
        ;;
    *) echo "usage: $0 pgsql|mysql" >&2; exit 2 ;;
esac

# Restarting must be idempotent (migrations already applied).
$compose restart app >/dev/null
$compose up -d --wait --no-build >/dev/null || fail "stack unhealthy after restart"
expect "$base/health" 200 '"status":"ok"'

$compose down -v >/dev/null
echo "smoke test ($variant) passed"
