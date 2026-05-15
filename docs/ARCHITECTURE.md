# Filament Impersonation - Architecture Notes

## Purpose

`chuimi/filament-impersonation` is a public Composer package that provides controlled user impersonation for Laravel and Filament applications.

The package is designed to be reusable across different projects and must not contain references to any private/internal domain, project-specific entities, or application-specific business logic.

This document records the agreed architecture, design decisions, implementation boundaries, and orchestration workflow for future maintainers and AI-assisted development agents.

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

Impersonation must change the authenticated Laravel user for real.

The package will use:

```php
Auth::guard($guard)->login($targetUser);
```

Before switching users, the package must store the original operator context in session.

This allows Laravel and Filament to apply permissions, policies, menus, visibility and UI behavior as the impersonated user.

The audit trail must not lose the real operator.

Actions performed during impersonation must be enriched with:

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
- restore the operator with the stored guard
- if restoration is not possible, logout safely

### Reason requirement

A reason is mandatory when starting impersonation.

Recommended configuration:

```php
'reason' => [
    'required' => true,
    'min_length' => 10,
],
```

The reason is stored only in `impersonation.started`.

It is not repeated in every later audited action.

Later actions are linked to the start event using `impersonation_activity_id`.

### No nested impersonation

Nested impersonation is forbidden.

If an impersonation is already active, `start()` must reject a new impersonation.

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

If the operator and target user have the same `getAuthIdentifier()`, `start()` must be blocked.

There is no `allow_self_impersonation` configuration.

Tests must validate that this case is blocked.

### Session payload

The package stores a structured payload in session.

The payload must include:

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

The manager API must include:

```php
public function isImpersonating(): bool;

public function payload(): ?array;
```

`payload()` returns:

- `array` if impersonation is active
- `null` if impersonation is not active

It must not throw an exception when there is no active impersonation.

### Session regeneration

The session must be regenerated when impersonation starts and when it stops.

**Start flow:**

1. validate package is enabled
2. validate there is no active impersonation
3. validate target is not self
4. validate target is not protected
5. validate authorization
6. validate reason
7. resolve guard
8. create `impersonation.started`
9. store payload in session
10. login as target user
11. regenerate session

**Stop flow:**

1. read payload
2. attempt to restore the operator
3. register finalization event where applicable
4. clean impersonation payload
5. login as operator if restorable
6. regenerate session

If the operator cannot be restored:

1. clean impersonation payload
2. logout completely
3. invalidate/regenerate session as appropriate

The order must avoid losing the payload before it is needed.

### Package enabled flag

Configuration:

```php
'enabled' => true,
```

If `config('filament-impersonation.enabled') === false`, then:

- `start()` must throw a package-specific exception, for example `PackageDisabledException`
- `canImpersonate()` must return `false`
- the future Filament Action must be hidden or unavailable

`stop()` may remain idempotent and silent if there is no active impersonation.

### Stop behavior

`stop()` is idempotent.

Recommended signature:

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

When stopping impersonation, the package must attempt to restore the original operator.

If the original operator:

- no longer exists
- cannot be loaded
- cannot be restored according to project logic

then the package must:

- logout completely on the corresponding guard
- clean all impersonation session keys
- register a finalization event if possible
- let authentication middleware redirect to login or a safe destination

The impersonated user must not remain authenticated in an ambiguous state.

### Restorable user callback

The package must not assume how an application marks users as disabled.

Configuration:

```php
'is_restorable_user' => null,
```

Rules:

- the package always checks that the operator exists
- if `is_restorable_user` is callable, it decides whether the operator may be restored
- if the callback returns `false`, logout completely
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

1. If `user_model` contains a FQCN, use it.
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
5. If the model cannot be resolved or the operator cannot be loaded, logout completely and clean session.

### Authorization

Authorization uses a configurable callback with fallback to roles/permissions.

Configuration:

```php
'can_impersonate' => null,

'operator_roles'       => [],
'operator_permissions' => [],
```

Rules:

- If `can_impersonate` is callable, it is used as the main authorization criterion.
- If no callback exists, roles and permissions are evaluated.
- Empty `operator_roles` means no role requirement.
- Empty `operator_permissions` means no permission requirement.
- If roles/permissions are required but the model does not support the needed methods, deny.

The callback can be more powerful than roles/permissions, but it must not bypass critical package rules such as:

- package disabled
- active impersonation already exists
- self impersonation
- protected target users
- safe restoration

### Protected users

Users that must never be impersonated are handled through protected roles and an optional callback.

Configuration:

```php
'protected_roles' => [],

'is_protected_user' => null,
```

Rules:

- If `protected_roles` has values, check whether the target user has any of those roles.
- If `protected_roles` is not empty and the model does not support `hasAnyRole()`, consider the user protected or deny for safety.
- If `is_protected_user` is callable, execute it.
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

This event is mandatory. If creating this activity fails, impersonation must not start.

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

If this log fails, the package must still exit impersonation safely.

### `impersonation.stopped_by_logout`

Registered when impersonation ends through logout or because the original operator cannot be safely restored.

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

Initial `logout_reason` values:

- `operator_not_found`
- `operator_not_restorable`
- `user_model_not_resolvable`
- `manual_logout`
- `restore_failed`

If this log fails, the package must still clean session and logout/restore safely.

---

## Logout handling

Manual logout during active impersonation will be detected using a listener for:

```
Illuminate\Auth\Events\Logout
```

Rules:

- if there is no active impersonation, do nothing
- if there is active impersonation, register `impersonation.stopped_by_logout`
- use `logout_reason = manual_logout`
- clean impersonation session payload
- do not restore the operator, because the user explicitly logged out

The service must avoid double registration — for example, normal `stop()` must not also trigger `stopped_by_logout`.

**This listener is outside the first implementation block.**

---

## Audited model enrichment

The package provides a manual trait for models that use `spatie/laravel-activitylog`.

Trait:

```
Chuimi\FilamentImpersonation\Concerns\HasImpersonationActivityContext
```

The consumer adds it manually to auditable models.

If a model uses both `LogsActivity` and `HasImpersonationActivityContext`, its activities are enriched with:

- `impersonation_activity_id`
- `operator_user_id`
- `operator_user_type`
- `impersonated_user_id`
- `impersonated_user_type`

If a model does not use the trait, the package does not modify its logs.

There will be no global observer and no middleware that modifies all logs.

**This trait is outside the first implementation block.**

### Activity hook

The trait will use Spatie's hook:

```php
tapActivity(Activity $activity, string $eventName): void
```

When impersonation is active, it must:

- retrieve the session payload
- set `causer_type` and `causer_id` to the original operator
- merge impersonation context into properties
- preserve existing properties such as `old` and `attributes`

If there is no active impersonation, it does nothing.

If the consumer model already defines its own `tapActivity()`, documentation must explain how to integrate both behaviors.

---

## Filament Action

The package will provide a reusable Filament Action:

```
Chuimi\FilamentImpersonation\Filament\Actions\ImpersonateAction
```

The consumer must add it manually where needed.

The package will not inject it automatically into all `UserResource` classes.

The Action must include:

- visibility based on authorization
- reason form
- minimum reason validation from config
- call to `ImpersonationManager`
- success/error notifications using translations
- redirect after successful start

**This Action is outside the first implementation block.**

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

Rules:

- If callable, execute with useful context.
- If string, use it as configured route/URL.
- If `null`, attempt to resolve Filament current panel dashboard/home.
- If not resolvable, use the current page or a safe URL.

The package must not assume a fixed route such as:

```
filament.admin.pages.dashboard
```

Redirect logic should be centralized in:

```
Chuimi\FilamentImpersonation\Support\RedirectResolver
```

---

## Exit route and controller

