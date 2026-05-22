# Security Guide

This document describes the security model, audit behavior, known design trade-offs, and integration responsibilities for `chuimi/filament-impersonation`.

For installation and configuration see [INTEGRATION.md](INTEGRATION.md).
For architecture decisions see [ARCHITECTURE.md](ARCHITECTURE.md).

---

## 1. Security model

Impersonation is a sensitive operation that grants an operator full access to another user's session. The package is designed around these security principles:

- **Mandatory reason.** Impersonation cannot start without a documented reason. The default minimum length is 10 characters. This is enforced both in the Filament action form and in `ImpersonationManager::start()`.
- **Mandatory audit on start.** The `impersonation.started` activity is created before the user switch occurs. If recording that activity fails for any reason, impersonation does not start and no session is written. There is no way to start an unaudited impersonation session.
- **Real authentication switch.** The package calls `Auth::guard($guard)->login($target)` — it does not simulate permissions or fake the user context. Every Laravel middleware, policy, and Filament authorization check runs against the impersonated user for the duration of the session.
- **No nested impersonation.** Starting an impersonation while one is already active is always blocked. The audit chain (`operator_user_id → impersonated_user_id → impersonation_activity_id`) must remain unambiguous.
- **No self-impersonation.** Blocked unconditionally, independent of configuration.

---

## 2. Session and authentication

The impersonation context is stored in the server-side Laravel session under the key `filament_impersonation` (configurable via `IMPERSONATION_SESSION_KEY`). The payload is:

```text
operator_user_id         → original operator primary key
operator_user_type       → operator model FQCN
operator_guard           → guard used when impersonation started

impersonated_user_id     → impersonated user primary key
impersonated_user_type   → impersonated user model FQCN
impersonated_guard       → guard used (same as operator_guard)

impersonation_activity_id  → ID of the impersonation.started activity
started_at                 → ISO 8601 timestamp
```

**Session regeneration:**

- The session is regenerated when impersonation starts (after login as target).
- The session is regenerated when impersonation stops normally (after login as operator).
- The session is invalidated and the CSRF token regenerated on forced logout.

**Guard value validation:**

When stopping impersonation, the `operator_guard` value read from the session is validated before use. If the value is missing, empty, or does not match a known guard in `config('auth.guards')`, the package falls back to auto-detecting the current guard. This prevents `Auth::guard($untrustedValue)` from throwing `InvalidArgumentException` with a tampered session.

---

## 3. Audit events

The package uses `spatie/laravel-activitylog`. All events are logged under the log name configured in `activity_log_name` (default: `impersonation`).

### `impersonation.started`

Recorded before the user switch. **Mandatory** — start is aborted if this fails.

Properties recorded:

```text
operator_user_id, operator_user_type, operator_guard
impersonated_user_id, impersonated_user_type, impersonated_guard
reason
started_at
ip_address
user_agent
```

The `reason` field is recorded only here and is not repeated in subsequent model activity logs. Later actions are linked to this event via `impersonation_activity_id`.

### `impersonation.stopped`

Recorded when impersonation ends normally and the original operator is restored. If this log entry fails, the safe exit still completes — the failure is reported via `report()` but does not block session cleanup.

Properties recorded:

```text
operator_user_id, operator_user_type, operator_guard
impersonated_user_id, impersonated_user_type, impersonated_guard
impersonation_activity_id
started_at, stopped_at, duration_seconds
ip_address, user_agent
```

### `impersonation.stopped_by_logout`

Recorded when impersonation ends through a manual logout or because the original operator cannot be safely restored.

Properties recorded: same as `impersonation.stopped`, plus:

```text
logout_reason  → one of the values below
```

| `logout_reason` | Meaning |
|---|---|
| `manual_logout` | User triggered logout during impersonation |
| `operator_not_found` | Original operator no longer exists in the database |
| `operator_not_restorable` | `is_restorable_user` callback returned `false` |
| `user_model_not_resolvable` | User model class could not be resolved |
| `restore_failed` | `Auth::guard()->login()` threw or restorable check threw |

If this log entry fails, the safe exit still completes.

