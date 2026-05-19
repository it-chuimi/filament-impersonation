# Filament Impersonation - Architecture Map

## Purpose

This document provides a high-level architecture map for the `chuimi/filament-impersonation` package.

It is intended to give architects, maintainers and implementation agents a quick overview of the package boundaries, runtime components and integration points.

For detailed design decisions, see:

```text
docs/ARCHITECTURE.md
```

---

## High-level goal

`chuimi/filament-impersonation` provides controlled and audited user impersonation for Laravel + Filament applications.

The package allows an authorized operator to temporarily authenticate as another user while preserving auditability of the real operator.

---

## Runtime architecture

```mermaid
flowchart LR
    Operator[Operator user] --> App[Consumer Laravel application]

    App --> Filament[Filament panel]
    Filament --> Action[ImpersonateAction]
    Filament --> Plugin[ImpersonationPlugin]
    Plugin --> Banner[Impersonation banner]

    Action --> Manager[ImpersonationManager]
    Banner --> StopRoute[POST impersonation.stop]
    StopRoute --> Manager

    Manager --> Auth[Laravel Auth / Guards]
    Manager --> Session[Laravel Session]
    Manager --> Authorization[ImpersonationAuthorization]
    Manager --> Activity[ImpersonationActivity]
    Manager --> Redirects[RedirectResolver]

    Authorization --> Permission[Spatie Permission optional]
    Activity --> Activitylog[Spatie Activitylog required]

    Auth --> Target[Impersonated user session]
```

---

## Component overview

### Consumer Laravel application

The host application where the package is installed.

Responsibilities:

- register the package through Laravel autodiscovery;
- publish and customize configuration if needed;
- register the Filament plugin in the desired panels;
- add the reusable impersonation action where appropriate;
- decide which models should receive impersonation audit context.

---

### Filament panel

The administrative UI where impersonation is exposed.

The package does not automatically inject actions into any resource.

The consuming application decides where to place:

```php
ImpersonateAction::make()
```

The package plugin is registered manually per panel:

```php
ImpersonationPlugin::make()
```

Once registered, the impersonation banner is rendered at `PanelsRenderHook::BODY_START` and shown only while impersonation is active.

---

### ImpersonateAction

Reusable Filament Action provided by the package.

Responsibilities:

- check whether the current operator can impersonate the target user;
- show the required reason form;
- validate the reason using package configuration;
- call `ImpersonationManager::start()`;
- show translated success/error notifications;
- redirect after successful start.

This action is added manually by the consumer.

---

### ImpersonationPlugin

Filament plugin provided by the package.

Responsibilities:

- register the impersonation banner via `PanelsRenderHook::BODY_START`;
- the banner shows only while impersonation is active;
- provide visible access to "Leave impersonation".

The plugin is registered manually in each Filament panel where impersonation can be used.

---

### Impersonation banner

Security signal shown during an active impersonation.

Responsibilities:

- clearly indicate that impersonation is active;
- show the impersonated user context;
- provide a visible "Leave impersonation" action;
- submit a CSRF-protected POST form to the package stop route.

---

### Stop route and controller

The normal exit flow uses a POST route.

Default route:

```text
POST /impersonation/stop
```

Default name:

```text
impersonation.stop
```

Default middleware:

```php
['web', 'auth']
```

Flow:

```text
Banner -> POST route('impersonation.stop') -> StopImpersonationController -> ImpersonationManager::stop()
```

---

### ImpersonationManager

Central runtime coordinator of the package.

Responsibilities:

- start impersonation;
- stop impersonation;
- stop impersonation during logout;
- expose `isImpersonating()`;
- expose `payload()`;
- expose `canImpersonate()`;
- coordinate session, authentication, authorization and audit logging.

Main methods:

```php
start(...)
stop(): bool
stopForLogout(...)
isImpersonating(): bool
payload(): ?array
canImpersonate(): bool
```

---

### Laravel Auth / Guards

The package performs a real authentication switch.

During impersonation start:

```php
Auth::guard($guard)->login($targetUser);
```

During normal stop:

```php
Auth::guard($guard)->login($operator);
```

The guard is configurable.

```php
'guard' => null,
```

Meaning:

- `null`: use the current guard;
- string: force a specific guard.

The stored `operator_guard` value is validated against `config('auth.guards')` before use on stop.

