#!/usr/bin/env bash
#
# One-command local development setup.
#
# Brings up the Docker dev stack (docker-compose.dev.yml: source bind-mounted,
# so PHP and Twig changes apply on reload) on the database engine of your
# choice, checks that Docker is ready before touching anything, and confirms
# the app actually answers before saying it is up. Safe to re-run: every step
# is idempotent and nothing is deleted without being asked.
#
# It also stops the stack again, so starting and stopping local development is
# the one command either way. On Windows run it from Git Bash (or
# `bash bin/dev-setup.sh …`).
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Git Bash on Windows rewrites values that look like POSIX paths ("/data/…")
# into Windows paths before handing them to docker.exe; keep ours intact.
export MSYS2_ENV_CONV_EXCL="DEV_${MSYS2_ENV_CONV_EXCL:+;$MSYS2_ENV_CONV_EXCL}"
export MSYS_NO_PATHCONV=1

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'
    YELLOW=$'\033[33m'; BLUE=$'\033[34m'; RESET=$'\033[0m'
else
    BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; BLUE=''; RESET=''
fi

STEP=0
TOTAL_STEPS=0
step()  { STEP=$((STEP + 1)); printf '\n%s[%d/%d]%s %s%s%s\n' "$BLUE" "$STEP" "$TOTAL_STEPS" "$RESET" "$BOLD" "$1" "$RESET"; }
ok()    { printf '      %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
info()  { printf '      %s·%s %s\n' "$DIM" "$RESET" "$1"; }
warn()  { printf '      %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
die()   { printf '\n%serror:%s %s\n\n' "$RED" "$RESET" "$1" >&2; exit 1; }
have()  { command -v "$1" >/dev/null 2>&1; }
indent() { sed 's/^/      /'; }

# Ask before something destructive; --yes skips the question.
confirm() {
    [ "$ASSUME_YES" -eq 1 ] && return 0
    printf '      %s%s [y/N] %s' "$YELLOW" "$1" "$RESET"
    local reply
    read -r reply || reply=""
    case "$reply" in [yY]*) return 0 ;; *) return 1 ;; esac
}

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
ENGINE="pgsql"
SAMPLE_DATA=0
DO_RESET=0
DO_STOP=0
DO_STATUS=0
DO_LOGS=0
ASSUME_YES=0
PORT_OPTION=""

# Printed by --help. Kept here rather than read back out of the file's own
# comments, which breaks the moment a line is added above it.
usage() {
    cat <<'USAGE'
Local development for Logbook: start it, or stop it again.

  ./bin/dev-setup.sh                     Set up and start (PostgreSQL)
  ./bin/dev-setup.sh --with-sample-data  ...and add a demo owner plus sample vehicles
  ./bin/dev-setup.sh --mysql             Use MySQL instead (or --mariadb, --sqlite)
  ./bin/dev-setup.sh --reset             Start over from an empty database
  ./bin/dev-setup.sh --stop              Stop the stack, keeping all data
  ./bin/dev-setup.sh --stop --reset      Stop it and delete the data too

Options
  --with-sample-data   A demo owner (demo) and six vehicles with a year of
                       fill-ups, EV charges and odometer readings, and a member
                       (partner) two of them are shared with. Both get new random
                       passwords on every run, printed at the end and kept in
                       var/dev-credentials; on a database that already has them,
                       only the passwords change.
  --postgres, --mysql, --mariadb, --sqlite
                       Which database engine to run. PostgreSQL is the default.
                       Each engine keeps its own data and photos.
  --reset              Empty the chosen engine's database and its uploads. With
                       --stop: delete every dev database, the uploads and
                       vendor/. Prompts unless --yes.
  --stop, --down       Stop the containers instead of starting them.
  --status             Show the containers and whether the app responds.
  --logs               Follow the app log.
  --port <number>      Serve the app on this port. Default 8090; 8080 is kept
                       free for the production stack.
  -y, --yes            Do not prompt before anything destructive.
  -h, --help           This message.

The port may be set for a single run with either of:
  ./bin/dev-setup.sh --port 8081
  APP_PORT=8081 ./bin/dev-setup.sh

Every email the app sends (password resets, invitations, reminders) lands in
Mailpit at http://localhost:8025. If something else holds 8025 (another
project's Mailpit), the next free port is used and printed; MAILPIT_PORT=<n>
asks for one.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --postgres|--pgsql) ENGINE="pgsql" ;;
        --mysql)            ENGINE="mysql" ;;
        --mariadb)          ENGINE="mariadb" ;;
        --sqlite)           ENGINE="sqlite" ;;
        --with-sample-data) SAMPLE_DATA=1 ;;
        --reset)            DO_RESET=1 ;;
        --stop|--down)      DO_STOP=1 ;;
        --status)           DO_STATUS=1 ;;
        --logs)             DO_LOGS=1 ;;
        --port)
            [ $# -ge 2 ] || die "--port needs a number, e.g. --port 8081."
            PORT_OPTION="$2"; shift ;;
        --port=*)           PORT_OPTION="${1#--port=}" ;;
        -y|--yes)           ASSUME_YES=1 ;;
        -h|--help)          usage; exit 0 ;;
        *) die "Unknown option \"$1\". Try --help." ;;
    esac
    shift
