#!/bin/sh
# Development only (spec.md §10 *Development stack*, K128): install the
# dependencies into the vendor/ volume when it is empty, or when
# composer.lock has changed since the last install (a pull that changed the
# lock). The lock's content-hash is recorded in vendor/ after each install.
# Run from the app's root by docker/entrypoint.sh; it does nothing outside
# APP_ENV=development or without Composer.
set -eu

[ "${APP_ENV:-production}" = "development" ] || exit 0
command -v composer >/dev/null 2>&1 || exit 0

record=vendor/.composer-lock-hash
hash="$(sed -n 's/^ *"content-hash": *"\([0-9a-f]*\)".*/\1/p' composer.lock 2>/dev/null | head -n 1)"

if [ -f vendor/autoload.php ]; then
    # No lock to compare: as before, an installed vendor/ is left alone.
    [ -n "$hash" ] || exit 0
    [ "$(cat "$record" 2>/dev/null || true)" != "$hash" ] || exit 0
    echo "logbook: composer.lock has changed since vendor/ was installed; running composer install."
fi

composer install --no-interaction --no-progress
if [ -n "$hash" ]; then
    printf '%s\n' "$hash" > "$record"
fi
