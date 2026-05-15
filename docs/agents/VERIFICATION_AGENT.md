# Verification Agent Guide

This document defines the operating rules for any verification agent working on this repository.

The verification agent may be an AI execution assistant or a human reviewer. The role is tool-agnostic.

---

## Project

Repository:

```
/home/xherques/filament-impersonation
```

Composer package:

```
chuimi/filament-impersonation
```

PHP namespace:

```
Chuimi\FilamentImpersonation
```

Public repository:

```
https://github.com/it-chuimi/filament-impersonation
```

---

## Role

The verification agent is responsible for validating work produced by the implementation agent.

Typical responsibilities:

- inspect repository state
- inspect diffs
- validate expected files
- run syntax checks
- run Composer validation
- run tests when available
- detect unexpected changes
- detect forbidden references
- commit or push only when explicitly authorized by technical direction

---

## Source of truth

Before validating architectural or behavioral changes, read:

```
docs/ARCHITECTURE.md
```

That file is the source of truth for package design and agreed decisions.

---

## Handling doubts and ambiguities

If you encounter any ambiguity, missing context, conflict between documents, inability to run a command, uncertainty about whether something is in or out of scope, or any doubt that may affect the verification report, stop and ask technical direction before continuing.

Do not assume unverified behavior is valid.
Do not present uncertain findings as confirmed.
Do not propose code corrections as closed architectural decisions when they require technical direction.

---

## General rules

Execute commands in WSL inside:

```
/home/xherques/filament-impersonation
```

unless explicitly instructed otherwise.

- Do not modify files unless explicitly instructed.
- Do not install dependencies unless explicitly instructed.
- Do not commit unless explicitly authorized.
- Do not push unless explicitly authorized.
- If unexpected files are detected, stop and report before continuing.

---

## Standard repository inspection

```bash
pwd
git status --short
git branch -vv
git log --oneline --decorate -5
```

For file discovery:

```bash
find . -maxdepth 4 -type f \
  -not -path "./.git/*" \
  -not -path "./.idea/*" \
  -not -path "./vendor/*" \
  | sort
```

---

## Diff review

For unstaged changes:

```bash
git diff --stat
git diff
```

For staged changes:

```bash
git diff --cached --stat
git diff --cached
git diff --cached --name-only
```

Always verify that only expected files are changed or staged.

---

## Forbidden references check

The package must not contain references to private/internal domains or source-project entities.

Use checks like:

```bash
grep -RniE "GeSerMin|CHUIMI interno|hospital|huelga|huelgas|Strike|Center|Attendance|Staff|CHUIMI-PC|gesermin|suplantación" . \
  --exclude-dir=.git \
  --exclude-dir=.idea \
  --exclude-dir=vendor \
  || true
```

False positives must be reported clearly.

---

## PHP syntax validation

If local PHP is available:

```bash
find src config resources/lang tests -name "*.php" -print -exec php -l {} \;
```

If local PHP is unavailable, use Docker:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.2-cli sh -c \
  'find src config resources/lang tests -name "*.php" -print -exec php -l {} \;'
```

---

## Composer validation

First validate JSON syntax:

```bash
python3 -m json.tool composer.json > /tmp/filament-impersonation-composer.json.validated
```

Then validate Composer metadata:

```bash
composer validate --strict
```

If Composer in WSL resolves to a Windows Composer installation or is not usable, use Docker:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 composer validate --strict
```

Warnings caused by Docker/Git ownership must be distinguished from real Composer errors.

---

## Tests

If dependencies are installed:

```bash
vendor/bin/pest
```

If `vendor/bin/pest` does not exist, report that tests could not be executed and do not install dependencies unless explicitly instructed.

---

## Commit rules

Commit only when technical direction explicitly authorizes it.

Before committing:

1. Run `git status --short`.
2. Run `git diff --stat`.
3. Stage only expected files.
4. Run `git diff --cached --stat`.
5. Run `git diff --cached --name-only`.
6. Confirm staged files exactly match the expected list.
7. If anything unexpected appears, stop and do not commit.

Example commit command only when authorized:

```bash
git commit -m "docs: add architecture notes"
```

After commit:

```bash
git status --short
git log --oneline --decorate -2
```

---

## Push rules

Push only when technical direction explicitly authorizes it.

Before pushing:

```bash
git status
git branch -vv
git log --oneline --decorate -5
```

Then:

```bash
git push
```

After pushing:

```bash
git status
git branch -vv
git log --oneline --decorate -5
```

Confirm that the local branch is up to date with `origin/main`.

---

## Expected final response after verification

After verification, report:

1. Current repository state.
2. Files detected or changed.
3. Validation commands executed.
4. Validation results.
5. Problems found, if any.
6. Recommendation: apt / not apt.
7. Confirmation that no files were modified, unless explicitly authorized.
8. Confirmation that no commit or push was made, unless explicitly authorized.

---

## Current implementation strategy

The first implementation block focuses only on the core independent of Filament.

**Included:**

- `config/filament-impersonation.php`
- `src/ImpersonationManager.php`
- `src/Support/ImpersonationActivity.php`
- `src/Support/ImpersonationAuthorization.php`
- `src/Support/ImpersonationLogoutReason.php`
- `src/Exceptions/*`
- `tests/Feature/ImpersonationManagerTest.php`

**Out of scope for the first implementation block:**

- `ImpersonateAction`
- `ImpersonationPlugin`
- Banner automatic registration
- `StopImpersonationController`
- Routes
- Logout listener
- `HasImpersonationActivityContext` trait

Use `docs/ARCHITECTURE.md` as the reference when checking whether a change is within scope.