done

if [ "$DO_STOP" -eq 1 ] && [ "$SAMPLE_DATA" -eq 1 ]; then
    die "--with-sample-data starts the stack; it cannot be combined with --stop."
fi

# ---------------------------------------------------------------------------
# Stack configuration
# ---------------------------------------------------------------------------
COMPOSE=(docker compose -f docker-compose.dev.yml)
compose() { "${COMPOSE[@]}" "$@"; }

DB_SERVICES="pgsql mysql mariadb"

case "$ENGINE" in
    pgsql)   DEV_DB_DRIVER=pgsql;  DEV_DB_HOST=pgsql;   DEV_DB_NAME=logbook; DB_SERVICE=pgsql;   ENGINE_NAME=PostgreSQL ;;
    mysql)   DEV_DB_DRIVER=mysql;  DEV_DB_HOST=mysql;   DEV_DB_NAME=logbook; DB_SERVICE=mysql;   ENGINE_NAME=MySQL ;;
    mariadb) DEV_DB_DRIVER=mysql;  DEV_DB_HOST=mariadb; DEV_DB_NAME=logbook; DB_SERVICE=mariadb; ENGINE_NAME=MariaDB ;;
    sqlite)  DEV_DB_DRIVER=sqlite; DEV_DB_HOST=none;    DEV_DB_NAME=/data/logbook.sqlite; DB_SERVICE=; ENGINE_NAME=SQLite ;;
esac
# Per engine, so each database keeps its own photos.
DEV_UPLOAD_PATH="/data/uploads/$ENGINE"
export DEV_DB_DRIVER DEV_DB_HOST DEV_DB_NAME DEV_UPLOAD_PATH

# --port wins over APP_PORT from the environment, which wins over the default.
APP_PORT="${PORT_OPTION:-${APP_PORT:-8090}}"
case "$APP_PORT" in
    ''|*[!0-9]*) die "\"$APP_PORT\" is not a port number. Use e.g. --port 8081." ;;
esac
if [ "$APP_PORT" -lt 1 ] || [ "$APP_PORT" -gt 65535 ]; then
    die "Port $APP_PORT is out of range. Use a number from 1 to 65535."
fi
# 8080 belongs to the production stack (docker-compose.yml), so the two can
# run side by side; refuse it rather than collide with it.
if [ "$APP_PORT" = "8080" ]; then
    die "Port 8080 is reserved for the production stack (docker-compose.yml).
       Leave it unset for the dev default (8090), or pick another: --port 8081"
fi
export APP_PORT
BASE_URL="http://localhost:${APP_PORT}"
MAILPIT_PORT_ASKED="${MAILPIT_PORT:-}"
MAILPIT_PORT="${MAILPIT_PORT:-8025}"
case "$MAILPIT_PORT" in
    ''|*[!0-9]*) die "MAILPIT_PORT \"$MAILPIT_PORT\" is not a port number." ;;
esac
export MAILPIT_PORT
MAILPIT_URL="http://localhost:${MAILPIT_PORT}"
CREDENTIALS="var/dev-credentials"

