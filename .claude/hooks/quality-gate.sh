#!/usr/bin/env bash
# Stop: before Claude finishes, run lint, PHPStan and the unit tests when PHP
# files have changed. Exit 2 keeps Claude working with the failures.
# Integration tests and coverage are left to CI (slow, need a database).
set -uo pipefail

root="${CLAUDE_PROJECT_DIR:-$(pwd)}"
input=$(cat)
# Already continuing because of this hook: don't loop on something unfixable.
[ "$(jq -r '.stop_hook_active // false' <<<"$input")" = "true" ] && exit 0

cd "$root" || exit 0
changed=$(git status --porcelain --untracked-files=all -- '*.php' 'phpstan.neon.dist' 'phpcs.xml.dist' 'phpunit.xml.dist' 'composer.lock' 2>/dev/null)
[ -z "$changed" ] && exit 0

# Skip if the same working tree already passed (Claude can stop several times).
stamp="var/claude-quality-gate.pass"
hash=$( { git diff HEAD -- '*.php'; git ls-files --others --exclude-standard -- '*.php' | xargs cat 2>/dev/null; } | shasum | cut -d' ' -f1)
[ -f "$stamp" ] && [ "$(cat "$stamp")" = "$hash" ] && exit 0

run() {
    local name=$1; shift
    if ! out=$("$@" 2>&1); then
        echo "Quality gate failed: $name" >&2
        echo "$out" | tail -n 60 >&2
        exit 2
    fi
}
run "composer lint" composer lint --no-interaction -- -q --no-colors
run "composer analyse" composer analyse --no-interaction
run "composer test:unit" composer test:unit --no-interaction -- --no-progress --colors=never

mkdir -p var && echo "$hash" > "$stamp"
exit 0