The normal exit flow will use a POST route and controller.

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
banner -> POST route('impersonation.stop') -> controller -> manager stop() -> redirect_after_stop
```

**Routes and controller are outside the first implementation block.**

---

## Banner and Filament plugin

The impersonation banner is a security signal.

It must be shown whenever there is an active impersonation inside a panel where the plugin is registered.

The banner is not optional and is not disableable by configuration in the first version.

The package will provide:

```
Chuimi\FilamentImpersonation\Filament\ImpersonationPlugin
```

The consumer must register it manually in each Filament panel where impersonation should be supported:

```php
->plugins([
    ImpersonationPlugin::make(),
])
```

Rules:

- the package does not automatically register the plugin in all panels
- once registered in a panel, the banner is mandatory while impersonation is active
- the banner must include a visible "Leave impersonation" button
- if a project has multiple panels where an impersonated session may continue, the plugin must be registered in all of them

**The plugin/banner automatic integration is outside the first implementation block.**

---

## Internal structure

Planned structure:

```
src/
├─ Concerns/
│  └─ HasImpersonationActivityContext.php
├─ Contracts/
├─ Exceptions/
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

- `ImpersonationManager`: orchestrates `start`, `stop`, `stopForLogout`, `payload`, `isImpersonating`
- `ImpersonationActivity`: records events in Spatie Activitylog
- `ImpersonationAuthorization`: handles callbacks, roles, permissions and protected users
- `ImpersonationLogoutReason`: defines logout reasons, preferably as a PHP 8.2 enum
- `RedirectResolver`: resolves start/stop redirects
- `ImpersonateAction`: reusable Filament Action
- `ImpersonationPlugin`: registers mandatory banner in Filament
- `StopImpersonationController`: handles normal exit route
- `HandleImpersonationLogout`: listens to Logout events
- `HasImpersonationActivityContext`: enriches auditable model activity logs

---

## Exceptions

The package uses custom exceptions instead of generic runtime exceptions.

Initial exceptions:

- `PackageDisabledException`
- `ImpersonationAlreadyActiveException`
- `CannotImpersonateSelfException`
- `ProtectedUserCannotBeImpersonatedException`
- `UnauthorizedImpersonationException`
- `ImpersonationStartFailedException`
- `OperatorNotRestorableException`
- `UserModelNotResolvableException`

Actions/controllers may translate these exceptions into user-facing notifications.

---

## Public API

No Laravel Facade will be included in the first version.

Use dependency injection or container resolution:

```php
app(ImpersonationManager::class)
```

A Facade may be added later without breaking compatibility.

---

## First implementation block

The first implementation block focuses only on the core independent of Filament.

**Included:**

- `config/filament-impersonation.php`
- `src/ImpersonationManager.php`
- `src/Support/ImpersonationActivity.php`
- `src/Support/ImpersonationAuthorization.php`
- `src/Support/ImpersonationLogoutReason.php`
- `src/Exceptions/*`
- `tests/Feature/ImpersonationManagerTest.php`

**Must validate:**

- `Auth::guard($guard)->login($target)`
- full session payload with operator/impersonated type/id/guard
- blocking active impersonation
- self impersonation always forbidden
- authorization by callback or roles/permissions
- `protected_roles` and `is_protected_user`
- mandatory `impersonation.started`
- normal `impersonation.stopped`
- forced `impersonation.stopped_by_logout`
- session regeneration on start/stop
- complete logout when the original operator cannot be restored

**Out of scope for the first implementation block:**

- `ImpersonateAction`
- `ImpersonationPlugin`
- Banner automatic registration
- `StopImpersonationController`
- Routes
- Logout listener
- `HasImpersonationActivityContext` trait

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

The package will be published using semantic versioning.

Breaking changes to public API, config keys, method signatures or expected behavior must be treated as major-version changes once the package is released.

Before the first stable release, changes may still happen, but they should be documented clearly.
