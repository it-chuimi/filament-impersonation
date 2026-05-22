# Integration Guide

This guide explains how to install, configure, and integrate `chuimi/filament-impersonation` into a Laravel + Filament application.

For architecture and design decisions see [ARCHITECTURE.md](ARCHITECTURE.md).
For security considerations see [SECURITY.md](SECURITY.md).

---

## 1. Requirements

| Dependency | Minimum version | Required |
|---|---|---|
| PHP | 8.2 | Yes |
| Laravel | 12 | Yes |
| Filament | 5 | Yes |
| spatie/laravel-activitylog | 4 | Yes |
| spatie/laravel-permission | any | Only for roles / permissions features |

`spatie/laravel-activitylog` is required unconditionally. The package records an `impersonation.started` activity before switching the authenticated user, and if that recording fails, impersonation does not start. The `activity_log` table must exist before the package is used.

`spatie/laravel-permission` is only needed if you configure `operator_roles`, `operator_permissions`, or `protected_roles`. If those arrays are all empty and you use only the `can_impersonate` callback, the permission package is not required.

---

## 2. Installation

```bash
composer require chuimi/filament-impersonation
```

### Publish the configuration (optional)

```bash
php artisan vendor:publish --tag=filament-impersonation-config
```

This publishes `config/filament-impersonation.php` to your application.

### Ensure activitylog is set up

If `spatie/laravel-activitylog` is not already installed in your application, publish and run its migrations:

```bash
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

The `activity_log` table must exist. The package does not create it.

---

## 3. Register the Filament plugin

Add the plugin to each Filament panel where impersonation should be active. The plugin registers the impersonation banner, which is shown automatically when an impersonation session is running.

```php
use Chuimi\FilamentImpersonation\Filament\ImpersonationPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(ImpersonationPlugin::make())
        // ...
    ;
}
```

The plugin must be registered in every panel where impersonated users may navigate. If an impersonated session continues in a panel without the plugin, the banner will not be shown in that panel.

---

## 4. Add the impersonation action

The package provides a reusable Filament action. Add it wherever you want operators to be able to start impersonation — typically a user resource table:

```php
use Chuimi\FilamentImpersonation\Filament\Actions\ImpersonateAction;

->actions([
    ImpersonateAction::make(),
])
```

The action is automatically hidden when:

- the package is disabled (`enabled = false`);
- an impersonation is already active;
- the target is the operator themselves;
- the target is a protected user;
- the operator is not authorized.

The action shows a form requiring a mandatory reason before starting impersonation. The minimum reason length is read from `config('filament-impersonation.reason.min_length')` at evaluation time.

The consuming application decides where the action appears. The package does not inject it into any resource automatically.

---

## 5. Authorization

### Primary: `can_impersonate` callback

When this callback is set, it is used exclusively. Roles and permissions are not evaluated.

```php
// config/filament-impersonation.php

'can_impersonate' => function (Authenticatable $operator, Authenticatable $target): bool {
    return $operator->hasRole('super-admin');
},
```

This is the recommended approach for any non-trivial authorization logic.

### Fallback: `operator_roles` and `operator_permissions`

When `can_impersonate` is null, roles and permissions are evaluated together:

```php
'operator_roles'       => ['super-admin', 'support'],
'operator_permissions' => ['impersonate-users'],
```

**Evaluation logic:**

- `operator_roles`: the operator must have **at least one** of the listed roles (`hasAnyRole`). Requires `spatie/laravel-permission`.
- `operator_permissions`: the operator must have **all** of the listed permissions (each checked individually with `can()`). Requires `spatie/laravel-permission`.
- Both conditions are required: if you set both arrays, the operator must satisfy **roles AND permissions**.
- If an array is empty, that condition is skipped.
- If a required method (`hasAnyRole`, `can`) is missing from the operator model, impersonation is denied.

Default state: both arrays are empty, and `can_impersonate` is null. In this state, any authenticated user can impersonate any non-protected user. **Set an authorization rule before deploying to production.**

### Protected users

Users that must never be impersonated regardless of operator authorization.

```php
'protected_roles' => ['admin', 'super-admin'],

