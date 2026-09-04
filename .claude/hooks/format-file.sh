#!/usr/bin/env bash
# PostToolUse: Write|Edit — format in place. Silent no-op until the toolchain exists.
set -uo pipefail
file=$(cat | jq -r '.tool_response.filePath // .tool_input.file_path // ""')
[ -n "$file" ] && [ -f "$file" ] || exit 0
root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
case "${file#"$root"/}" in vendor/*|node_modules/*) exit 0 ;; esac

case "$file" in
  *.php)
    [ -x "$root/vendor/bin/pint" ] && "$root/vendor/bin/pint" --quiet "$file" ;;
  *.js|*.ts|*.vue|*.css|*.json)
    [ -x "$root/node_modules/.bin/prettier" ] && "$root/node_modules/.bin/prettier" --write --log-level silent "$file" ;;
esac
exit 0
