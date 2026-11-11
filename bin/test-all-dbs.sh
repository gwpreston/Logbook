#!/bin/sh
# Run the PHPUnit suite against SQLite, PostgreSQL, MySQL and MariaDB using the
# dev compose stack. Each engine: migrate → full rollback → migrate → phpunit.
#
#   bin/test-all-dbs.sh [sqlite|pgsql|mysql|mariadb ...]
set -eu

cd "$(dirname "$0")/.."
compose="docker compose -f docker-compose.dev.yml --profile all"
engines="${*:-sqlite pgsql mysql mariadb}"
server="-e TEST_DB_NAME=logbook_test -e TEST_DB_USER=logbook -e TEST_DB_PASSWORD=logbook"
# The tests choose their own SESSION_SECRET: the dev image's generated one (Phase 36.1) must not leak in.
nosecret="-e SESSION_SECRET= -e SESSION_SECRET_FILE="

# Every test container this script starts is named logbook-test-<engine>, so a
# run can tell when another one is still going. Two runs at once share the
# bind-mounted var/ (the SQLite file and var/cache) and wipe each other's data
# mid-suite: tests error from then on and the run stalls (K198).
prefix=logbook-test-
running="$(docker ps --filter "name=^${prefix}" --format '{{.Names}}')"
if [ -n "$running" ]; then
    echo "Another test run is still going: $(echo "$running" | tr '\n' ' ')" >&2
    echo "Wait for it, or stop it with: docker rm -f $(echo "$running" | tr '\n' ' ')" >&2
    exit 1
fi

# Interrupting the script (Ctrl-C, kill, a closed terminal) must also stop the
# container it started: `docker compose run` leaves it running otherwise, and
# that orphan then clashes with the next run (K198).
current=""
child=""
stop_current() {
    if [ -n "$current" ]; then
        docker rm -f "$current" >/dev/null 2>&1 || true
    fi
    if [ -n "$child" ]; then
        kill "$child" 2>/dev/null || true
    fi
}
trap 'stop_current; exit 130' INT
trap 'stop_current; exit 143' TERM HUP
trap 'stop_current' EXIT

$compose up -d --wait pgsql mysql mariadb >/dev/null

# A fresh logbook_test per engine (K155): a database an earlier run left with a second user makes
# the 2.0 migration refuse the rollback. The dev stack's init scripts own and grant this database.
recreate() {
    case "$1" in
        pgsql)
            $compose exec -T pgsql psql -q -U logbook -d logbook \
                -c 'DROP DATABASE IF EXISTS logbook_test WITH (FORCE)' \
                -c 'CREATE DATABASE logbook_test OWNER logbook' ;;
        mysql|mariadb)
            sql='DROP DATABASE IF EXISTS logbook_test; CREATE DATABASE logbook_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            $compose exec -T -e MYSQL_PWD=logbook "$1" "$([ "$1" = mysql ] && echo mysql || echo mariadb)" -ulogbook -e "$sql" ;;
    esac
}

for engine in $engines; do
    case "$engine" in
        # Its own SQLite file, not var/testing.sqlite, so a native `composer test`
        # (or a hook running one) on the host doesn't delete it mid-run (K198).
        sqlite)  env="-e TEST_DB_DRIVER=sqlite -e TEST_DB_NAME=var/testing-all-dbs.sqlite" ;;
        pgsql)   env="-e TEST_DB_DRIVER=pgsql -e TEST_DB_HOST=pgsql $server" ;;
        mysql)   env="-e TEST_DB_DRIVER=mysql -e TEST_DB_HOST=mysql $server" ;;
        mariadb) env="-e TEST_DB_DRIVER=mysql -e TEST_DB_HOST=mariadb $server" ;;
        *) echo "unknown engine: $engine" >&2; exit 2 ;;
    esac
    echo "=== $engine ==="
    recreate "$engine"
    current="${prefix}${engine}"
    # Run in the background and `wait` for it: sh holds a trap until a
    # foreground command ends (the whole suite), but `wait` returns at once
    # on a signal, so the trap can stop the container straight away.
    # shellcheck disable=SC2086
    $compose run --rm --no-deps --name "$current" $env $nosecret app sh -c '
        vendor/bin/phinx migrate -e testing -q &&
        vendor/bin/phinx rollback -e testing -t 0 -q &&
        vendor/bin/phinx migrate -e testing -q &&
        vendor/bin/phpunit' &
    child=$!
    status=0
    wait "$child" || status=$?
    child=""
    current=""
    if [ "$status" -ne 0 ]; then
        exit "$status"
    fi
done