'is_protected_user' => function (Authenticatable $target): bool {
    return $target->is_system_account === true;
},
```

**`protected_roles`** is evaluated first:

- If the array is non-empty and the target model does not implement `hasAnyRole()`, the target is treated as protected for safety — impersonation is denied.
- If the target has any of the listed roles, impersonation is denied.

**`is_protected_user`** callback is evaluated only if `protected_roles` did not already deny. Either mechanism is sufficient to deny.

### Self-impersonation

Always blocked. There is no configuration option to allow it.

### Nested impersonation

Always blocked. An active impersonation session must be ended before starting a new one.

---

## 6. Redirects

```php
'redirect_after_start' => null,
'redirect_after_stop'  => null,
```

Each accepts:

| Value | Behavior |
|---|---|
| `null` | Falls back to `url()->previous('/')` |
| `'/dashboard'` or `'https://...'` | Used as-is (absolute URL or path starting with `/`) |
| `'filament.pages.dashboard'` | Resolved as a named route; falls back to `'/'` if not found |
| `callable` | Called with a context array (see below) |

**Callable context array:**

```php
'redirect_after_start' => function (array $context): string {
    // $context['phase']        → 'start' or 'stop'
    // $context['operator']     → Authenticatable|null
    // $context['impersonated'] → Authenticatable|null
    // $context['payload']      → array|null (session payload)
    // $context['request']      → Illuminate\Http\Request
    return route('filament.admin.pages.dashboard');
},
```

The callable may return a `string`, a `RedirectResponse`, or `null` (falls back to previous URL). Exceptions thrown inside the callable are not caught.

---

## 7. Stop route and banner

### Default route

```
POST /impersonation/stop    →  name: impersonation.stop
```

Middleware: `['web', 'auth']`.

### Banner

When the plugin is registered, an impersonation banner is rendered at the bottom of every page while impersonation is active. The banner submits to the stop route via `POST` with a CSRF token. It cannot be disabled.

### Route configuration

```php
'routes' => [
    'enabled'    => true,
    'middleware' => ['web', 'auth'],
    'prefix'     => 'impersonation',
    'name'       => 'impersonation.',
],
```

Set `routes.enabled` to `false` if you want to provide your own stop route or button. In that case the banner will not render a button (the route will not exist), and you are responsible for triggering the stop flow.

Custom buttons that stop impersonation must submit via `POST` and include a CSRF token.

---

## 8. Manual logout during impersonation

If the impersonated user triggers a standard logout while impersonation is active, the package detects it through the `Illuminate\Auth\Events\Logout` event listener registered automatically in the service provider.

Behavior on manual logout:

- Records `impersonation.stopped_by_logout` with `logout_reason = manual_logout`.
- Clears the impersonation session payload.
- Does **not** restore the original operator (the user explicitly chose to log out).

The actual logout continues normally through Laravel's flow.

---

## 9. Model audit context trait

For models that log activity through `spatie/laravel-activitylog`, you can enrich the recorded activities with impersonation context by adding the trait:

```php
use Chuimi\FilamentImpersonation\Concerns\HasImpersonationActivityContext;
use Spatie\Activitylog\Traits\LogsActivity;

class Attendance extends Model
{
    use LogsActivity;
    use HasImpersonationActivityContext;

    // ...
}
```

When impersonation is active, every activity recorded by this model will have these additional properties:

```json
{
    "impersonation_activity_id": 42,
    "operator_user_id": 1,
    "operator_user_type": "App\\Models\\User",
    "impersonated_user_id": 7,
    "impersonated_user_type": "App\\Models\\User"
}
```

The `causer_id` and `causer_type` of the activity are set to the real operator (not the impersonated user), so audit queries correctly attribute the action.

**This trait is opt-in per model.** The package does not modify activity logs globally.

**Existing properties are preserved.** The trait merges impersonation context into the existing `properties` collection, so `attributes` and `old` keys from `LogsActivity` are not overwritten.

**No database queries are made.** The trait reads from the session payload only.

When impersonation is not active, the trait does nothing.

For SQL queries to review the historical audit trail in the `activity_log` table, see [Auditing impersonation activity](SECURITY.md#8-auditing-impersonation-activity) in the security guide.

---

## 10. `tapActivity` conflict

The trait implements the Spatie hook `tapActivity(Activity $activity, string $eventName)`. If your model already defines a `tapActivity()` method, PHP will raise a conflict error because both the trait and the class define the method.

In that case, resolve the conflict manually:

```php
class Attendance extends Model
{
    use LogsActivity;
    use HasImpersonationActivityContext {
        tapActivity as tapImpersonationActivity;
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $this->tapImpersonationActivity($activity, $eventName);

        // Your custom logic here.
    }
}
```

---

## 11. Morph maps

`causer_type` and `operator_user_type` are stored as fully-qualified class names (e.g. `App\Models\User`). If your application uses Laravel morph maps that alias class names, the stored FQCN may not resolve correctly in activity log queries.

Ensure your morph map entries match what the package stores, or configure your morph map to accept both the alias and the FQCN.

---

## 12. Multi-guard applications

```php
'guard' => null,   // auto-detect the current guard
// or
'guard' => 'web',  // force a specific guard
```

When `guard` is `null`, the package resolves the guard by checking which guard has an authenticated user at the time of the call. The resolved guard is stored in the session payload as `operator_guard`.

When stopping impersonation, the stored `operator_guard` is validated. If the value in the session is invalid or missing, the package falls back to the auto-detected guard — no exception is thrown.

For applications with multiple guards (e.g. `web` and `api`), verify that:

- impersonation is always started under the intended guard;
- the stop flow uses the same guard context;
- the banner stop route middleware matches your guard setup.

---

## 13. Operator restoration failures

If the original operator cannot be restored when stopping impersonation, the package performs a safe logout instead of raising an unhandled exception. This covers:

| Scenario | `logout_reason` |
|---|---|
| Operator deleted while impersonating | `operator_not_found` |
| `is_restorable_user` callback returns `false` | `operator_not_restorable` |
| User model class cannot be resolved | `user_model_not_resolvable` |
| `Auth::guard()->login()` throws | `restore_failed` |
| Restorable check throws unexpectedly | `restore_failed` |

In all cases:

- `impersonation.stopped_by_logout` is recorded if possible.
- The impersonation session payload is cleared.
- The guard is logged out completely.
- Session is invalidated and the token regenerated.
- Authentication middleware will redirect to the login page.

If the final log entry fails, the package still completes the safe logout. The logging failure is reported via `report()` but does not block session cleanup.

You can use the `is_restorable_user` callback to prevent restoring disabled or suspended operators:

```php
'is_restorable_user' => fn (Authenticatable $user): bool => $user->active === true,
```