---

### Laravel Session

The package stores impersonation context in session.

The payload contains:

```text
operator_user_id
operator_user_type
operator_guard
impersonated_user_id
impersonated_user_type
impersonated_guard
impersonation_activity_id
started_at
```

The session is regenerated when impersonation starts and when it stops normally.
The session is invalidated on forced stop.

---

### ImpersonationAuthorization

Authorization component.

Responsibilities:

- evaluate the `can_impersonate` callable if set (used exclusively, short-circuits roles/permissions);
- evaluate `operator_roles` and `operator_permissions` when no callback is set;
- check protected users via `protected_roles` and `is_protected_user` callback;
- keep `spatie/laravel-permission` optional.

The manager enforces package safety rules (enabled check, nested block, self block) independently of this component.

Relevant configuration:

```php
'can_impersonate' => null,

'operator_roles' => [],
'operator_permissions' => [],

'protected_roles' => [],
'is_protected_user' => null,
```

---

### ImpersonationActivity

Activity logging component.

Uses:

```text
spatie/laravel-activitylog
```

Activity events:

```text
impersonation.started
impersonation.stopped
impersonation.stopped_by_logout
```

The `impersonation.started` event is mandatory. If it cannot be created, `ImpersonationStartFailedException` is thrown and impersonation does not start.

`impersonation.stopped` is registered only after a successful operator login. If login fails, `impersonation.stopped_by_logout` with `restore_failed` is registered instead.

Both `impersonation.stopped` and `impersonation.stopped_by_logout` are non-blocking: failures are reported via `report()` but do not block session cleanup.

---

### RedirectResolver

Component responsible for resolving post-action redirects.

Configuration:

```php
'redirect_after_start' => null,
'redirect_after_stop' => null,
```

Each value may be:

- `null` → falls back to `url()->previous('/')`;
- string → used as absolute URL, path, or named route;
- callable → called with a context array; callable exceptions are not caught.

---

### HasImpersonationActivityContext

Manual opt-in trait for models using `spatie/laravel-activitylog`.

The consumer adds it only to auditable models that should receive impersonation context.

Responsibilities:

- enrich activity logs during impersonation via `tapActivity()`;
- set the real operator as causer (`causer_type` / `causer_id`);
- preserve existing activity properties;
- add impersonation context to properties.

Added context:

```text
impersonation_activity_id
operator_user_id
operator_user_type
impersonated_user_id
impersonated_user_type
```

This trait is applied manually by the consuming application. If the consumer model also defines its own `tapActivity()`, both implementations must be merged manually.

---

## Start flow

```mermaid
sequenceDiagram
    actor Operator
    participant Filament
    participant Action as ImpersonateAction
    participant Manager as ImpersonationManager
    participant Authorization as ImpersonationAuthorization
    participant Activity as ImpersonationActivity
    participant Auth as Laravel Auth
    participant Session as Laravel Session

    Operator->>Filament: Click impersonate
    Filament->>Action: Execute action
    Action->>Action: Validate required reason
    Action->>Manager: start(targetUser, reason)

    Manager->>Manager: Check package enabled
    Manager->>Manager: Resolve guard
    Manager->>Manager: Resolve operator from guard
    Manager->>Manager: Check no active impersonation
    Manager->>Manager: Block self impersonation
    Manager->>Authorization: Check protected user + authorization
    Authorization-->>Manager: Authorized

    Manager->>Activity: Create impersonation.started (mandatory)
    Activity-->>Manager: Activity ID

    Manager->>Session: Store impersonation payload
    Manager->>Auth: Login as target user
    Manager->>Session: Regenerate session

    Manager-->>Action: Started
    Action-->>Filament: Redirect after start
```

---

## Normal stop flow

```mermaid
sequenceDiagram
    actor Operator
    participant Banner
    participant Controller as StopImpersonationController
    participant Manager as ImpersonationManager
    participant Activity as ImpersonationActivity
    participant Auth as Laravel Auth
    participant Session as Laravel Session

    Operator->>Banner: Click Leave impersonation
    Banner->>Controller: POST impersonation.stop
    Controller->>Manager: stop()

    Manager->>Session: Read impersonation payload
    Manager->>Manager: Resolve and validate guard
    Manager->>Manager: Resolve original operator
    Note over Manager: If operator unresolvable → forced stop

    Manager->>Auth: Login as original operator
    Note over Manager: If login throws → forced stop (restore_failed)

    Manager->>Activity: Record impersonation.stopped (non-blocking)
    Manager->>Session: Clear impersonation payload
    Manager->>Session: Regenerate session

    Manager-->>Controller: true
    Controller-->>Operator: Redirect after stop
```