---

## 4. Normal stop vs. manual logout

### Normal stop

Triggered by the operator clicking "Leave impersonation" in the banner (or any call to the stop route).

1. Reads the session payload.
2. Resolves and validates the original operator. If the operator cannot be found, the model class cannot be resolved, or the `is_restorable_user` check fails, the flow diverts to forced stop instead (see below).
3. Attempts `Auth::guard($guard)->login($operator)`. If this call throws, the flow falls back to safe logout with `logout_reason = restore_failed` — `impersonation.stopped` is **not** logged in that case.
4. Only if login succeeds: logs `impersonation.stopped` (non-blocking — a failure here is reported via `report()` but does not block the remaining cleanup).
5. Clears the impersonation session key.
6. Regenerates the session.

### Manual logout

Triggered by the impersonated user (or any code) calling `Auth::logout()` or equivalent during an active impersonation.

The `Illuminate\Auth\Events\Logout` event is intercepted by `HandleImpersonationLogout`.

1. Logs `impersonation.stopped_by_logout` with `logout_reason = manual_logout`.
2. Clears the impersonation session key.
3. Does **not** restore the original operator — the user explicitly chose to log out.

The actual Laravel logout flow continues after the listener returns.

### Forced stop (operator not restorable)

Triggered internally when the stop flow cannot restore the operator safely.

1. Logs `impersonation.stopped_by_logout` with the applicable reason.
2. Clears the impersonation session key.
3. Calls `Auth::guard($guard)->logout()`.
4. Calls `session()->invalidate()` and `session()->regenerateToken()`.

In all three cases, after completion `ImpersonationManager::isImpersonating()` returns `false` and the session key is absent.

---

## 5. Known risks and explicit design decisions

### Session payload is server-side but read defensively

The impersonation payload is stored in the server-side session. A tampered or corrupted payload (e.g. from session store manipulation or test code seeding) is handled defensively:

- `payload()` returns `null` if the session value is not a non-empty array.
- `operator_guard` is validated against `config('auth.guards')` before use.
- An unresolvable user model produces a safe logout rather than an exception.

### `stop()` is idempotent

`stop()` returns `false` if no impersonation is active and takes no action. This handles double-click, stale tab, expired session, and manual route calls without side effects. There are no distributed locks; concurrent stop calls on the same session are handled by PHP's session locking at the server level.

### Final log failure does not block safe exit

If `impersonation.stopped` or `impersonation.stopped_by_logout` cannot be written (e.g. database unavailable), the session cleanup and user switch still complete. The logging failure is forwarded to Laravel's exception handler via `report()`. This decision favors safe session termination over log completeness.

### Start log failure **does** block start

The reverse applies at start: if `impersonation.started` cannot be written, `ImpersonationStartFailedException` is thrown, no session payload is written, and the user switch does not happen. An unaudited impersonation session is not permitted.

### `can_impersonate` callback is not subject to package safety rules

The `can_impersonate` callback decides authorization only. The following checks are enforced by the manager regardless of what the callback returns:

- package disabled
- active impersonation already exists
- self-impersonation
- protected target users
- mandatory reason validation
- mandatory start activity

The callback cannot bypass these rules.

### Default configuration is unrestricted

With `can_impersonate: null`, `operator_roles: []`, and `operator_permissions: []`, any authenticated user can impersonate any non-protected user. This is safe for development but **must be restricted before production deployment** by setting at least one authorization mechanism.

---

## 6. CSRF

The stop route uses `POST` and is protected by Laravel's CSRF middleware (included in the default `['web', 'auth']` middleware stack).

The impersonation banner renders the form with `@csrf` automatically.

If you disable the default route (`routes.enabled = false`) and implement your own stop button, that button must:

- use `POST` (not `GET`);
- include a CSRF token (`@csrf` in Blade, or the `X-CSRF-TOKEN` header for XHR).

---

## 7. Permissions model summary

