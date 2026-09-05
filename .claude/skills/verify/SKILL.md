---
name: verify
description: Run this project's full verification gate (Pint, PHPStan, Pest, Vitest, migrations, boundary audit) and report pass/fail with real command output. Use before claiming work is complete, before committing, and before opening a PR. Never claim "tests pass" without running this.
---

# Verify

The Definition of Done gate (docs/engineering.md §11). Run every applicable step,
show real output, and report honestly. **A step that was skipped is reported as
skipped, never as passed.**

## Procedure

Run in this order. Do not stop at the first failure — collect all results, so one
report gives the full picture.

```bash
# 1. Formatting — check, do not mutate. A verify run that rewrites files makes
#    the diff you are about to review different from the one you tested.
[ -x vendor/bin/pint ] && vendor/bin/pint --test
[ -d node_modules ] && npm run format:check

# 2. Static analysis
[ -x vendor/bin/phpstan ] && vendor/bin/phpstan analyse --no-progress
[ -d node_modules ] && npm run typecheck

# 3. PHP tests
[ -x vendor/bin/pest ] && vendor/bin/pest --compact

# 4. Schema builds from scratch (catches migrations that only work incrementally)
#    --seed as well: a seeder that broke is a broken demo, and the demo is a
#    first-class artefact (docs/engineering.md §6).
[ -f artisan ] && php artisan migrate:fresh --seed --force

# 5. Routes resolve (catches a controller or method that does not exist, and a
#    duplicate route name where the later registration silently wins)
[ -f artisan ] && php artisan route:list > /dev/null

# 6. Frontend
[ -d node_modules ] && npm run test && npm run build

# 6b. Performance budgets (docs/architecture.md §15.1). Both read their limits
#     from config/anatomy.php, so there is one number to change.
#     bundle:verify needs step 6's build to have run.
[ -d node_modules ] && npm run bundle:verify
[ -d node_modules ] && npm run models:verify

# 7. Architecture boundaries across the whole repo
/boundary-audit

# 8. No secret reached the client bundle
[ -d public/build ] && ! grep -rEl '(sk-ant-|sk-[A-Za-z0-9]{32}|AKIA[0-9A-Z]{16})' public/build
```

Steps whose tooling is absent (early phases) are **skipped, not failed** — say so
explicitly in the report.

## Report format

```
VERIFICATION — <branch> @ <short sha>

  Pint          PASS | FAIL | SKIPPED (not installed)
  Prettier      …
  PHPStan       …
  vue-tsc       …
  Pest          … (N passed, M failed)
  migrate:fresh …
  route:list    …
  Vitest        …
  Build         …
  Bundle budget …
  Model budget  …
  Boundaries    …
  Secret scan   …

RESULT: PASS / FAIL
<on failure: the failing command and its actual output>
```

## Rules

- Never summarise output you did not see. Paste the real failure.
- Never report PASS when any step failed or when a required step could not run.
- If a test is failing for a reason unrelated to the current change, say that
  explicitly and name the test — do not silently exclude it.
- Do not "fix" a failure by deleting, skipping, or loosening the assertion.

## Environment prerequisites

Steps 3-5 need a `.env` (Laravel reads `APP_KEY` and the database connection from
it). On a fresh clone:

```bash
cp .env.example .env && php artisan key:generate
```

Without it, Pest still runs — `phpunit.xml` supplies its own environment — but every
test emits a "failed to open .env" warning, and `migrate:fresh` falls back to
`database/database.sqlite` rather than the connection you think you are testing.
Report that as **SKIPPED (no .env)**, never as a pass.
