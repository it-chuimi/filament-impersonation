<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable impersonation
    |--------------------------------------------------------------------------
    */
    'enabled' => env('IMPERSONATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Auth guard
    |--------------------------------------------------------------------------
    | null: auto-detect the current guard.
    | string: force a specific guard, e.g. 'web' or 'admin'.
    */
    'guard' => null,

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    | FQCN of the Eloquent user model.
    | null: resolved from the configured auth guard provider.
    */
    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Session key
    |--------------------------------------------------------------------------
    | Key used to store the impersonation payload in the session.
    */
    'session_key' => env('IMPERSONATION_SESSION_KEY', 'filament_impersonation'),

    /*
    |--------------------------------------------------------------------------
    | Reason
    |--------------------------------------------------------------------------
    | A reason is required when starting impersonation.
    */
    'reason' => [
        'required'   => true,
        'min_length' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    | can_impersonate: callable($operator, $target): bool
    |   Used as the main authorization criterion when set.
    |   If null, operator_roles and operator_permissions are evaluated.
    |
    | operator_roles: array of role names the operator must have (requires
    |   spatie/laravel-permission). Empty array = no role requirement.
    |
    | operator_permissions: array of permission names the operator must have.
    |   Empty array = no permission requirement.
    */
    'can_impersonate' => null,

    'operator_roles' => [],

    'operator_permissions' => [],

    /*
    |--------------------------------------------------------------------------
    | Protected users
    |--------------------------------------------------------------------------
    | Users that must never be impersonated.
    |
    | protected_roles: array of role names. If the target has any of these
    |   roles, impersonation is denied. Requires hasAnyRole() on the model.
    |   If the model does not support hasAnyRole() and this array is not
    |   empty, the target is considered protected for safety.
    |
    | is_protected_user: callable($target): bool
    |   Additional or alternative protection check.
    */
    'protected_roles' => [],

    'is_protected_user' => null,

    /*
    |--------------------------------------------------------------------------
    | Restorable user callback
    |--------------------------------------------------------------------------
    | callable($user): bool
    |   Decides whether the original operator can be restored after stopping.
    |   null: operator existence is sufficient.
    |
    | Example:
    |   'is_restorable_user' => fn ($user): bool => $user->active === true,
    */
    'is_restorable_user' => null,

    /*
    |--------------------------------------------------------------------------
    | Activity log
    |--------------------------------------------------------------------------
    | Log name used when recording impersonation events via
    | spatie/laravel-activitylog.
    */
    'activity_log_name' => env('IMPERSONATION_LOG_NAME', 'impersonation'),

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    | null | string | callable
    | Resolved in future blocks via RedirectResolver.
    */
    'redirect_after_start' => null,

    'redirect_after_stop' => null,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | Registered in a future implementation block.
    | Set enabled to false if you provide your own route.
    */
    'routes' => [
        'enabled'    => true,
        'middleware' => ['web', 'auth'],
        'prefix'     => 'impersonation',
        'name'       => 'impersonation.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Banner view
    |--------------------------------------------------------------------------
    | Blade view rendered when an active impersonation session is detected.
    | Override with your own view name after publishing the views.
    */
    'banner_view' => 'filament-impersonation::banner',

];
