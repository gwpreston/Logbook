#!/bin/sh
# End-to-end smoke test of the production image (used by CI; runnable locally).
#
#   bin/smoke-test.sh pgsql     # docker-compose.yml, app at /logbook behind nginx
#   bin/smoke-test.sh mysql     # docker-compose.mysql.yml, app at /
#   bin/smoke-test.sh header    # docker-compose.yml, /logbook behind nginx forward auth (header sign-in)
#   bin/smoke-test.sh demo      # docker-compose.yml with DEMO_MODE: seeded, signed in, reset by CLI, signed in again
#   bin/smoke-test.sh caddy | caddy-subpath | traefik | traefik-subpath
#                               # docker/examples/ (Phase 35.2) unchanged, over HTTPS on localhost, at / or /logbook
#
# Assumes the image logbook:local exists (docker build -t logbook:local .).
set -eu

cd "$(dirname "$0")/.."
variant="${1:-pgsql}"
export APP_PORT="${APP_PORT:-18080}" PROXY_PORT="${PROXY_PORT:-18081}"
curl_opts="" # -k for the proxy examples' own certificates
jar="$(mktemp)"
trap 'rm -f "$jar" /tmp/smoke.body /tmp/smoke.head' EXIT

fail() { echo "SMOKE FAIL: $*" >&2; $compose logs --no-color >&2 || true; $compose down -v >/dev/null 2>&1 || true; exit 1; }

