# Filament Impersonation - Architecture Notes

## Purpose

`chuimi/filament-impersonation` is a public Composer package that provides controlled user impersonation for Laravel and Filament applications.

The package is designed to be reusable across different projects and must not contain references to any private/internal domain, project-specific entities, or application-specific business logic.

This document records the agreed architecture, design decisions, and implementation boundaries for future maintainers and AI-assisted development agents.

This document represents the current state of the package for v0.1.0.

---

## Package identity

Composer package:

```bash
chuimi/filament-impersonation
```

PHP namespace:

```
Chuimi\FilamentImpersonation
```

Main configuration file:

```
config/filament-impersonation.php
```

Configuration key:

```php
config('filament-impersonation')
```

---

## Compatibility

Target compatibility:

- PHP >= 8.2
- Laravel >= 12
- Filament >= 5
- `spatie/laravel-activitylog` required
- `spatie/laravel-permission` optional/configurable

---

## Public package rules

The package must remain generic and reusable.

It must not contain references to:

- private/internal source projects
- internal organization systems
- organization-specific infrastructure
- domain-specific business entities from the source application
- any private application domain

All tests must be generic and specific to this package.

The package must use translations for visible UI texts.

---

## Orchestration workflow

Development is coordinated using the following roles:

### ChatGPT

Acts as technical direction and architecture owner.

Responsibilities:

- define steps
- decide architecture
- review outputs
- prepare prompts for Claude and Codex
- authorize commits/pushes when appropriate
- keep the process step-by-step

### Claude

Acts as the main developer/coder.

Responsibilities:

- create or modify files
- implement code according to scoped prompts
- not commit or push unless explicitly authorized
- not modify files outside the requested scope

### Codex

Acts as verifier/executor.

Responsibilities:

- run commands
- inspect git status
- inspect git diff
- run linting, Composer validation and tests
- validate expected files
- commit/push only when explicitly authorized by technical direction

### User

Acts as bridge between tools.

Responsibilities:

- copy prompts
- return outputs
- confirm each step before moving to the next

---

## Workflow rules

- All work must be done step by step.
- Do not proceed to the next step until the previous one has been confirmed.
- Commands must be executed in WSL inside:

```
/home/xherques/filament-impersonation
```

Unless explicitly stated otherwise.

- Before every commit:
  - review `git status`
  - review `git diff --stat`
  - review `git diff` or staged diff
  - validate that only expected files are included
- Claude must not commit or push unless explicitly instructed.
- Codex may commit or push only when explicitly authorized.
- Do not install dependencies unless explicitly instructed.
- For PHP files, validate with:

```bash
php -l
```

If local PHP is unavailable, Docker may be used.

- For `composer.json`, validate with:

```bash
python3 -m json.tool composer.json
composer validate --strict
```

