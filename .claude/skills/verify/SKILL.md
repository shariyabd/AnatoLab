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
# 1. Format (mutates files; run first so later steps see formatted code)
[ -x vendor/bin/pint ] && vendor/bin/pint --test

# 2. Static analysis
[ -x vendor/bin/phpstan ] && vendor/bin/phpstan analyse --no-progress

# 3. PHP tests
[ -x vendor/bin/pest ] && vendor/bin/pest --compact

# 4. Schema builds from scratch (catches migrations that only work incrementally)
[ -f artisan ] && php artisan migrate:fresh --env=testing --force

# 5. Routes resolve (catches a controller or method that does not exist)
[ -f artisan ] && php artisan route:list > /dev/null

# 6. Frontend
[ -d node_modules ] && npm run test --if-present && npm run build

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
  PHPStan       …
  Pest          … (N passed, M failed)
  migrate:fresh …
  route:list    …
  Frontend      …
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