# 20 characters from an unambiguous alphabet (no 0/O, 1/l/I), from /dev/urandom.
new_password() {
    # tr ends with SIGPIPE once head has its 20, which pipefail would count as a failure.
    { LC_ALL=C tr -dc 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789' </dev/urandom 2>/dev/null | head -c 20; } || true
}

service_running() {
    [ -n "$(compose ps -q --status running "$1" 2>/dev/null || true)" ]
}

LOG=""
cleanup() { [ -n "$LOG" ] && rm -f "$LOG"; return 0; }
trap cleanup EXIT

# Run a compose command with its output captured, showing only the tail on
# failure. Plain `mktemp -t name` is not portable: GNU mktemp (Linux, Git Bash)
# wants an explicit XXXXXX template.
run_logged() {
    [ -n "$LOG" ] && rm -f "$LOG"
    LOG="$(mktemp "${TMPDIR:-/tmp}/logbook-dev.XXXXXX")"
    if compose "$@" >"$LOG" 2>&1; then
        return 0
    fi
    tail -20 "$LOG" | indent
    return 1
}

# ---------------------------------------------------------------------------
# Logs. No checks and no steps: this hands the terminal to compose.
# ---------------------------------------------------------------------------
if [ "$DO_LOGS" -eq 1 ]; then
    exec "${COMPOSE[@]}" logs -f app
fi

# ---------------------------------------------------------------------------
# Header
# ---------------------------------------------------------------------------
if [ "$DO_STOP" -eq 1 ]; then
    TOTAL_STEPS=2
    printf '\n%s Logbook — stopping local development %s\n' "$BOLD" "$RESET"
elif [ "$DO_STATUS" -eq 1 ]; then
    TOTAL_STEPS=2
    printf '\n%s Logbook — local development status %s\n' "$BOLD" "$RESET"
else
    TOTAL_STEPS=5
    [ "$DO_RESET" -eq 1 ] && TOTAL_STEPS=$((TOTAL_STEPS + 1))
    [ "$SAMPLE_DATA" -eq 1 ] && TOTAL_STEPS=$((TOTAL_STEPS + 1))
    printf '\n%s Logbook — local development setup %s\n' "$BOLD" "$RESET"
    printf '%s Engine: %s · port %s %s\n' "$DIM" "$ENGINE_NAME" "$APP_PORT" "$RESET"
fi

# ---------------------------------------------------------------------------
step "Checking prerequisites"
# ---------------------------------------------------------------------------
have docker || die "Docker is not installed. See https://docs.docker.com/get-docker/"

if ! docker compose version >/dev/null 2>&1; then
    die "The Docker Compose plugin is missing. Install Docker Desktop, or the docker-compose-plugin package."
fi
ok "docker $(docker version --format '{{.Client.Version}}' 2>/dev/null || echo '?') with the compose plugin"

if ! docker info >/dev/null 2>&1; then
    if [ "$DO_STOP" -eq 1 ]; then
        # Nothing can be running if the daemon is not. That is the desired
        # end state, so report it and succeed rather than fail.
        ok "the Docker daemon is not running — nothing to stop"
        printf '\n%s Stopped. %s\n\n' "$GREEN$BOLD" "$RESET"
        exit 0
    fi
    if [ "$(uname -s)" = "Darwin" ] && [ -d "/Applications/Docker.app" ]; then
        warn "The Docker daemon is not running. Starting Docker Desktop…"
        open -a Docker
        for _ in $(seq 1 60); do
            docker info >/dev/null 2>&1 && break
            sleep 2
        done
    fi
    docker info >/dev/null 2>&1 || die "The Docker daemon is not running. Start Docker and run this again."
fi
ok "the Docker daemon is running"

# ---------------------------------------------------------------------------
# Stopping. Everything below this block is about starting up, so the stop path
# finishes here.
# ---------------------------------------------------------------------------
if [ "$DO_STOP" -eq 1 ]; then
    step "Stopping containers"

    before="$(compose --profile all ps --services --status running 2>/dev/null || true)"

    if [ "$DO_RESET" -eq 1 ]; then
        confirm "This also deletes every dev database, the uploads and vendor/. Continue?" \
            || die "Aborted. Nothing was stopped."
        run_logged --profile all down --volumes || die "Could not stop the stack. See the output above."
        ok "containers stopped; every dev database, the uploads and vendor/ deleted"
    else
        run_logged --profile all down || die "Could not stop the stack. See the output above."
        ok "containers stopped — your data is kept"
    fi

    if [ -n "$before" ]; then
        printf '%s\n' "$before" | while IFS= read -r service; do
            [ -n "$service" ] && info "stopped: $service"
        done
    else
        info "nothing was running"
    fi

    # Anything left over is worth knowing about now rather than next time the
    # app mysteriously fails to start.
    leftover="$(compose --profile all ps --services --status running 2>/dev/null || true)"
    [ -n "$leftover" ] && warn "still running: $(printf '%s' "$leftover" | tr '\n' ' ')"

    printf '\n%s Stopped. %s\n\n' "$GREEN$BOLD" "$RESET"
    printf '  %sStart it again with%s  ./bin/dev-setup.sh  %s(add --port <number> for another port)%s\n\n' "$BOLD" "$RESET" "$DIM" "$RESET"
    exit 0
fi

# ---------------------------------------------------------------------------
# Status. Reports on whatever is running, whichever engine that is.
# ---------------------------------------------------------------------------
if [ "$DO_STATUS" -eq 1 ]; then
    step "Checking the stack"

    if service_running app; then
        # The engine and port the running container was started with, rather
        # than this run's defaults.
        running_engine="$(compose exec -T app printenv DB_HOST 2>/dev/null | tr -d '\r' || true)"
        running_url="$(compose exec -T app printenv APP_URL 2>/dev/null | tr -d '\r' || true)"
        [ "$running_engine" = "none" ] && running_engine="sqlite"
        running_url="${running_url:-$BASE_URL}"

        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$running_url/health" 2>/dev/null || true)"
        if [ "$code" = "200" ]; then
            ok "the app responds on $running_url (database: ${running_engine:-?})"
        else
            warn "the app is running but /health answered HTTP ${code:-000} — see ./bin/dev-setup.sh --logs"
        fi
    else
        info "the app is not running — start it with ./bin/dev-setup.sh"
    fi
    if service_running mailpit; then
        published="$(compose port mailpit 8025 2>/dev/null | sed 's/.*://' || true)"
        [ -n "$published" ] && MAILPIT_URL="http://localhost:${published}"
        ok "Mailpit catches the app's email on $MAILPIT_URL"
    else
        info "Mailpit is not running"
    fi
    if [ -f "$CREDENTIALS" ]; then
        printf '\n      %sSample sign-ins%s (%s)\n' "$BOLD" "$RESET" "$CREDENTIALS"
        indent <"$CREDENTIALS"
    fi

    printf '\n'
    compose --profile all ps
    printf '\n'
    exit 0
fi

have curl || die "curl is required to check that the app responds."
ok "curl is available"

# A port already in use is the most common reason this fails, and the error
# Docker gives for it is not obvious. This pre-check is best-effort — a binding
# held inside Docker's own VM is invisible to lsof — so the authoritative check
# is Docker's bind, whose error is translated into the same advice below.
port_in_use() {
    if have lsof; then lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1
    elif have nc; then nc -z 127.0.0.1 "$1" >/dev/null 2>&1
    else return 1
    fi
}

# The port may be held by our own app container, which is fine, or by
# something else, which is not. Captured into a variable rather than piped into
# `grep -q`, which would exit early, hand docker a SIGPIPE, and leave pipefail
# reporting the whole test as failed. Publishers render as
# {ip targetPort publishedPort protocol}.
PUBLISHED_PORTS="$(compose --profile all ps --format '{{.Publishers}}' 2>/dev/null || true)"
port_is_ours() {
    case "$PUBLISHED_PORTS" in
        *" $1 tcp}"*) return 0 ;;
        *)            return 1 ;;
    esac
}