If Composer in WSL fails because it resolves to a Windows Composer installation, Docker may be used:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 composer validate --strict
```

---

## Repository status

The repository is public on GitHub:

```
https://github.com/it-chuimi/filament-impersonation
```

Initial local commit:

```
3832c32 chore: initial package scaffold
```

Initial scaffold includes:

- `.gitignore`
- `LICENSE`
- `README.md`
- `composer.json`
- `config/filament-impersonation.php`
- `resources/lang/en/messages.php`
- `resources/lang/es/messages.php`
- `resources/views/banner.blade.php`
- `src/ImpersonationServiceProvider.php`
- `tests/Feature/ServiceProviderTest.php`
- `tests/Pest.php`
- `tests/TestCase.php`

---

## Core design decisions

### Real authentication switch

Impersonation changes the authenticated Laravel user for real.

The package uses:

```php
Auth::guard($guard)->login($targetUser);
```

Before switching users, the package stores the original operator context in session.

This allows Laravel and Filament to apply permissions, policies, menus, visibility and UI behavior as the impersonated user.

The audit trail must not lose the real operator.

Actions performed during impersonation can be enriched with:

- `operator_user_id`
- `operator_user_type`
- `impersonated_user_id`
- `impersonated_user_type`
- `impersonation_activity_id`

### Guard handling

The guard is configurable.

Configuration:

```php
'guard' => null,
```

Meaning:

- `null`: use the currently detected guard
- `string`: force a specific guard, such as `web` or `admin`

During start:

- resolve configured/current guard
- store original guard in session
- login as the target user with that guard

During stop:

- recover the stored operator guard
- validate it against `config('auth.guards')` before use
- if the stored value is missing, empty, or not a known guard, fall back to auto-detecting the current guard
- restore the operator with the resolved guard
- if restoration is not possible, logout safely

### Reason requirement

A reason is always mandatory when starting impersonation. This is not configurable.

The minimum length is configurable:

```php
'reason' => [
    'min_length' => 10,
],
```

The reason is stored only in `impersonation.started`.

It is not repeated in every later audited action.

Later actions are linked to the start event using `impersonation_activity_id`.

### No nested impersonation

Nested impersonation is forbidden.

If an impersonation is already active, `start()` rejects a new impersonation.

The user must stop the current impersonation before starting another.

This avoids ambiguous chains such as:

```
Operator A impersonates B, then B impersonates C
```

The audit relationship must remain clean:

```
operator_user_id -> impersonated_user_id -> impersonation_activity_id
```

### Self impersonation

Self impersonation is always forbidden.

If the operator and target user have the same `getAuthIdentifier()` and the same class, `start()` is blocked.

There is no `allow_self_impersonation` configuration.

### Session payload

The package stores a structured payload in session.

The payload includes:

```php
[
    'operator_user_id'         => $operator->getAuthIdentifier(),
    'operator_user_type'       => $operator::class,
    'operator_guard'           => $guard,

    'impersonated_user_id'     => $target->getAuthIdentifier(),
    'impersonated_user_type'   => $target::class,
    'impersonated_guard'       => $guard,

    'impersonation_activity_id' => $activity->id,
    'started_at'               => now()->toISOString(),
]
```

The manager API includes:

```php
public function isImpersonating(): bool;

public function payload(): ?array;
```

`payload()` returns:

- `array` if impersonation is active
- `null` if impersonation is not active

It does not throw an exception when there is no active impersonation.

### Session regeneration

The session is regenerated when impersonation starts and when it stops normally.
The session is invalidated on forced stop.

**Start flow:**

1. validate package is enabled
2. resolve guard
3. resolve operator from guard — abort with `RuntimeException` if no authenticated user
4. validate there is no active impersonation (no nested sessions)
5. validate target is not self
6. validate target is not protected
7. validate authorization
8. validate reason
9. create `impersonation.started` — mandatory; if this fails, `ImpersonationStartFailedException` is thrown and start is aborted with no session written
10. store payload in session
11. login as target user
12. regenerate session

**Normal stop flow:**

1. read payload — return `false` immediately if no impersonation is active
2. resolve and validate the guard (fallback to auto-detect if stored value is invalid or unknown)
3. resolve original operator via user model lookup and `is_restorable_user` check
4. if operator is unresolvable or not restorable → forced stop (see below)
5. attempt `Auth::guard($guard)->login($operator)` — if this throws → forced stop with `restore_failed`
6. only if login succeeds: record `impersonation.stopped` (non-blocking — failure is reported via `report()` but does not block cleanup)
7. clear the impersonation session key
8. regenerate session

**Forced stop flow:**

Triggered when the operator cannot be resolved or found, is not restorable, or the login call throws.

1. record `impersonation.stopped_by_logout` with the applicable reason (non-blocking)
2. clear the impersonation session key
3. `Auth::guard($guard)->logout()`
4. `session()->invalidate()` and `session()->regenerateToken()`

`impersonation.stopped` is never recorded in a forced stop. The audit trail uses `impersonation.stopped_by_logout` instead.

### Package enabled flag

Configuration:

```php
'enabled' => true,
```

If `config('filament-impersonation.enabled') === false`, then:

- `start()` throws `PackageDisabledException`
- `canImpersonate()` returns `false`
- `ImpersonateAction` is hidden via its `visible()` check

`stop()` remains idempotent and returns `false` if there is no active impersonation.

### Stop behavior

`stop()` is idempotent.

Signature:

```php
public function stop(): bool;
```

Behavior:

- returns `true` if an active impersonation was stopped
- returns `false` if no impersonation was active
- does not throw a visible exception when no impersonation is active
- does not register `impersonation.stopped` if there was no active impersonation

This protects against double clicks, stale tabs, expired sessions and manual route calls.

### Operator restoration

When stopping impersonation, the package attempts to restore the original operator.

If the original operator:

- no longer exists
- cannot be loaded
- cannot be restored according to project logic

then the package:

- logouts completely on the corresponding guard
- cleans all impersonation session keys
- registers `impersonation.stopped_by_logout` if possible
- lets authentication middleware redirect to login or a safe destination

The impersonated user must not remain authenticated in an ambiguous state.

### Restorable user callback

The package does not assume how an application marks users as disabled.

Configuration:

```php
'is_restorable_user' => null,
```

Rules:

- the package always checks that the operator exists
- if `is_restorable_user` is callable, it decides whether the operator may be restored
- if the callback returns `false`, forced stop with `operator_not_restorable`
- if there is no callback, existence is enough

Example:

```php
'is_restorable_user' => fn ($user): bool => $user->active === true,
```

No mandatory interface is required on the User model.

### User model resolution

Configuration:

```php
'user_model' => null,
```

Rules:

1. If `user_model` contains a FQCN, use it. If the class does not exist → forced stop with `user_model_not_resolvable`. An explicitly configured but invalid class is never silently bypassed.
2. If `user_model` is `null`, resolve the provider from:
   ```
   config("auth.guards.{$guard}.provider")
   ```
3. Resolve the model from:
   ```
   config("auth.providers.{$provider}.model")
   ```
4. If not resolvable, fallback to:
   ```
   config('auth.providers.users.model')
   ```
5. If the model cannot be resolved or the operator cannot be loaded, forced stop with `user_model_not_resolvable` or `restore_failed`.

### Authorization

Authorization uses a configurable callback with fallback to roles/permissions.

Configuration:

```php
'can_impersonate' => null,