| Mechanism | Requirement |
|---|---|
| `can_impersonate` callback | Used exclusively when set; receives `$operator` and `$target` |
| `operator_roles` | Operator needs **any one** of the listed roles (`hasAnyRole`); requires spatie/laravel-permission |
| `operator_permissions` | Operator needs **all** of the listed permissions; requires spatie/laravel-permission |
| Roles + permissions combined | Both must pass (AND) |
| `protected_roles` | Target with any listed role is always denied; model without `hasAnyRole()` is denied for safety |
| `is_protected_user` callback | Evaluated if `protected_roles` did not already deny |
| Self-impersonation | Always denied, no config option |
| Nested impersonation | Always denied, no config option |

---

## 8. Auditing impersonation activity

The package does not create a dedicated impersonation audit table. Audit data lives in two places:

| What | Where |
|---|---|
| Active impersonation session | Laravel session store (e.g. database, Redis) under the configured session key |
| Historical audit trail | `activity_log` table, via `spatie/laravel-activitylog` |

To query the historical audit, filter `activity_log` by:

```sql
log_name = 'impersonation'
```

The `log_name` value is configurable via `activity_log_name` in `config/filament-impersonation.php` (default: `impersonation`).

There is no dedicated UI for reviewing impersonation audit data. Administrators access it directly through the database or through any query tool connected to the application database.

### Events and their meaning

| `description` | When it is recorded | Blocking |
|---|---|---|
| `impersonation.started` | Before the user switch. Contains the mandatory reason. | **Yes** — start is aborted if this fails |
| `impersonation.stopped` | When impersonation ends normally and the operator is restored | No — failure is reported via `report()` but session cleanup still completes |
| `impersonation.stopped_by_logout` | Manual logout, operator not restorable, or any forced exit | No — same as above |

The `reason` for the impersonation is stored only in `impersonation.started`. Subsequent events reference the start event through `properties->>'impersonation_activity_id'`.

### `logout_reason` values (only in `impersonation.stopped_by_logout`)

| Value | Meaning |
|---|---|
| `manual_logout` | User explicitly triggered logout during impersonation. Operator is **not** restored. |
| `operator_not_found` | Operator no longer exists in the database |
| `operator_not_restorable` | `is_restorable_user` callback returned `false` |
| `user_model_not_resolvable` | User model class could not be resolved |
| `restore_failed` | `Auth::guard()->login()` threw or the restorable check threw |

### Manual logout and the audit record

When the impersonated user triggers a standard Laravel logout (`Auth::logout()`), the package:

1. Intercepts the `Illuminate\Auth\Events\Logout` event.
2. Records `impersonation.stopped_by_logout` with `logout_reason = manual_logout`.
3. Does **not** restore the original operator — the user explicitly chose to log out.

After a manual logout the operator must authenticate again via the login page.

### Window or tab close (no explicit logout)

Closing the browser window or tab does **not** trigger a Laravel logout request. The impersonation session remains in the session store until:

- the session expires naturally (controlled by `SESSION_LIFETIME`); or
- the user returns and triggers a normal stop or logout.

If the session expires without an explicit logout or stop, **no `impersonation.stopped_by_logout` event is generated**. The `activity_log` will contain an `impersonation.started` entry with no matching stop event for that session.

Administrators should account for this gap when auditing: an unmatched `impersonation.started` with an old `created_at` likely corresponds to a session that expired silently.

### PostgreSQL audit queries

The following queries assume PostgreSQL with `spatie/laravel-activitylog`. Adjust the cast `::bigint` to `::integer` if your `activity_log.id` column uses `int` instead of `bigint`.

**Recent impersonation events (all types)**

```sql
SELECT
    id,
    description,
    properties->>'operator_user_id'          AS operator_id,
    properties->>'impersonated_user_id'      AS impersonated_id,
    properties->>'reason'                    AS reason,
    properties->>'started_at'               AS started_at,
    properties->>'stopped_at'               AS stopped_at,
    properties->>'duration_seconds'         AS duration_seconds,
    properties->>'logout_reason'            AS logout_reason,
    properties->>'ip_address'               AS ip_address,
    created_at
FROM activity_log
WHERE log_name = 'impersonation'
ORDER BY created_at DESC
LIMIT 50;
```

