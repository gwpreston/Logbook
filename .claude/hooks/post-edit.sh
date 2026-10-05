#!/usr/bin/env bash
# PostToolUse (Edit|Write): fix and check PSR-12 on the PHP file just edited.
# Exit 2 sends the remaining phpcs errors back to Claude to fix.
set -uo pipefail

root="${CLAUDE_PROJECT_DIR:-$(pwd)}"
file=$(jq -r '.tool_input.file_path // empty')
case "$file" in *.php) ;; *) exit 0 ;; esac
[ -f "$file" ] || exit 0
rel="${file#"$root"/}"
case "$rel" in vendor/*) exit 0 ;; esac

cd "$root" || exit 0
# phpcbf exits 1 when it fixed something; that's not a failure.
vendor/bin/phpcbf -q --no-colors "$rel" >/dev/null 2>&1 || true

if ! out=$(vendor/bin/phpcs -q --no-colors --report=emacs "$rel" 2>&1); then
    echo "phpcs (PSR-12) still reports problems in $rel after phpcbf:" >&2
    echo "$out" >&2
    exit 2
fi
exit 0
