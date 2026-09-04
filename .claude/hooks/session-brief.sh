#!/usr/bin/env bash
# SessionStart — every agent starts with the same invariants and its lane map.
# Short by design: this is injected into every session's context.
set -uo pipefail
root="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
branch=$(git -C "$root" branch --show-current 2>/dev/null || echo "?")

read -r -d '' brief <<EOF || true
Project: Interactive 3D Anatomy Learning Platform (Laravel 11 + Inertia + Vue 3 + MySQL).
Branch: ${branch}. Rules: docs/engineering.md. Design: docs/architecture.md. Audit: docs/project-context.md.

Invariants — hooks enforce these, do not work around them:
1. Services never read request()/auth()/session(). Typed arguments only.
2. Services depend on App\\Contracts, never App\\Infrastructure concretes.
3. resources/js/anatomy/ imports no Vue, no Inertia, and never fetches.
4. The client never receives an answer key (is_correct, correct_structure_id).
5. FIT_SIZE is 3.8. Changing it invalidates every anchor_position in the DB.
6. Eloquent only in app/. declare(strict_types=1) on every PHP file.
7. Stay in your lane; ".claude/hooks/", app/Contracts/ and docs/ are shared — edits there prompt the human.

The 3D models are single-mesh (no per-structure geometry) and the upstream repo has NO licence.
Both facts are load-bearing: see docs/project-context.md §2.2 and §3 before designing around them.
EOF

jq -n --arg c "$brief" '{hookSpecificOutput:{hookEventName:"SessionStart",additionalContext:$c}}'