**Only `impersonation.started` (includes mandatory reason)**

```sql
SELECT
    id,
    properties->>'operator_user_id'          AS operator_id,
    properties->>'impersonated_user_id'      AS impersonated_id,
    properties->>'reason'                    AS reason,
    properties->>'started_at'               AS started_at,
    properties->>'ip_address'               AS ip_address,
    created_at
FROM activity_log
WHERE log_name = 'impersonation'
  AND description = 'impersonation.started'
ORDER BY created_at DESC
LIMIT 50;
```

**Only `impersonation.stopped` (normal exits)**

```sql
SELECT
    id,
    properties->>'operator_user_id'                 AS operator_id,
    properties->>'impersonated_user_id'             AS impersonated_id,
    properties->>'impersonation_activity_id'        AS start_event_id,
    properties->>'started_at'                       AS started_at,
    properties->>'stopped_at'                       AS stopped_at,
    (properties->>'duration_seconds')::integer      AS duration_seconds,
    properties->>'ip_address'                       AS ip_address,
    created_at
FROM activity_log
WHERE log_name = 'impersonation'
  AND description = 'impersonation.stopped'
ORDER BY created_at DESC
LIMIT 50;
```

**Only `impersonation.stopped_by_logout` (forced exits)**

```sql
SELECT
    id,
    properties->>'operator_user_id'                 AS operator_id,
    properties->>'impersonated_user_id'             AS impersonated_id,
    properties->>'impersonation_activity_id'        AS start_event_id,
    properties->>'logout_reason'                    AS logout_reason,
    properties->>'started_at'                       AS started_at,
    properties->>'stopped_at'                       AS stopped_at,
    (properties->>'duration_seconds')::integer      AS duration_seconds,
    properties->>'ip_address'                       AS ip_address,
    created_at
FROM activity_log
WHERE log_name = 'impersonation'
  AND description = 'impersonation.stopped_by_logout'
ORDER BY created_at DESC
LIMIT 50;
```

**Join start and end events (full session view)**

Links each `impersonation.started` with its corresponding stop event via `impersonation_activity_id`:

```sql
SELECT
    s.id                                                AS start_event_id,
    s.properties->>'operator_user_id'                  AS operator_id,
    s.properties->>'impersonated_user_id'              AS impersonated_id,
    s.properties->>'reason'                            AS reason,
    s.properties->>'started_at'                        AS started_at,
    s.properties->>'ip_address'                        AS started_ip,
    e.description                                      AS end_event,
    e.properties->>'stopped_at'                        AS stopped_at,
    (e.properties->>'duration_seconds')::integer       AS duration_seconds,
    e.properties->>'logout_reason'                     AS logout_reason
FROM activity_log s
LEFT JOIN activity_log e
       ON e.log_name    = 'impersonation'
      AND e.description IN ('impersonation.stopped', 'impersonation.stopped_by_logout')
      AND (e.properties->>'impersonation_activity_id')::bigint = s.id
WHERE s.log_name    = 'impersonation'
  AND s.description = 'impersonation.started'
ORDER BY s.created_at DESC
LIMIT 50;
```

A `NULL` value in `end_event` indicates a session with no recorded stop — either currently active, or expired without an explicit logout (see [Window or tab close](#window-or-tab-close-no-explicit-logout) above).

---

## 9. CI and test coverage

The package is tested against PHP 8.2, 8.3, and 8.4 on every push to `main` and on every pull request using GitHub Actions.

Tests use [Pest](https://pestphp.com/) with PHPUnit 11 and Orchestra Testbench 10 (SQLite in-memory).

Test coverage includes:

- normal start and stop flow
- session payload contents and cleanup
- authorization via callback, roles, and permissions
- protected users and roles
- self-impersonation block
- nested impersonation block
- mandatory start activity — abort if fails
- operator restoration failure scenarios (all logout reasons)
- manual logout interception
- `HasImpersonationActivityContext` trait enrichment and causer override
- incomplete session payload handling in the trait
- `operator_guard` validation (invalid, missing, empty string)
- idempotent stop
- session regeneration
