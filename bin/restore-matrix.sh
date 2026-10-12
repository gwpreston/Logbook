#!/bin/sh
# The restore matrix (Phase 45.2, spec.md §7.13): the golden household backed
# up on each engine and restored on every engine, compared row by row, file by
# file and figure by figure (tests/Integration/Household/HouseholdRestoreTest).
#
#   bin/restore-matrix.sh                 # all four engines: 16 restores
#   bin/restore-matrix.sh sqlite pgsql    # just these (4 restores)
#
# Runs in the dev stack (docker-compose.dev.yml), like bin/test-all-dbs.sh.
# The backups and snapshots are left in var/restore-matrix/.
set -eu

cd "$(dirname "$0")/.."
compose="docker compose -f docker-compose.dev.yml --profile all"
engines="${*:-sqlite pgsql mysql mariadb}"
server="-e TEST_DB_NAME=logbook_test -e TEST_DB_USER=logbook -e TEST_DB_PASSWORD=logbook"
nosecret="-e SESSION_SECRET= -e SESSION_SECRET_FILE="
dir=var/restore-matrix

rm -rf "$dir"
mkdir -p "$dir"
$compose up -d --wait pgsql mysql mariadb >/dev/null

env_for() {
    case "$1" in
        sqlite)  echo "-e TEST_DB_DRIVER=sqlite" ;;
        pgsql)   echo "-e TEST_DB_DRIVER=pgsql -e TEST_DB_HOST=pgsql $server" ;;
        mysql)   echo "-e TEST_DB_DRIVER=mysql -e TEST_DB_HOST=mysql $server" ;;
        mariadb) echo "-e TEST_DB_DRIVER=mysql -e TEST_DB_HOST=mariadb $server" ;;
        *) echo "unknown engine: $1" >&2; exit 2 ;;
    esac
}

for engine in $engines; do
    echo "=== back up on $engine ==="
    # shellcheck disable=SC2046,SC2086
    $compose run --rm --no-deps $(env_for "$engine") $nosecret -e RESTORE_MATRIX_OUT="$dir" app sh -c '
        vendor/bin/phinx migrate -e testing -q &&
        vendor/bin/phpunit --filter testTheHouseholdRestoresExactlyThroughThePages tests/Integration/Household/HouseholdRestoreTest.php'
done

for engine in $engines; do
    echo "=== restore every backup on $engine ==="
    # shellcheck disable=SC2046,SC2086
    $compose run --rm --no-deps $(env_for "$engine") $nosecret -e RESTORE_MATRIX_IN="$dir" app sh -c '
        vendor/bin/phinx migrate -e testing -q &&
        vendor/bin/phpunit --filter testBackupsFromOtherEnginesRestoreExactly tests/Integration/Household/HouseholdRestoreTest.php'
done