expect() { # expect <url> <status> [body-substring]
    body="$(curl -s $curl_opts -b "$jar" -c "$jar" -o /tmp/smoke.body -w '%{http_code}' "$1")" || fail "request to $1 failed"
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
    status="$(curl -s $curl_opts -b "$jar" -c "$jar" -o /tmp/smoke.body -w '%{http_code} %{redirect_url}' \
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
        # Purchase and sale paperwork and the dated starting reading (Phase 12).
        expect "$base/vehicles/new" 200 'name="sale_attachments'
        expect "$base/vehicles/new" 200 'name="current_odometer_on"'
        expect "$base/history" 200 'Nothing logged yet'
        # The fast fill-up path (no vehicle yet: offers to add one).
        expect "$base/fuel/new" 303
        # Tyre change from Log entry (Phase 11.1): the same, at the subpath.
        expect "$base/log/new/tyre" 303
        # Check tread and Settings → Tyres (Phase 11.2), deep links at the subpath.
        expect "$base/log/new/tyre_check" 303
        expect "$base/settings/tyres" 200 'action="/logbook/settings/tyres"'
        # Installable app at the subpath.
        expect "$base/manifest.webmanifest" 200 '"start_url": "/logbook/"'
        expect "$base/sw.js" 200 '"base":"/logbook"'
        expect "$base/offline" 200 'href="/logbook/fuel/new"'
        # Backups: the page, a download, and the command line as www-data.
        expect "$base/settings/backup" 200 'href="/logbook/settings/backup/download"'
        expect "$base/settings/backup/download" 200 'manifest.json'
        $compose exec -T -u www-data app php bin/backup.php create >/dev/null || fail "bin/backup.php create failed"
        echo "ok  bin/backup.php create"
        ;;
    mysql)
        compose="docker compose -p logbook-smoke -f docker-compose.mysql.yml"
        $compose up -d --wait --no-build || fail "stack did not become healthy"
        base="http://localhost:$APP_PORT"
        expect "$base/health" 200 '"database":"ok"'
        expect "$base/diagnostics/deep/link" 200 'Deep link works'
        setup_flow "$base" "/"
        ;;
    header)
        # Header sign-in (Phase 23.2) behind docker/nginx/forward-auth-example.conf,
        # with a stub for Authelia: the smoke_user cookie is "signed in at the proxy".
        compose="docker compose -p logbook-smoke -f docker-compose.yml -f docker/smoke/compose.header.yml"
        $compose up -d --wait --no-build || fail "stack did not become healthy"
        base="http://localhost:$PROXY_PORT/logbook"
        direct="http://localhost:$APP_PORT/logbook"
        expect "$base/health" 200 '"database":"ok"'
        expect "$base/" 302
        # First run happens on the app itself: header sign-in waits for a user.
        setup_flow "$direct" "/logbook/"
        rm -f "$jar"
        # The header sent straight to the app (not from nginx's address) is ignored.
        curl -s -o /tmp/smoke.body -D /tmp/smoke.head -H 'Remote-User: smoke' "$direct/" >/dev/null
        grep -qi '^location: /logbook/login' /tmp/smoke.head || fail "a header from outside the proxy signed someone in"
        echo "ok  header from an untrusted address ignored"
        printf 'localhost\tFALSE\t/\tFALSE\t0\tsmoke_user\tsmoke\n' > "$jar"
        expect "$base/" 303
        expect "$base/" 200 'Hello, smoke'
        # nginx overwrites what a client sends, and drops the underscore spelling.
        status="$(curl -s -b "$jar" -c "$jar" -o /tmp/smoke.body -w '%{http_code}' \
            -H 'Remote-User: someone-else' -H 'Remote_User: someone-else' "$base/profile")"
        { [ "$status" = 200 ] && grep -q 'value="smoke"' /tmp/smoke.body; } \
            || fail "a client's own Remote-User reached the app ($status)"
        echo "ok  client Remote-User and Remote_User replaced by nginx"
        # Exempt from forward auth, and never signed in by the header.
        api_status="$(curl -s -o /dev/null -w '%{http_code}' -H 'Remote-User: smoke' "$base/api/v1/me")"
        [ "$api_status" = 401 ] || fail "the API answered $api_status without a key"
        echo "ok  401  API without a key, behind forward auth's exemption"
        # Switching user at the proxy ends the session.
        sed -i.bak 's/smoke_user\tsmoke$/smoke_user\tstranger/' "$jar" && rm -f "$jar.bak"
        expect "$base/" 303
        expect "$base/" 303
        expect "$base/login" 200 'isn&#039;t linked to Logbook'
        sed -i.bak 's/smoke_user\tstranger$/smoke_user\tsmoke/' "$jar" && rm -f "$jar.bak"
        expect "$base/" 303
        expect "$base/settings" 200 'action="/logbook/logout"'
        status="$(curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' \
            --data-urlencode "csrf_name=$(field csrf_name)" --data-urlencode "csrf_value=$(field csrf_value)" \
            "$base/logout")"
        [ "$status" = "303 http://auth.example.test/logout" ] || fail "sign-out answered: $status"
        echo "ok  303  sign-out goes to AUTH_PROXY_LOGOUT_URL"
        expect "$base/" 303
        expect "$base/" 200 'Hello, smoke'
        ;;
    demo)
        # Demo mode (Phase 35.1, spec.md §7.36) on the production image: an empty
        # database is seeded at start, `demo` signs in, what a visitor must not do
        # is refused, `bin/demo-reset.php` ends the session, and `demo` signs in
        # again. Its own flow: the shared steps below expect the first-run account.
        export DEMO_MODE=true DEMO_PASSWORD=smoke-demo-password
        compose="docker compose -p logbook-smoke -f docker-compose.yml"
        $compose up -d --wait --no-build || fail "stack did not become healthy"
        base="http://localhost:$APP_PORT"
        demo_sign_in() {
            expect "$base/login" 200 'data-demo-try'
            status="$(curl -s -b "$jar" -c "$jar" -o /tmp/smoke.body -w '%{http_code} %{redirect_url}' \
                --data-urlencode "csrf_name=$(field csrf_name)" --data-urlencode "csrf_value=$(field csrf_value)" \
                --data-urlencode 'username=demo' --data-urlencode "password=$DEMO_PASSWORD" "$base/login")"
            case "$status" in "303 "*) echo "ok  303  POST $base/login (demo)" ;; *) fail "demo sign-in returned: $status" ;; esac
        }
        expect "$base/health" 200 '"database":"ok"'
        expect "$base/setup" 404
        demo_sign_in
        expect "$base/" 200 'data-demo-banner'
        expect "$base/garage" 200 'Volkswagen'
        curl -s -D /tmp/smoke.head -o /dev/null "$base/" >/dev/null
        grep -qi '^x-robots-tag: noindex, nofollow' /tmp/smoke.head || fail "no X-Robots-Tag on the demo"
        echo "ok  X-Robots-Tag"
        expect "$base/settings/users" 403 'Not available in the demo'
        expect "$base/settings/backup" 403 'Not available in the demo'
        expect "$base/api/v1/me" 403
        # Put it back by hand: the session ends, the credentials still work.
        $compose exec -T -u www-data app php bin/demo-reset.php --yes || fail "bin/demo-reset.php failed"
        echo "ok  bin/demo-reset.php"
        expect "$base/garage" 303
        expect "$base/login?demo=reset" 200 'The demo was reset'
        demo_sign_in
        expect "$base/garage" 200 'Volkswagen'
        $compose down -v >/dev/null
        echo "smoke test ($variant) passed"
        exit 0
        ;;
    caddy|caddy-subpath|traefik|traefik-subpath)
        # The reverse-proxy examples (Phase 35.2, docs/reverse-proxies.md) run
        # unchanged on "localhost": Caddy issues its own certificate for it,
        # Traefik serves its default one (its overlay only drops the resolver,
        # so CI never asks Let's Encrypt). The checks nginx's case makes, over
        # HTTPS, plus the redirect from HTTP and a Secure session cookie.
        proxy="${variant%-subpath}"
        file="compose.yml" prefix=""
        [ "$variant" = "$proxy" ] || { file="compose.subpath.yml" prefix="/logbook"; }
        export LOGBOOK_DOMAIN=localhost HTTP_PORT="${HTTP_PORT:-18082}" HTTPS_PORT="${HTTPS_PORT:-18443}"
        compose="docker compose -p logbook-smoke -f docker-compose.yml -f docker/examples/$proxy/$file"
        [ "$proxy" = caddy ] || compose="$compose -f docker/smoke/compose.$variant.yml"
        curl_opts="-k"
        $compose up -d --wait --no-build || fail "stack did not become healthy"
        base="https://localhost:$HTTPS_PORT$prefix"
        i=0 # the proxy has started; Traefik reads the labels, Caddy issues the certificate
        until [ "$(curl -sk -o /dev/null -w '%{http_code}' "$base/health")" = 200 ]; do
            i=$((i + 1)); [ "$i" -lt 30 ] || fail "$base/health never answered 200"; sleep 1
        done
        expect "$base/health" 200 '"database":"ok"'
        status="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://localhost:$HTTP_PORT$prefix/garage")"
        case "$status" in
            30[178]" https://localhost"*"$prefix/garage") echo "ok  ${status%% *}  HTTP to HTTPS" ;;
            *) fail "http://localhost:$HTTP_PORT$prefix/garage answered: $status" ;;
        esac
        expect "$base/diagnostics/deep/link" 200 "href=\"$prefix/\""
        expect "$base/assets/css/app.css" 200
        expect "$base/no/such/page" 404 'Page not found'
        if [ -n "$prefix" ]; then
            status="$(curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "https://localhost:$HTTPS_PORT/logbook")"
            case "$status" in
                30[178]" "*"/logbook/") echo "ok  ${status%% *}  /logbook to /logbook/" ;;
                *) fail "/logbook answered: $status" ;;
            esac
            expect "https://localhost:$HTTPS_PORT/stripped/diagnostics/deep/link" 200 'Deep link works'
            expect "https://localhost:$HTTPS_PORT/stripped/assets/css/app.css" 200
        fi
        setup_flow "$base" "$prefix/"
        # APP_URL is https://, so the session cookie is Secure (SESSION_SECURE's default).
        awk -F '\t' '$6 != "" && $4 == "TRUE" { found = 1 } END { exit !found }' "$jar" \
            || { cat "$jar" >&2; fail "the session cookie is not Secure"; }
        echo "ok  session cookie Secure"
        # Hard refresh of a deep, signed-in link, and the installable app.
        expect "$base/vehicles/new" 200 "action=\"$prefix/vehicles/new\""
        expect "$base/manifest.webmanifest" 200 "\"start_url\": \"$prefix/\""
        $compose down -v >/dev/null
        echo "smoke test ($variant) passed"
        exit 0
        ;;
    *) echo "usage: $0 pgsql|mysql|header|demo|caddy|caddy-subpath|traefik|traefik-subpath" >&2; exit 2 ;;
