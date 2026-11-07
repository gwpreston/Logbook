#!/usr/bin/env bash
# Shared helpers for the Edit/Write hooks. Sourced, not run.

# Print the edited file's path from the hook's JSON payload (stdin), or
# nothing. jq isn't installed everywhere (Git Bash on Windows), so fall back
# to PHP, which the project always has, and then to a plain sed match.
hook_file_path() {
    local input
    input=$(cat)
    if command -v jq >/dev/null 2>&1; then
        jq -r '.tool_input.file_path // empty' <<<"$input" 2>/dev/null && return 0
    fi
    if command -v php >/dev/null 2>&1; then
        php -r '$j = json_decode(stream_get_contents(STDIN), true); echo $j["tool_input"]["file_path"] ?? "";' <<<"$input" 2>/dev/null && return 0
    fi
    # Last resort: the first "file_path": "..." string, with JSON's \\ unescaped.
    grep -o '"file_path"[[:space:]]*:[[:space:]]*"[^"]*"' <<<"$input" | head -n1 \
        | sed -e 's/^"file_path"[[:space:]]*:[[:space:]]*"//' -e 's/"$//' -e 's/\\\\/\\/g'
}

# Normalise a path so Windows (C:\x, C:/x) and Git Bash (/c/x) forms compare
# equal: forward slashes, and a /c/ drive prefix turned into C:/.
hook_norm_path() {
    local p=${1//\\//}
    if [[ $p =~ ^/([a-zA-Z])/(.*)$ ]]; then
        p="${BASH_REMATCH[1]^^}:/${BASH_REMATCH[2]}"
    elif [[ $p =~ ^([a-zA-Z]):(.*)$ ]]; then
        p="${BASH_REMATCH[1]^^}:${BASH_REMATCH[2]}"
    fi
    printf '%s' "$p"
}

# Print $1 relative to the project root $2 (both normalised), or $1 unchanged
# when it lies outside the root.
hook_rel_path() {
    local file root
    file=$(hook_norm_path "$1")
    root=$(hook_norm_path "$2")
    printf '%s' "${file#"$root"/}"
}