'operator_roles'       => [],
'operator_permissions' => [],
```

Rules:

- If `can_impersonate` is callable, it is the sole authorization criterion. Roles and permissions are not evaluated.
- If no callback is set, `operator_roles` and `operator_permissions` are both evaluated (AND logic: both checks must pass if both are configured).
- `operator_roles`: if not empty, the operator must have at least one listed role (`hasAnyRole`). Empty array = no role check.
- `operator_permissions`: if not empty, the operator must have all listed permissions. Empty array = no permission check.
- If a role check is required but the model does not implement `hasAnyRole()`, the check is denied for safety.
- If a permission check is required but the model does not implement `can()`, the check is denied for safety.

The callback and roles/permissions cannot bypass the safety rules enforced unconditionally by the manager:

- package disabled check
- active impersonation block (no nested sessions)
- self impersonation block
- protected user block
- mandatory start activity

### Protected users

Users that must never be impersonated are handled through protected roles and an optional callback.

Configuration:

```php
'protected_roles' => [],

'is_protected_user' => null,
```

Rules:

- `protected_roles` is evaluated first. If the array is not empty and the target has any of those roles, impersonation is denied.
- If `protected_roles` is not empty and the model does not support `hasAnyRole()`, the target is considered protected for safety.
- `is_protected_user` is evaluated second, if callable.
- If either mechanism marks the user as protected, impersonation is denied.

Example:

```php
'is_protected_user' => fn ($target): bool => $target->is_system_account === true,
```

---

## Activity log events

The package uses `spatie/laravel-activitylog`.

Events:

- `impersonation.started`
- `impersonation.stopped`
- `impersonation.stopped_by_logout`

### `impersonation.started`

Registered when impersonation starts successfully.

This event is mandatory. If creating this activity fails, `ImpersonationStartFailedException` is thrown and impersonation does not start.

Properties:

- `operator_user_id`
- `operator_user_type`
- `operator_guard`
- `impersonated_user_id`
- `impersonated_user_type`
- `impersonated_guard`
- `reason`
- `started_at`
- `ip_address`
- `user_agent`

The created activity ID is stored in session as `impersonation_activity_id`.

### `impersonation.stopped`

Registered when impersonation stops normally and the original operator is restored.

Recorded only after `Auth::guard($guard)->login($operator)` succeeds. If login throws, this event is never registered and the flow falls back to `impersonation.stopped_by_logout` with `restore_failed`.

If this log fails after a successful login, the package still exits impersonation safely. The failure is forwarded to Laravel's exception handler via `report()`.

Properties:

- `operator_user_id`
- `operator_user_type`
- `operator_guard`
- `impersonated_user_id`
- `impersonated_user_type`
- `impersonated_guard`
- `impersonation_activity_id`
- `started_at`
- `stopped_at`
- `duration_seconds`
- `ip_address`
- `user_agent`

`duration_seconds` is calculated between `started_at` and `stopped_at`.

### `impersonation.stopped_by_logout`

Registered when impersonation ends through a manual logout or because the original operator cannot be safely restored.

If this log fails, the package still cleans the session and performs logout safely. The failure is forwarded via `report()`.

Properties:

- `operator_user_id`
- `operator_user_type`
- `operator_guard`
- `impersonated_user_id`
- `impersonated_user_type`
- `impersonated_guard`
- `impersonation_activity_id`
- `started_at`
- `stopped_at`
- `duration_seconds`
- `ip_address`
- `user_agent`
- `logout_reason`

`logout_reason` values:

- `operator_not_found`
- `operator_not_restorable`
- `user_model_not_resolvable`
- `manual_logout`
- `restore_failed`

---

## Logout handling

Manual logout during active impersonation is detected using a listener for:

```
Illuminate\Auth\Events\Logout
```

The listener is registered automatically by `ImpersonationServiceProvider`.

Rules:

- if there is no active impersonation, do nothing
- if there is active impersonation, call `stopForLogout(ManualLogout)`
- `stopForLogout` records `impersonation.stopped_by_logout` with `logout_reason = manual_logout` (non-blocking)
- cleans impersonation session payload
- does not restore the operator — the user explicitly logged out
- does not block the normal Laravel logout flow

The service avoids double registration: normal `stop()` does not trigger the logout listener because `stop()` restores the operator rather than logging out.

---

## Audited model enrichment

The package provides a manual trait for models that use `spatie/laravel-activitylog`.

Trait:

```
Chuimi\FilamentImpersonation\Concerns\HasImpersonationActivityContext
```

The consumer adds it manually only to auditable models that should receive impersonation context.

If a model uses both `LogsActivity` and `HasImpersonationActivityContext`, its activities are enriched with:

- `impersonation_activity_id`
- `operator_user_id`
- `operator_user_type`
- `impersonated_user_id`
- `impersonated_user_type`

If a model does not use the trait, the package does not modify its logs.

There is no global observer and no middleware that modifies all logs. The trait is opt-in per model.

### Activity hook

The trait uses Spatie's hook:

```php
tapActivity(Activity $activity, string $eventName): void
```

When impersonation is active, it:

- retrieves the session payload
- sets `causer_type` and `causer_id` directly on the activity to the original operator
- merges impersonation context into `activity->properties` without overwriting existing keys

If there is no active impersonation, it does nothing.

### Caveats

**tapActivity conflict**: If the consuming model already defines its own `tapActivity()`, both implementations cannot coexist through normal trait resolution. The consumer must manually call the trait method from their own implementation or merge the behavior manually.

**Morph map**: The trait stores `operator_user_type` as the raw Eloquent model FQCN (e.g. `App\Models\User`), not a morph alias. If the consuming application uses a custom morph map, queries filtering on `operator_user_type` must resolve the FQCN, not the alias.

---

## Filament Action

The package provides a reusable Filament Action:

```
Chuimi\FilamentImpersonation\Filament\Actions\ImpersonateAction
```

The consumer adds it manually where needed.

The package does not inject it automatically into any `UserResource` class.

The Action:

- checks visibility via `ImpersonationManager::canImpersonate()`
- shows the required reason form with minimum length validation from config
- calls `ImpersonationManager::start()` and catches all package-specific exceptions
- shows translated success/error notifications
- redirects after successful start using `RedirectResolver::afterStart()`

---

## Redirects

Configuration:

```php
'redirect_after_start' => null,

