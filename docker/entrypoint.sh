#!/bin/sh
# Logbook container entrypoint: normalise config, prepare /data, wait for the
# database, apply pending migrations, then hand over to the CMD (Apache).
set -eu

cd /var/www/html

# "/logbook/" -> "/logbook", "logbook" -> "/logbook", "/" -> ""
APP_BASE_PATH="$(printf '%s' "${APP_BASE_PATH:-}" | sed -e 's#/*$##' -e 's#^\([^/]\)#/\1#')"
export APP_BASE_PATH

# Re-read PHP files on change only in development (bind-mounted source).
if [ "${APP_ENV:-production}" = "development" ]; then
    PHP_OPCACHE_VALIDATE=1
else
    PHP_OPCACHE_VALIDATE=0
fi
export PHP_OPCACHE_VALIDATE

DATA_DIR="${DATA_DIR:-/data}"
mkdir -p "$DATA_DIR" "${UPLOAD_PATH:-$DATA_DIR/uploads}" "${BACKUP_PATH:-$DATA_DIR/backups}" var/cache var/cache/locks var/log

# Dev image with a fresh (empty) vendor volume: install dependencies once.
if [ ! -f vendor/autoload.php ] && [ "${APP_ENV:-production}" = "development" ] && command -v composer >/dev/null 2>&1; then
    composer install --no-interaction --no-progress
fi

if [ "${1:-}" = "apache2-foreground" ]; then
    if [ ! -f vendor/autoload.php ]; then
        echo "logbook: vendor/ is missing — run 'composer install' first." >&2
        exit 1
    fi

    php bin/wait-for-db.php --timeout="${DB_WAIT_TIMEOUT:-60}"

    if [ "${MIGRATE_ON_START:-true}" = "true" ]; then
        vendor/bin/phinx migrate --configuration=phinx.php --environment=production --no-interaction
    fi

    # Migrations may have created the SQLite file as root.
    chown -R www-data:www-data "$DATA_DIR" var 2>/dev/null || true

    # Demo mode (spec.md §7.36): an empty database is seeded with the sample
    # data and marked as a demo. A database that holds real data is refused
    # with a line in the log and left exactly as it is; the container starts
    # either way.
    case "$(printf '%s' "${DEMO_MODE:-}" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on)
            setpriv --reuid=www-data --regid=www-data --init-groups php bin/demo-seed.php || true
            ;;
    esac

    # Scheduler passes (reminders, the digest, cleanup, scheduled backups),
    # so no host cron is needed: every SCHEDULER_INTERVAL seconds, as
    # www-data (it may write the SQLite database), for as long as the
    # container runs. Each run is recorded on Settings → Jobs, labelled
    # `docker`; a failed run never stops the loop.
    if [ "${SCHEDULER_ENABLED:-true}" = "true" ]; then
        interval="${SCHEDULER_INTERVAL:-900}"
        case "$interval" in ''|*[!0-9]*|0) interval=900 ;; esac
        # The app reads it too (the scheduler health warning, Phase 28.1).
        SCHEDULER_INTERVAL="$interval"
        export SCHEDULER_INTERVAL
        (
            while :; do
                LOGBOOK_SCHEDULER_TRIGGER=docker setpriv --reuid=www-data --regid=www-data --init-groups php bin/run-scheduled-tasks.php || true
                sleep "$interval"
            done
        ) &
        echo "logbook: scheduled tasks run every ${interval}s (SCHEDULER_INTERVAL)."
    fi
fi

exec "$@"
