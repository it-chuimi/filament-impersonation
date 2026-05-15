# Implementation Agent Guide

This document defines the operating rules for any implementation agent working on this repository.

The implementation agent may be an AI coding assistant or a human developer. The role is tool-agnostic.

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

The implementation agent is responsible for creating or modifying files according to a scoped task.

Typical responsibilities:

- implement PHP code
- create or update tests
- create or update package configuration
- create or update Blade views
- create or update translations
- create or update documentation
- keep changes limited to the requested scope

---

## Source of truth

Before making architectural or behavioral changes, read:

```
docs/ARCHITECTURE.md
```

That file is the source of truth for:

- package goals
- architecture decisions
- impersonation flow
- audit behavior
- session payload
- authorization rules
- Filament integration strategy
- first implementation block scope
- out-of-scope items

Do not override architecture decisions unless explicitly instructed by technical direction.

---

## Handling doubts and ambiguities

If you encounter any ambiguity, missing context, conflict between documents, uncovered architectural decision, relevant technical doubt, or any uncertainty that may affect design, scope, behavior, tests, or implementation, stop and ask technical direction before continuing.

Do not improvise.
Do not make new architectural decisions.
Do not expand the agreed scope.
Do not implement alternative solutions without explicit approval.

---

## Non-negotiable package rules

This is a public reusable package.

Do not include references to:

- private/internal source projects
- internal organization systems
- organization-specific infrastructure
- source-application-specific operational domains
- domain-specific business entities from the original application
- any private application domain

The package must remain generic.

Visible UI text must use translations.

---

## Compatibility

Maintain compatibility with:

- PHP >= 8.2
- Laravel >= 12
- Filament >= 5
- `spatie/laravel-activitylog`
- optional/configurable `spatie/laravel-permission`

Do not introduce dependencies unless explicitly instructed.

---

## Scope discipline

Before modifying files:

1. Read the files involved in the task.
2. Check the current project structure.
3. Identify which files are in scope.
4. Do not modify files outside the requested scope.
5. If an unexpected file appears necessary, stop and report it.

Do not perform broad refactors unless explicitly requested.

Do not implement future phases early.

For example, when working on the core manager block, do not implement:

- Filament Action
- Filament Plugin
- automatic banner registration
- routes
- controller
- logout listener
- activity context trait

---

## Git rules

Do not commit unless explicitly authorized.

Do not push unless explicitly authorized.

After modifying files, always show:

```bash
git status --short
git diff --stat
```

If useful, also show the relevant diff.

Never include unexpected files.

Never include:

- `.idea/`
- `vendor/`
- `node_modules/`

---

## Validation rules

For PHP files, run syntax validation when possible:

```bash
php -l path/to/file.php
```

If PHP is unavailable locally, Docker may be used.

For `composer.json`, use:

```bash
python3 -m json.tool composer.json
composer validate --strict
```

If local Composer is not usable from WSL, Docker may be used:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 composer validate --strict
```

Run tests only when dependencies are available and the task requires it.

Do not install dependencies unless explicitly instructed.

---

## Expected final response after a task

After completing a task, report:

1. Files created or modified.
2. Summary of the technical change.
3. Validation commands executed.
4. Validation results.
5. `git status --short`.
6. `git diff --stat`.
7. Confirmation that no commit or push was made.

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

Refer to `docs/ARCHITECTURE.md` before implementing this block.
