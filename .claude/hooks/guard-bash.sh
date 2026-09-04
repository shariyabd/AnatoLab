#!/usr/bin/env bash
# PreToolUse: Bash — destructive operations and multi-agent git hygiene.
set -uo pipefail
cmd=$(cat | jq -r '.tool_input.command // ""')
[ -z "$cmd" ] && exit 0

deny() { jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",permissionDecisionReason:$r}}'; exit 0; }
ask()  { jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"ask",permissionDecisionReason:$r}}'; exit 0; }

# --- Irreversible filesystem / history ------------------------------------
printf '%s' "$cmd" | grep -qE 'rm +(-[a-zA-Z]* )*-[a-zA-Z]*r[a-zA-Z]* +(/|~|\$HOME|\*)( |$)' \
  && deny "BLOCKED: recursive delete of a root or home path. If you need to remove generated output, name the exact directory."
printf '%s' "$cmd" | grep -qE 'git +push +.*(--force|-f)( |$)' \
  && deny "BLOCKED: force-push. Other agents branch from this remote; rewriting shared history destroys their work. Use --force-with-lease on your own feature branch only, and only with the human's say-so."
printf '%s' "$cmd" | grep -qE 'git +(reset +--hard|clean +-[a-zA-Z]*f|checkout +\.)' \
  && ask "DESTRUCTIVE: this discards uncommitted work in the shared checkout, including other lanes' in-flight changes. Confirm."
printf '%s' "$cmd" | grep -qE 'chmod +(-R +)?777' \
  && deny "BLOCKED: chmod 777. Use 775 for directories Laravel writes to (storage, bootstrap/cache)."

# --- Database: destructive, and environment-blind --------------------------
printf '%s' "$cmd" | grep -qE 'artisan +(migrate:fresh|migrate:reset|migrate:rollback|db:wipe)' \
  && ask "DESTRUCTIVE MIGRATION: this drops data. Safe on your own local database, catastrophic anywhere else. Confirm which database this is pointed at."

# --- Dependencies need a human decision (docs/engineering.md §5) -----------
printf '%s' "$cmd" | grep -qE '(composer +(require|remove)|npm +(install|i|add|uninstall) +[a-z@])' \
  && ask "DEPENDENCY CHANGE. Every new package needs a named reason, a licence check, and confirmation that nothing already installed does the job (docs/engineering.md §5)."

# --- Multi-agent git hygiene ----------------------------------------------
if printf '%s' "$cmd" | grep -qE 'git +commit'; then
  branch=$(git -C "${CLAUDE_PROJECT_DIR:-.}" branch --show-current 2>/dev/null || echo "")
  case "$branch" in
    main|master)
      ask "COMMIT ON ${branch}: work belongs on a lane branch (e.g. feat/anatomy-api). Committing straight to ${branch} collides with every other agent. Branch first, or confirm this is a deliberate exception." ;;
  esac
fi

exit 0
