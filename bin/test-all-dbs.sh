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

$compose up -d --wait pgsql mysql mariadb >/dev/null

for engine in $engines; do
    case "$engine" in
        sqlite)  env="-e TEST_DB_DRIVER=sqlite" ;;
        pgsql)   env="-e TEST_DB_DRIVER=pgsql -e TEST_DB_HOST=pgsql $server" ;;
        mysql)   env="-e TEST_DB_DRIVER=mysql -e TEST_DB_HOST=mysql $server" ;;
        mariadb) env="-e TEST_DB_DRIVER=mysql -e TEST_DB_HOST=mariadb $server" ;;
        *) echo "unknown engine: $engine" >&2; exit 2 ;;
    esac
    echo "=== $engine ==="
    # shellcheck disable=SC2086
    $compose run --rm --no-deps $env $nosecret app sh -c '
        vendor/bin/phinx migrate -e testing -q &&
        vendor/bin/phinx rollback -e testing -t 0 -q &&
        vendor/bin/phinx migrate -e testing -q &&
        vendor/bin/phpunit'
done