if port_in_use "$APP_PORT" && ! port_is_ours "$APP_PORT"; then
    die "Port $APP_PORT is already in use by something else.
       Pick a free one for this run: ./bin/dev-setup.sh --port 8081"
fi
ok "port $APP_PORT is free (or already ours)"

# Mailpit's web UI. Another project's Mailpit on 8025 is common; with no
# MAILPIT_PORT asked for, move to the next free port rather than fail.
if port_in_use "$MAILPIT_PORT" && ! port_is_ours "$MAILPIT_PORT"; then
    if [ -n "$MAILPIT_PORT_ASKED" ]; then
        die "Port $MAILPIT_PORT (MAILPIT_PORT) is already in use by something else.
       Pick a free one: MAILPIT_PORT=8026 ./bin/dev-setup.sh"
    fi
    taken="$MAILPIT_PORT"
    for candidate in $(seq $((MAILPIT_PORT + 1)) $((MAILPIT_PORT + 20))); do
        if ! port_in_use "$candidate" || port_is_ours "$candidate"; then
            MAILPIT_PORT="$candidate"
            break
        fi
    done
    [ "$MAILPIT_PORT" != "$taken" ] || die "No free port for Mailpit near $taken. Set one: MAILPIT_PORT=9025 ./bin/dev-setup.sh"
    export MAILPIT_PORT
    MAILPIT_URL="http://localhost:${MAILPIT_PORT}"
    info "port $taken is taken by something else; Mailpit uses $MAILPIT_PORT instead"
fi