'redirect_after_stop' => null,
```

Each value may be:

- `null`
- `string`
- `callable`

Resolution rules:

- If callable: called with a context array containing `phase`, `operator`, `impersonated`, `payload`, and `request`. If the return value is a `RedirectResponse` or a string, it is used. Otherwise, the fallback applies.
- If string: used directly if it starts with `http://`, `https://`, or `/`. Otherwise, treated as a named route via `route($value)`. If the named route does not exist, falls back to `'/'`.
- If `null` (or callable returns a non-string/non-RedirectResponse): falls back to `url()->previous('/')`.

**Callable exceptions are not caught.** A misconfigured callable will surface to the application's exception handler. This is intentional: misconfigured redirects must be visible to the consuming application.

Redirect logic is centralized in:

```
Chuimi\FilamentImpersonation\Support\RedirectResolver
```

---

## Exit route and controller

The normal exit flow uses a POST route and controller.

Default route:

```
POST /impersonation/stop
```

Default route name:

```
impersonation.stop
```

Default middleware:

```php
['web', 'auth']
```

Configuration:

```php
'routes' => [
    'enabled'    => true,
    'middleware' => ['web', 'auth'],
    'prefix'     => 'impersonation',
    'name'       => 'impersonation.',
],
```

If `routes.enabled` is `false`:

