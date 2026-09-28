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
jar="$(mktemp)"
trap 'rm -f "$jar" /tmp/smoke.body' EXIT

fail() { echo "SMOKE FAIL: $*" >&2; $compose logs --no-color >&2 || true; $compose down -v >/dev/null 2>&1 || true; exit 1; }

expect() { # expect <url> <status> [body-substring]
    body="$(curl -s -b "$jar" -c "$jar" -o /tmp/smoke.body -w '%{http_code}' "$1")" || fail "request to $1 failed"
    [ "$body" = "$2" ] || fail "$1 returned $body, expected $2"
    if [ -n "${3:-}" ]; then grep -q -- "$3" /tmp/smoke.body || fail "$1 body lacks: $3"; fi
    echo "ok  $2  $1"
}

field() { # field <name>: value of a hidden input on the last page
    sed -n "s/.*name=\"$1\" value=\"\([^\"]*\)\".*/\1/p" /tmp/smoke.body | head -n 1
}

# First-run setup through the real stack: session cookie, CSRF, Argon2id, DB writes.
setup_flow() { # setup_flow <base> <expected-redirect>
    expect "$1/" 303
    expect "$1/setup" 200 'Create your account'
    status="$(curl -s -b "$jar" -c "$jar" -o /tmp/smoke.body -w '%{http_code} %{redirect_url}' \
        --data-urlencode "csrf_name=$(field csrf_name)" --data-urlencode "csrf_value=$(field csrf_value)" \
        --data-urlencode 'username=smoke' --data-urlencode 'password=smoke test passphrase' \
        --data-urlencode 'password_confirm=smoke test passphrase' --data-urlencode 'units=uk' \
        --data-urlencode 'currency=GBP' --data-urlencode 'locale=en_GB' --data-urlencode 'timezone=Europe/London' \
        "$1/setup")"
    case "$status" in "303 "*"$2") echo "ok  303  POST $1/setup" ;; *) fail "setup returned: $status" ;; esac
    expect "$1/" 200 'Hello, smoke'
    expect "$1/garage" 200 'Your garage is empty'
    expect "$1/setup" 303
}

case "$variant" in
    pgsql)
        compose="docker compose -p logbook-smoke -f docker-compose.yml -f docker/smoke/compose.subpath.yml"
        $compose up -d --wait --no-build || fail "stack did not become healthy"
        base="http://localhost:$PROXY_PORT/logbook"
        expect "$base/health" 200 '"database":"ok"'
        expect "$base/diagnostics/deep/link" 200 'href="/logbook/"'
        expect "$base/assets/css/app.css" 200
        expect "http://localhost:$PROXY_PORT/stripped/diagnostics/deep/link" 200 'Deep link works'
        expect "$base/no/such/page" 404 'Page not found'
        setup_flow "$base" "/logbook/"
        # Hard refresh of a deep, signed-in link.
        expect "$base/vehicles/new" 200 'action="/logbook/vehicles/new"'
        # The fast fill-up path (no vehicle yet: offers to add one).
        expect "$base/fuel/new" 303
        ;;
    mysql)
        compose="docker compose -p logbook-smoke -f docker-compose.mysql.yml"
        $compose up -d --wait --no-build || fail "stack did not become healthy"
        base="http://localhost:$APP_PORT"
        expect "$base/health" 200 '"database":"ok"'
        expect "$base/diagnostics/deep/link" 200 'Deep link works'
        setup_flow "$base" "/"
        ;;
    *) echo "usage: $0 pgsql|mysql" >&2; exit 2 ;;
esac

# Reminders: the page works, and the entrypoint's scheduler has run the task.
expect "$base/reminders" 200 'Nothing coming up'
# Reports (Phase 5).
expect "$base/reports" 200 'Expenses &amp; reports'
i=0
until $compose logs app 2>&1 | grep -q 'Scheduled tasks: '; do
    i=$((i + 1)); [ "$i" -lt 30 ] || fail "the scheduled task never ran"; sleep 1
done
echo "ok  scheduler ran inside the container"
out="$($compose exec -T app setpriv --reuid=www-data --regid=www-data --init-groups php bin/run-scheduled-tasks.php -v)" \
    || fail "scheduled task exited non-zero"
case "$out" in *"1 account(s) checked"*) echo "ok  scheduled task (manual run): $out" ;; *) fail "scheduled task said: $out" ;; esac

# Restarting must be idempotent (migrations already applied) and keep sessions.
$compose restart app >/dev/null
$compose up -d --wait --no-build >/dev/null || fail "stack unhealthy after restart"
expect "$base/health" 200 '"status":"ok"'
expect "$base/" 200 'Hello, smoke'

$compose down -v >/dev/null
echo "smoke test ($variant) passed"
