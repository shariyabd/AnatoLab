#!/usr/bin/env bash
# PreToolUse: Write|Edit|NotebookEdit
# DENY writes to generated/secret files. ASK (human approval) for shared contracts.
# Rationale + full list: docs/engineering.md §8.
set -uo pipefail

payload=$(cat)
file=$(printf '%s' "$payload" | jq -r '.tool_input.file_path // ""')
[ -z "$file" ] && exit 0

root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
rel="${file#"$root"/}"

deny() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",permissionDecisionReason:$r}}'
  exit 0
}
ask() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"ask",permissionDecisionReason:$r}}'
  exit 0
}

# ---- Hard deny: never agent-editable -------------------------------------
case "$rel" in
  .env|.env.*)
    deny "BLOCKED: $rel holds secrets and is never agent-editable. Add the key name to .env.example instead and tell the human what value to set." ;;
  composer.lock|package-lock.json|pnpm-lock.yaml|yarn.lock)
    deny "BLOCKED: $rel is generated. Change composer.json / package.json and run the installer so the lock file is regenerated." ;;
  vendor/*|node_modules/*|public/build/*|storage/framework/*|.git/*)
    deny "BLOCKED: $rel is vendored or build output. Never hand-edit; change the source and rebuild." ;;
  .claude/settings.json|.claude/hooks/*)
    deny "BLOCKED: $rel is the enforcement layer itself. Only the Engineering Standards owner changes hooks, and only with the human in the loop." ;;
esac

# Secrets must not be introduced anywhere.
content=$(printf '%s' "$payload" | jq -r '(.tool_input.content // "") + "\n" + (.tool_input.new_string // "")')
if printf '%s' "$content" | grep -qE '(sk-ant-[A-Za-z0-9]|sk-[A-Za-z0-9]{32}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----)'; then
  deny "BLOCKED: this write contains what looks like a live API key or private key. Secrets belong in .env (untracked) and are read through config(). Never in source."
fi

# ---- Ask: shared contracts, one owner, cross-lane blast radius -----------
case "$rel" in
  docs/architecture.md|docs/project-context.md|docs/engineering.md)
    ask "SHARED DOC: $rel is a project contract other agents build against. Confirm this edit is intentional and in scope." ;;
  app/Contracts/*|config/ai.php)
    ask "SHARED CONTRACT: $rel is depended on by multiple lanes (AI, RAG, services). Changing it breaks other agents' work in flight. Confirm." ;;
  resources/js/anatomy/types.ts)
    ask "SHARED CONTRACT: the viewer DTOs mirror the API Resources. Changing them requires updating the matching Resource in the same commit. Confirm." ;;
  composer.json|package.json)
    ask "DEPENDENCY CHANGE: $rel. New dependencies need human approval (docs/engineering.md §5). Confirm the package, its licence, and that no existing dependency already does this." ;;
  database/migrations/*)
    if [ -f "$file" ]; then
      ask "EXISTING MIGRATION: $rel is already committed and may have run. Editing it desynchronises every other environment. Prefer a NEW migration. Confirm only if this migration is unreleased."
    fi ;;
  .github/workflows/*|CLAUDE.md)
    ask "SHARED CI/INSTRUCTIONS: $rel affects every agent and every build. Confirm." ;;
esac

exit 0