---

## Forced stop flows

### Forced stop from normal stop (within stop())

Triggered inside `ImpersonationManager::stop()` when the operator cannot be restored via the normal path. Two distinct trigger conditions lead to the same safe logout sequence.

```mermaid
sequenceDiagram
    actor Operator
    participant Controller as StopImpersonationController
    participant Manager as ImpersonationManager
    participant Activity as ImpersonationActivity
    participant Auth as Laravel Auth
    participant Session as Laravel Session

    Operator->>Controller: POST impersonation.stop
    Controller->>Manager: stop()

    Manager->>Session: Read impersonation payload
    Manager->>Manager: Resolve and validate guard
    Manager->>Manager: Resolve original operator

    alt Operator not found / model not resolvable / not restorable
        Manager->>Activity: Record impersonation.stopped_by_logout (non-blocking)
        Note over Activity: reason: operator_not_found | user_model_not_resolvable | operator_not_restorable
    else Auth::guard(guard)->login(operator) throws
        Manager->>Auth: login(operator) — throws
        Manager->>Activity: Record impersonation.stopped_by_logout (non-blocking)
        Note over Activity: reason: restore_failed
    end

    Manager->>Session: Clear impersonation payload
    Manager->>Auth: logout()
    Manager->>Session: invalidate() + regenerateToken()
    Note over Manager: impersonation.stopped is never registered in forced stop

    Manager-->>Controller: true
```

---

### Manual logout flow (via HandleImpersonationLogout)

Triggered when `Illuminate\Auth\Events\Logout` fires during an active impersonation. The listener calls `stopForLogout()` directly — `stop()` is not involved.

```mermaid
sequenceDiagram
    actor User
    participant Laravel as Laravel Logout Event
    participant Listener as HandleImpersonationLogout
    participant Manager as ImpersonationManager
    participant Activity as ImpersonationActivity
    participant Session as Laravel Session

    User->>Laravel: Logout during impersonation
    Laravel->>Listener: Illuminate\Auth\Events\Logout
    Listener->>Manager: stopForLogout(manual_logout)

    Manager->>Session: Read impersonation payload
    Manager->>Activity: Record impersonation.stopped_by_logout (non-blocking)
    Note over Activity: reason: manual_logout
    Manager->>Session: Clear impersonation payload

    Note over Manager: Operator is not restored — user chose to log out
    Note over Manager: Laravel logout flow continues after listener returns
```

---

## Audit model

```mermaid
flowchart TD
    Started[impersonation.started] --> Payload[Session payload]
    Payload --> Actions[Audited model actions]
    Actions --> Enriched[Activity logs enriched with impersonation context]
    Payload --> Stopped[impersonation.stopped]
    Payload --> Logout[impersonation.stopped_by_logout]

    Enriched --> Operator[operator_user_id / operator_user_type]
    Enriched --> Target[impersonated_user_id / impersonated_user_type]
    Enriched --> Link[impersonation_activity_id]
```

---

## Package structure

```text
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

---

## Architectural boundaries

The package owns:

- impersonation flow;
- impersonation session payload;
- impersonation audit events;
- reusable Filament integration points;
- optional permission integration;
- safe start/stop behavior.

The consuming application owns:

- where the impersonation action appears;
- which users may impersonate through config/callbacks;
- which users are protected;
- which panels register the plugin;
- which models use the audit context trait;
- application-specific authorization or user-restoration rules.

---

## Summary

At a high level, the package separates responsibilities as follows:

```text
Filament UI
  -> starts/stops impersonation

ImpersonationManager
  -> orchestrates runtime flow

Authorization
  -> decides whether impersonation is allowed

Activitylog
  -> records audit trail

Session
  -> stores impersonation context

Laravel Auth
  -> performs the real user switch

Consumer application
  -> decides where and how to integrate the package
```

This separation keeps the package reusable, auditable and compatible with different Laravel + Filament applications.
