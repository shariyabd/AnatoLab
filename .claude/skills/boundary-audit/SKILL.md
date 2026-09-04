---
name: boundary-audit
description: Sweep the whole repository for architecture and dependency-rule violations (service/request coupling, viewer framework imports, answer-key leaks, raw SQL, logic in templates, strict_types, FIT_SIZE drift). Use before merging a lane, when picking up unfamiliar code, or when the per-file hook may have been bypassed.
---

# Boundary Audit

The PostToolUse hook checks one file as it is written. This sweeps everything —
including code that predates the hook, arrived by merge, or was written with hooks
disabled.

## Run

```bash
echo "── Services reading request/auth/session (must take typed arguments) ──"
grep -rnE '\brequest\(|\bauth\(|\bsession\(' app/Services/ 2>/dev/null

echo "── Services depending on Infrastructure concretes (must use App\\Contracts) ──"
grep -rn 'App\\Infrastructure' app/Services/ 2>/dev/null

echo "── Models depending on Services or Http (dependencies point inward) ──"
grep -rnE 'App\\(Services|Http)' app/Models/ 2>/dev/null

echo "── Raw SQL in app/ (Eloquent only) ──"
grep -rnE 'DB::(raw|table|select|statement|insert|update|delete)' app/ 2>/dev/null

echo "── Controllers querying or passing \$request->all() ──"
grep -rnE '->(join|leftJoin)\(|DB::|\$request->all\(\)' app/Http/Controllers/ 2>/dev/null

echo "── Viewer importing Vue/Inertia or fetching (must be framework-free) ──"
grep -rnE "from ['\"]vue['\"]|@inertiajs|from ['\"]axios['\"]|\bfetch\(" resources/js/anatomy/ 2>/dev/null

echo "── ANSWER KEY leaking to the client ──"
grep -rnE 'is_correct|correct_structure_id|correct_option_id|correct_sequence' resources/js/ resources/views/ 2>/dev/null

echo "── Queries or logic in templates ──"
grep -rnE '::where\(|->get\(\)|->first\(\)|DB::' resources/views/ resources/js/Pages/ resources/js/Components/ 2>/dev/null

echo "── PHP files missing declare(strict_types=1) ──"
find app database tests -name '*.php' 2>/dev/null | xargs -r grep -LE 'declare\(strict_types=1\)'

echo "── FIT_SIZE drift (must be 3.8 everywhere) ──"
grep -rnE 'FIT_SIZE' app/ config/ resources/js/ 2>/dev/null | grep -v '3\.8'

echo "── N+1 risk: loops over relations without eager loading ──"
grep -rnE 'foreach .*->\w+ as ' resources/views/ app/Http/ 2>/dev/null

echo "── Endpoints without a FormRequest ──"
grep -rlnE 'function (store|update|attempt|ask|complete)\(Request \$request' app/Http/Controllers/ 2>/dev/null
```

## Reporting

Every hit is a **candidate**, not a confirmed violation — open the file and judge
it before reporting. Legitimate exceptions exist (a migration using `DB::statement`
for something Eloquent cannot express; a seeder; the word `fetch` inside a comment).

Report as a table: file:line · rule broken · why it matters · suggested fix.
Group by rule, most severe first — **answer-key leaks and secret exposure outrank
everything else**. If nothing is found, say so plainly; do not invent findings.
