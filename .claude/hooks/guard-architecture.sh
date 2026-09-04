#!/usr/bin/env bash
# PostToolUse: Write|Edit|NotebookEdit
# Enforces the dependency rules in docs/architecture.md §4.2 and the integration
# rules in §5.4. Reports every violation in one pass so the fix is one edit.
set -uo pipefail

payload=$(cat)
file=$(printf '%s' "$payload" | jq -r '.tool_response.filePath // .tool_input.file_path // ""')
[ -z "$file" ] && exit 0
[ -f "$file" ] || exit 0

root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
rel="${file#"$root"/}"
case "$rel" in
  vendor/*|node_modules/*|docs/*|.claude/*|*.md) exit 0 ;;
esac

v=()
hit() { grep -nEm1 "$1" "$file" >/dev/null 2>&1; }

# --- Rule: the viewer is framework-free (architecture.md §4.2, §5.1) -------
case "$rel" in resources/js/anatomy/*)
  hit "from +['\"]vue['\"]|from +['\"]@inertiajs" && v+=("resources/js/anatomy/ must not import Vue or Inertia. The viewer is a standalone library; the only bridge is resources/js/composables/useAnatomyViewer.ts.")
  hit "from +['\"]axios['\"]|window\.axios|\bfetch\(" && v+=("The viewer must never fetch (architecture.md §5.4 rule 3). It receives a fully-formed OrganDto. Move the network call into the Vue layer.")
  ;;
esac

# --- Rule: services are request-agnostic (architecture.md §4.2) -----------
case "$rel" in app/Services/*)
  hit "\brequest\(|\bauth\(|\bsession\(|\\\$_(POST|GET|REQUEST)|Illuminate\\\\Support\\\\Facades\\\\(Request|Auth|Session)" && v+=("Services must not read the request, session, or auth state. Pass the User and validated input in as typed arguments — otherwise the service cannot be queued or tested.")
  hit "App\\\\Infrastructure\\\\" && v+=("Services depend on App\\Contracts, never on App\\Infrastructure concretes. Type-hint the interface and let AppServiceProvider bind it.")
  ;;
esac

# --- Rule: models hold state, not behaviour (architecture.md §4.2) --------
case "$rel" in app/Models/*)
  hit "App\\\\Services\\\\|App\\\\Http\\\\" && v+=("Models must not depend on Services or Http. Dependencies point inward only.")
  ;;
esac

# --- Rule: Eloquent only, no raw SQL in app code (architecture.md §6) -----
case "$rel" in app/*.php)
  hit "DB::(raw|table|select|statement|insert|update|delete)" && v+=("Raw SQL is not permitted in app/. Use Eloquent models, relationships, and scopes. (Migrations and seeders may use DB::statement where Eloquent genuinely cannot express it.)")
  ;;
esac

# --- Rule: controllers stay thin (architecture.md §4.2) ------------------
case "$rel" in app/Http/Controllers/*)
  hit "->(join|leftJoin|rightJoin)\(|DB::" && v+=("Controllers must not query. Validate, delegate to a Service, return a Resource.")
  hit "\\\$request->all\(\)" && v+=("Never pass \$request->all() onward. Use a FormRequest and its validated() output.")
  ;;
esac

# --- Rule: the client never holds an answer key (architecture.md §5.4) ----
case "$rel" in resources/js/*|resources/views/*)
  hit "is_correct|correct_structure_id|correct_option_id|correct_sequence" && v+=("ANSWER KEY LEAK: '$rel' references a correctness field. Answers are stripped by the API Resource and validated server-side only (architecture.md §5.4 rule 2). The client must never receive them.")
  ;;
esac

# --- Rule: templates display, they do not query (architecture.md §1) -----
case "$rel" in resources/views/*.blade.php|resources/js/Pages/*|resources/js/Components/*)
  hit "::where\(|->get\(\)|->first\(\)|DB::|::query\(" && v+=("Templates must contain no queries or business logic. Move it into a Service and pass the result in as data.")
  ;;
esac

# --- Rule: strict types on new PHP (docs/engineering.md §2) --------------
case "$rel" in app/*.php|database/*.php|tests/*.php)
  if ! grep -qE 'declare\(strict_types=1\)' "$file"; then
    v+=("Missing 'declare(strict_types=1);' — required on every PHP file in this project.")
  fi
  ;;
esac

# --- Rule: the normalisation constant is a hard contract (§5.4 rule 1) ---
if grep -qE '\bFIT_SIZE\b' "$file" && grep -qE 'FIT_SIZE *[=:] *' "$file"; then
  if ! grep -qE 'FIT_SIZE *[=:] *3\.8' "$file"; then
    v+=("FIT_SIZE must stay 3.8. Every anchor_position in the database is authored in that normalised pivot space; changing it silently invalidates all of them (architecture.md §5.4 rule 1).")
  fi
fi

[ ${#v[@]} -eq 0 ] && exit 0

reason="Architecture rule violation in ${rel}:"
for item in "${v[@]}"; do reason="${reason}"$'\n'"  • ${item}"; done
reason="${reason}"$'\n'"Fix before continuing. Rules: docs/engineering.md, docs/architecture.md §4.2."
jq -n --arg r "$reason" '{decision:"block",reason:$r}'
exit 0