esac

# Reminders: the page works, and the entrypoint's scheduler has run the task.
expect "$base/reminders" 200 'Nothing coming up'
# Reports (Phase 5).
expect "$base/reports" 200 'Expenses &amp; reports'
# Cost of ownership (Phase 14.2): a deep link at the subpath, and its CSV.
expect "$base/reports/ownership" 200 'Cost of ownership'
expect "$base/reports/ownership.csv" 200 'Owned from'
# Coming up (Phase 15): a deep link, its CSV and the dashboard widget.
expect "$base/upcoming" 200 'Nothing planned yet'
expect "$base/upcoming.csv" 200 'Expected cost'
i=0
until $compose logs app 2>&1 | grep -q 'Scheduled tasks: '; do
    i=$((i + 1)); [ "$i" -lt 30 ] || fail "the scheduled task never ran"; sleep 1
done
echo "ok  scheduler ran inside the container"
out="$($compose exec -T app setpriv --reuid=www-data --regid=www-data --init-groups php bin/run-scheduled-tasks.php -v)" \
    || fail "scheduled task exited non-zero"
case "$out" in *"1 account(s) checked"*) echo "ok  scheduled task (manual run): $out" ;; *) fail "scheduled task said: $out" ;; esac

# REST API (Phase 18.2): a key from the command line, then the API with it,
# which also proves the web server passes the Authorization header to PHP.
token="$($compose exec -T -u www-data app php bin/api-key.php create --user smoke --name Smoke --scope read)" \
    || fail "bin/api-key.php create failed"