- the package does not register the route
- the consumer must provide its own route or button

The default flow is:

```
banner -> POST route('impersonation.stop') -> StopImpersonationController -> ImpersonationManager::stop() -> RedirectResolver::afterStop()
```

The controller captures the session payload and the impersonated user before calling `stop()`, because `stop()` clears the session.

---

## Banner and Filament plugin

The impersonation banner is a security signal.

It is shown whenever there is an active impersonation inside a panel where the plugin is registered.

The package provides:

```
Chuimi\FilamentImpersonation\Filament\ImpersonationPlugin
```

The consumer registers it manually in each Filament panel where impersonation should be supported:

```php
->plugins([
    ImpersonationPlugin::make(),
])
```

Rules:

- the package does not automatically register the plugin in any panel
- once registered in a panel, the banner is rendered at `PanelsRenderHook::BODY_START`
- the banner is shown conditionally: only when `ImpersonationManager::isImpersonating()` returns `true`
- the banner includes a "Leave impersonation" button that submits a POST form to `route('impersonation.stop')`
- if a project has multiple panels where an impersonated session may continue, the plugin must be registered in all of them

---

## Internal structure

Current structure:

```
src/
├─ Concerns/
│  └─ HasImpersonationActivityContext.php
├─ Exceptions/
│  ├─ CannotImpersonateSelfException.php
│  ├─ ImpersonationAlreadyActiveException.php
│  ├─ ImpersonationStartFailedException.php
│  ├─ OperatorNotRestorableException.php
│  ├─ PackageDisabledException.php
│  ├─ ProtectedUserCannotBeImpersonatedException.php
│  ├─ UnauthorizedImpersonationException.php
│  └─ UserModelNotResolvableException.php
├─ Filament/
│  ├─ Actions/
│  │  └─ ImpersonateAction.php
│  └─ ImpersonationPlugin.php
├─ Http/
│  └─ Controllers/
│     └─ StopImpersonationController.php
├─ Listeners/
│  └─ HandleImpersonationLogout.php
├─ Support/
│  ├─ ImpersonationActivity.php
│  ├─ ImpersonationAuthorization.php
│  ├─ ImpersonationLogoutReason.php
│  └─ RedirectResolver.php
├─ ImpersonationManager.php
└─ ImpersonationServiceProvider.php
```

Responsibilities:

- `ImpersonationManager`: orchestrates `start`, `stop`, `stopForLogout`, `payload`, `isImpersonating`, `canImpersonate`
- `ImpersonationActivity`: records events in Spatie Activitylog
- `ImpersonationAuthorization`: evaluates `can_impersonate` callback, roles/permissions, and protected user checks
- `ImpersonationLogoutReason`: PHP 8.2 backed enum defining forced stop reason values
- `RedirectResolver`: resolves post-action redirects for start and stop
- `ImpersonateAction`: reusable Filament Action added manually by the consumer
- `ImpersonationPlugin`: registers the mandatory banner via Filament render hooks
- `StopImpersonationController`: handles the normal stop POST route
- `HandleImpersonationLogout`: listens to `Illuminate\Auth\Events\Logout` to intercept manual logouts
- `HasImpersonationActivityContext`: opt-in trait for auditable models

---

## Exceptions

The package uses custom exceptions.

Current exceptions:

- `PackageDisabledException` — thrown by `start()` when the package is disabled
- `ImpersonationAlreadyActiveException` — thrown by `start()` when a session is already active
- `CannotImpersonateSelfException` — thrown by `start()` on self-impersonation attempt
- `ProtectedUserCannotBeImpersonatedException` — thrown by `start()` when target is protected
- `UnauthorizedImpersonationException` — thrown by `start()` on authorization failure
- `ImpersonationStartFailedException` — thrown by `start()` when the mandatory start activity cannot be recorded
- `OperatorNotRestorableException` — available for consumer-side use; manager converts this scenario into a forced stop
- `UserModelNotResolvableException` — available for consumer-side use; manager converts this scenario into a forced stop

Actions and controllers translate start exceptions into user-facing notifications.

---

## Public API

No Laravel Facade is included.

Use dependency injection or container resolution:

```php
app(ImpersonationManager::class)
```

A Facade may be added in a future version without breaking compatibility.

---

## Publishables and service provider

`ImpersonationServiceProvider` handles all registration automatically through Laravel autodiscovery.

On `register()`:

- merges package config with application config
- registers `ImpersonationAuthorization`, `ImpersonationActivity`, `ImpersonationManager`, and `RedirectResolver` as singletons

On `boot()`:

- listens for `Illuminate\Auth\Events\Logout` → `HandleImpersonationLogout`
- loads views from `resources/views` under the `filament-impersonation` namespace
- loads translations from `resources/lang` under the `filament-impersonation` namespace
- registers the stop route if `routes.enabled` is `true`

Publishable groups:

- `filament-impersonation-config` → `config_path('filament-impersonation.php')`
- `filament-impersonation-views` → `resource_path('views/vendor/filament-impersonation')`
- `filament-impersonation-lang` → `app()->langPath('vendor/filament-impersonation')`

---

## CI and testing

The package is tested on GitHub Actions on every push to `main` and on every pull request.

Matrix:

- PHP 8.2, 8.3, 8.4
- Ubuntu latest

Pipeline steps:

1. `composer validate --strict`
2. `composer install --prefer-dist --no-interaction --no-progress`
3. `php vendor/bin/pest --configuration=phpunit.xml.dist`

Test framework: [Pest](https://pestphp.com/) with PHPUnit, Orchestra Testbench 10, SQLite in-memory.

Test suites defined in `phpunit.xml.dist`:

- `Feature` — main test suite under `tests/Feature/`
- `RoutesDisabled` — route-disabled scenarios under `tests/RoutesDisabled/`

---

## Out of scope for v0.1.0

The following are explicitly not included in this version:

- **No automatic action injection**: `ImpersonateAction` is never injected automatically into any `UserResource`. The consumer decides where to place it.
- **No global audit middleware**: `HasImpersonationActivityContext` is opt-in per model. There is no global observer or middleware that modifies all activitylog entries.
- **No Facade**: `ImpersonationManager` is accessed via DI or `app()`. No `Impersonation::` facade is provided.
- **No advanced configuration UI**: there is no Filament settings page or admin panel for the package configuration.
- **No distributed concurrency locks**: concurrent stop calls on the same session are handled by PHP's native session locking at the server level. No advisory locks or cache-based locks are implemented.
- **No morph map special support**: `operator_user_type` is stored as the Eloquent model FQCN. The package documents the caveat but does not resolve morph aliases automatically.
- **No consumer application-specific logic**: the package contains no assumptions about model naming, panel structure, or business rules of any consuming application.

---

## Validation rules before commits

Before every commit, verify:

```bash
git status --short
git diff --stat
git diff
```

For PHP files:

```bash
php -l path/to/file.php
```

If PHP is unavailable locally, use Docker.

For Composer:

```bash
python3 -m json.tool composer.json
composer validate --strict
```

If Composer in WSL is not usable:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 composer validate --strict
```

- Do not commit unexpected files.
- Do not include `.idea/`.
- Do not include generated vendor dependencies.

---

## Documentation expectations

`README.md` should remain focused on package users:

- installation
- publishing config/views
- registering the plugin
- adding the Action
- using the trait
- configuration examples

This file, `docs/ARCHITECTURE.md`, is the technical source of truth for architecture and decisions.

If the design changes, update this file in the same pull request or commit series.

---

## Semantic versioning

The package is published using semantic versioning.

Breaking changes to public API, config keys, method signatures or expected behavior must be treated as major-version changes once the package is released.

Before the first stable release, changes may still happen, but they must be documented clearly.
