#!/usr/bin/env bash
# PreToolUse (Edit|Write): stop Claude editing files it must not touch.
# Exit 2 blocks the tool call and shows the message to Claude.
set -euo pipefail

root="${CLAUDE_PROJECT_DIR:-$(pwd)}"
file=$(jq -r '.tool_input.file_path // empty')
[ -z "$file" ] && exit 0
rel="${file#"$root"/}"

block() { echo "Blocked: $rel — $1" >&2; exit 2; }

case "$rel" in
    .env | .env.local)
        block "secrets live here and stay out of the agent's hands (CLAUDE.md §9). Change .env.example instead." ;;
    vendor/*)
        block "vendor/ is managed by Composer; change composer.json and run composer install/update." ;;
    public/assets/*)
        block "public/assets is built output; edit assets/ and run php bin/build-assets.php." ;;
    db/migrations/*)
        # New migrations are fine; changing one that's already committed breaks upgrades.
        if git -C "$root" ls-files --error-unmatch -- "$rel" >/dev/null 2>&1; then
            block "this migration is already committed and may have run on real databases. Add a new migration instead."
        fi ;;
esac
exit 0
