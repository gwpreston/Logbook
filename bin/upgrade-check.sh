#!/bin/sh
# The upgrade path (Phase 45.2, #417, spec.md §7.13): the golden household
# entered on an older release, backed up and restored there, then upgraded
# to this checkout, must give every golden figure that the same household
# entered on this version gives.
#
#   bin/upgrade-check.sh                  # from the previous minor release
#   bin/upgrade-check.sh v3.8.0 v3.9.0    # from these releases
#
# Uses the TEST_DB_* database (default: SQLite, one file per release under
# var/upgrade/). With PostgreSQL or MySQL that database is emptied for each
# release, and left at this version's schema. Needs composer and network access for the old release's
# dependencies. CI runs it on every release tag and nightly (ci.yml,
# restore-matrix.yml).
set -eu

cd "$(dirname "$0")/.."
root=$PWD
tags="$*"
if [ -z "$tags" ]; then
    # The newest minor release before this commit (a release's own tag is not "previous").
    tags=$(git tag --list 'v*.*.0' --sort=-v:refname --no-contains HEAD | head -1)
fi
export APP_ENV=testing
driver="${TEST_DB_DRIVER:-sqlite}"
failed=""

for tag in $tags; do
    echo "=== from $tag ==="
    dir="$root/var/upgrade/$tag"
    rm -rf "$dir"
    mkdir -p "$dir/app" "$dir/uploads" "$dir/backups"
    git archive "$tag" | tar -x -C "$dir/app"
    (cd "$dir/app" && composer install --quiet --no-interaction --no-progress)

    # This version's household and its loader, entered through that release's services.
    mkdir -p "$dir/app/tests/Support/Golden" "$dir/app/tests/Support/Household" "$dir/app/tests/Fixtures/golden"
    cp -R tests/Support/Golden/. "$dir/app/tests/Support/Golden/"
    cp tests/Support/Household/Household.php "$dir/app/tests/Support/Household/"
    cp tests/Support/MutableClock.php "$dir/app/tests/Support/"
    cp -R tests/Fixtures/golden/inputs tests/Fixtures/golden/uploads tests/Fixtures/golden/upgrade-source.php \
        "$dir/app/tests/Fixtures/golden/"

    if [ "$driver" = sqlite ]; then
        db="TEST_DB_NAME=$dir/upgrade.sqlite"
    else
        db="TEST_DB_DRIVER=$driver"
        # An empty database for the old release to build its own schema in.
        vendor/bin/phinx migrate -e testing -q
        php tests/Fixtures/golden/empty-database.php
        vendor/bin/phinx rollback -e testing -t 0 -q
    fi
    if ! (cd "$dir/app" &&
        env "$db" UPLOAD_PATH="$dir/uploads" BACKUP_PATH="$dir/backups" vendor/bin/phinx migrate -e testing -q &&
        env "$db" UPLOAD_PATH="$dir/uploads" BACKUP_PATH="$dir/backups" \
            php tests/Fixtures/golden/upgrade-source.php "$dir/source.zip"); then
        echo "!!! $tag: the household could not be entered, backed up or restored on $tag"
        failed="$failed $tag"
    elif ! (env "$db" vendor/bin/phinx migrate -e testing -q &&
        env "$db" UPLOAD_PATH="$dir/uploads" BACKUP_PATH="$dir/backups" php bin/backup.php create "$dir/upgraded.zip" &&
        UPGRADED_BACKUP="$dir/upgraded.zip" UPGRADED_FROM="$tag" \
            vendor/bin/phpunit tests/Integration/Household/HouseholdUpgradeTest.php); then
        echo "!!! $tag: the upgraded household differs"
        failed="$failed $tag"
    fi

    if [ "$driver" != sqlite ]; then
        # Leave this version's schema, empty, as the tests expect.
        vendor/bin/phinx migrate -e testing -q
        php tests/Fixtures/golden/empty-database.php
    fi
done

if [ -n "$failed" ]; then
    echo "Upgrade path failed from:$failed"
    exit 1
fi
echo "Upgrade path passed from: $tags"