# Asked now, before the build, rather than after a wait of several minutes.
if [ "$DO_RESET" -eq 1 ]; then
    confirm "This deletes ALL data in the $ENGINE_NAME dev database and its uploads. Continue?" \
        || die "Aborted. Nothing was changed."
fi

# ---------------------------------------------------------------------------
step "Starting the database"
# ---------------------------------------------------------------------------
if [ -n "$DB_SERVICE" ]; then
    if ! run_logged up -d --wait "$DB_SERVICE"; then
        compose logs --tail 20 "$DB_SERVICE" 2>/dev/null | indent || true
        die "$ENGINE_NAME did not become healthy. See: docker compose -f docker-compose.dev.yml logs $DB_SERVICE"
    fi
    ok "$ENGINE_NAME is healthy"
else
    ok "SQLite needs no database container"
fi

# Every email the app sends goes to Mailpit (spec.md §10 *Development stack*).
if run_logged up -d --wait mailpit; then
    ok "Mailpit is up on $MAILPIT_URL"
elif grep -qE "address already in use|port is already allocated" "$LOG"; then
    warn "Mailpit could not have port $MAILPIT_PORT; the app runs, but its email goes nowhere"
    info "pick another port: MAILPIT_PORT=8026 ./bin/dev-setup.sh"
else
    warn "Mailpit did not start; the app runs, but its email goes nowhere"
fi

# Other engines are not needed; stop them (their data is kept).
for service in $DB_SERVICES; do
    if [ "$service" != "$DB_SERVICE" ] && service_running "$service"; then
        compose stop "$service" >/dev/null 2>&1 || warn "could not stop $service"
        info "stopped $service (its data is kept)"
    fi
done

# ---------------------------------------------------------------------------
step "Building and starting the app"
# ---------------------------------------------------------------------------
# Recreates the app container when the engine or port changed; --build picks
# up Dockerfile changes (e.g. new PHP extensions) and is cached otherwise.
info "building the image — the first run takes a few minutes"
if ! run_logged up -d --build app; then
    if grep -qE "address already in use|port is already allocated" "$LOG"; then
        die "Port $APP_PORT is already taken by something outside this project.
       Pick a free one for this run: ./bin/dev-setup.sh --port 8081"
    fi
    die "Could not start the app container. See the output above."
fi
ok "app container started"

# ---------------------------------------------------------------------------
step "Waiting for the app"
# ---------------------------------------------------------------------------
# Apache starts only after the entrypoint has migrated the database, so a
# healthy /health means the app is ready to use.
info "the entrypoint applies migrations before the web server starts"
code="000"
for attempt in $(seq 1 90); do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$BASE_URL/health" 2>/dev/null || true)"
    [ "$code" = "200" ] && break
    # A container that has exited will never answer, and waiting the full 90
    # intervals to say so is not help.
    if ! service_running app; then
        compose logs --tail 20 app 2>/dev/null | indent || true
        die "The app container stopped while starting. See the output above."
    fi
    if [ "$attempt" -eq 90 ]; then
        compose logs --tail 20 app 2>/dev/null | indent || true
        die "The app did not respond on $BASE_URL/health (last status: ${code:-000})."
    fi
    sleep 2
done
ok "the app responds on $BASE_URL (HTTP $code)"

# ---------------------------------------------------------------------------
# Optional: start over from an empty database. Rolled back and migrated again
# rather than deleting a volume, so only the chosen engine is affected. The
# accounts go first: the 2.0.0 migration refuses to roll back past several
# users (their vehicles and entries go with them).
# ---------------------------------------------------------------------------
# Both run as www-data, the web server's user, so the uploads they create
# stay writable by the app; directories left as root by an older run of this
# script are handed back first.
if [ "$DO_RESET" -eq 1 ] || [ "$SAMPLE_DATA" -eq 1 ]; then
    compose exec -T app chown -R www-data:www-data /data >/dev/null 2>&1 || true
fi

if [ "$DO_RESET" -eq 1 ]; then
    step "Emptying the database"
    if ! run_logged exec -T -u www-data app sh -c "
        php -r 'require \"vendor/autoload.php\"; \$c = Logbook\\Kernel::createContainer(Logbook\\Kernel::settings()); \$c->get(Doctrine\\DBAL\\Connection::class)->executeStatement(\"DELETE FROM users\");' 2>/dev/null || true
        vendor/bin/phinx rollback -e development -t 0 -q &&
        vendor/bin/phinx migrate -e development -q &&
        rm -rf '$DEV_UPLOAD_PATH'"; then
        die "Could not reset the $ENGINE_NAME database. See the output above."
    fi
    ok "rolled back, migrated again and removed the uploads"