case "$token" in lbk_*) echo "ok  bin/api-key.php create" ;; *) fail "bin/api-key.php printed: $token" ;; esac
api() { # api <path> <status> [body-substring] [token]
    status="$(curl -s -o /tmp/smoke.body -w '%{http_code}' ${4:+-H "Authorization: Bearer $4"} "$base/api/v1$1")" \
        || fail "request to $base/api/v1$1 failed"
    [ "$status" = "$2" ] || fail "$base/api/v1$1 returned $status, expected $2: $(cat /tmp/smoke.body)"
    if [ -n "${3:-}" ]; then grep -qF -- "$3" /tmp/smoke.body || fail "$base/api/v1$1 body lacks: $3"; fi
    echo "ok  $2  API $1"
}
api /vehicles 200 '"items":[]' "$token"
api /me 200 '"username":"smoke"' "$token"
api /vehicles 401 '"code":"missing_key"'
# A bad key is counted: the throttle's counter must be writable by www-data.
api /me 401 '"code":"invalid_key"' "lbk_0000000000000000000000000000000000000000000"
$compose exec -T app sh -c 'ls var/cache/api-throttle/*.json' >/dev/null || fail "the failed-key counter was not written"
echo "ok  failed-key counter written"
api /openapi.json 200 '"openapi":"3.1.0"'

# Writes, edits and deletes (Phase 39.2): POST, PATCH with If-Match and DELETE through the
# web server and proxy, at the root or the subpath.
rw="$($compose exec -T -u www-data app php bin/api-key.php create --user smoke --name Writes --scope read_write)" \
    || fail "bin/api-key.php create (read_write) failed"
send() { # send <method> <path> <status> [json] [extra header]
    status="$(curl -s -o /tmp/smoke.body -D /tmp/smoke.head -w '%{http_code}' -X "$1" -H "Authorization: Bearer $rw" \
        ${4:+-H 'Content-Type: application/json' -d "$4"} ${5:+-H "$5"} "$base/api/v1$2")" || fail "$1 $base/api/v1$2 failed"
    [ "$status" = "$3" ] || fail "$1 $base/api/v1$2 returned $status, expected $3: $(cat /tmp/smoke.body)"
    echo "ok  $3  API $1 $2"
}
send POST /vehicles 201 '{"type":"car","make":"Smoke","model":"Test","fuel_type":"petrol"}'
vid="$(sed -n 's/^{"entry":{"id":\([0-9]*\).*/\1/p' /tmp/smoke.body)"
[ -n "$vid" ] || fail "no vehicle id in $(cat /tmp/smoke.body)"
send POST "/vehicles/$vid/odometer" 201 '{"odometer":1000,"distance_unit":"km"}'
rid="$(sed -n 's/^{"entry":{"id":\([0-9]*\).*/\1/p' /tmp/smoke.body)"
send GET "/vehicles/$vid/odometer/$rid" 200
etag="$(sed -n 's/^[Ee][Tt][Aa][Gg]: *\(.*\)\r*$/\1/p' /tmp/smoke.head | tr -d '\r')"
[ -n "$etag" ] || fail "no ETag on the single read"
send PATCH "/vehicles/$vid/odometer/$rid" 412 '{"note":"stale"}' 'If-Match: "0000"'
send PATCH "/vehicles/$vid/odometer/$rid" 200 '{"note":"Smoke"}' "If-Match: $etag"
send DELETE "/vehicles/$vid/odometer/$rid" 204

# MCP server (Phase 26.5): the same key at /mcp, through the web server (and,
# in the header variant, nginx's forward-auth exemption).
mcp() { # mcp <status> <body-substring> [token]
    status="$(curl -s -o /tmp/smoke.body -w '%{http_code}' ${3:+-H "Authorization: Bearer $3"} \
        -H 'Content-Type: application/json' -H 'MCP-Protocol-Version: 2025-11-25' \
        -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$base/mcp")" || fail "request to $base/mcp failed"
    [ "$status" = "$1" ] || fail "$base/mcp returned $status, expected $1: $(cat /tmp/smoke.body)"
    grep -qF -- "$2" /tmp/smoke.body || fail "$base/mcp body lacks: $2"
    echo "ok  $1  MCP tools/list"
}
mcp 200 '"name":"find_vehicles"' "$token"
mcp 401 '"code":-31401'

# Restarting must be idempotent (migrations already applied) and keep sessions.
$compose restart app >/dev/null
$compose up -d --wait --no-build >/dev/null || fail "stack unhealthy after restart"
expect "$base/health" 200 '"status":"ok"'
expect "$base/" 200 'Hello, smoke'

$compose down -v >/dev/null
echo "smoke test ($variant) passed"