fi

# ---------------------------------------------------------------------------
# Optional: a populated instance to look at, from the Phinx demo seed.
# ---------------------------------------------------------------------------
SAMPLE_LOADED=0
DEMO_PASSWORD=""
PARTNER_PASSWORD=""
if [ "$SAMPLE_DATA" -eq 1 ]; then
    step "Creating sample data"
    # New passwords on every run (Phase 33.1): nothing fixed to guess.
    DEMO_PASSWORD="$(new_password)"
    PARTNER_PASSWORD="$(new_password)"
    [ "${#DEMO_PASSWORD}" -eq 20 ] && [ "${#PARTNER_PASSWORD}" -eq 20 ] \
        || die "Could not make random passwords from /dev/urandom."
    run_logged exec -T -u www-data -e DEMO_PASSWORD="$DEMO_PASSWORD" -e PARTNER_PASSWORD="$PARTNER_PASSWORD" \
        app vendor/bin/phinx seed:run -e development -s DemoDataSeeder \
        || die "The seeder failed. See the output above."
    # The seeder declines, rather than fails, when another account already
    # exists, and only sets new passwords when the sample users do; say which.
    if grep -q "An account already exists" "$LOG"; then
        warn "skipped — this database already has an account"
        info "start from an empty one with: ./bin/dev-setup.sh --reset --with-sample-data"
    else
        SAMPLE_LOADED=1
        if grep -q "new passwords set" "$LOG"; then
            ok "the sample users were already there: new passwords set"
        else
            ok "a demo owner and six vehicles with a year of history, and a partner they share two with"
        fi
        mkdir -p var
        ( umask 077; printf 'demo     %s   (demo@example.test, an admin)\npartner  %s   (partner@example.test, a member)\n' \
            "$DEMO_PASSWORD" "$PARTNER_PASSWORD" >"$CREDENTIALS" )
        chmod 600 "$CREDENTIALS"
        info "written to $CREDENTIALS (readable by you only)"
    fi
fi

# ---------------------------------------------------------------------------
step "Checking for an account"
# ---------------------------------------------------------------------------
SETUP_NEEDED=0
redirect="$(curl -s -o /dev/null -w '%{redirect_url}' --max-time 5 "$BASE_URL/" 2>/dev/null || true)"
case "$redirect" in
    */setup) SETUP_NEEDED=1; info "no account yet — the first-run setup is open" ;;
    *)       ok "this database already has an account" ;;
esac

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf '\n%s Ready. %s\n\n' "$GREEN$BOLD" "$RESET"
printf '  %sApp%s          %s\n' "$BOLD" "$RESET" "$BASE_URL"
printf '  %sDatabase%s     %s  %s(each engine keeps its own data)%s\n' \
    "$BOLD" "$RESET" "$ENGINE_NAME" "$DIM" "$RESET"
printf '  %sMail%s         %s  %s(Mailpit: every email the app sends)%s\n' \
    "$BOLD" "$RESET" "$MAILPIT_URL" "$DIM" "$RESET"

if [ "$SAMPLE_LOADED" -eq 1 ]; then
    printf '\n  %sSign in with%s  demo / %s\n' "$BOLD" "$RESET" "$DEMO_PASSWORD"
    printf '                or partner / %s  %s(a member)%s\n' "$PARTNER_PASSWORD" "$DIM" "$RESET"
    printf '                %snew on every run; kept in %s (./bin/dev-setup.sh --status)%s\n' "$DIM" "$CREDENTIALS" "$RESET"
elif [ "$SETUP_NEEDED" -eq 1 ]; then
    printf '\n  %sOpen the app to create your account.%s\n' "$BOLD" "$RESET"
fi

printf '\n  %sUseful commands%s\n' "$BOLD" "$RESET"
printf '    ./bin/dev-setup.sh --logs                 %sfollow the application log%s\n' "$DIM" "$RESET"
printf '    ./bin/dev-setup.sh --stop                 %sstop everything (data is kept)%s\n' "$DIM" "$RESET"
printf '    ./bin/dev-setup.sh --reset                %sstart again from an empty database%s\n' "$DIM" "$RESET"
printf '    ./bin/dev-setup.sh --with-sample-data     %sadd the demo owner and vehicles%s\n' "$DIM" "$RESET"
printf '    docker compose -f docker-compose.dev.yml exec app composer check\n'
printf '                                              %slint, static analysis and tests%s\n' "$DIM" "$RESET"
printf '\n'
