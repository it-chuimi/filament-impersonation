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
    | Session key used to store the impersonation state
    |--------------------------------------------------------------------------
    */
    'session_key' => env('IMPERSONATION_SESSION_KEY', 'filament_impersonation'),

    /*
    |--------------------------------------------------------------------------
    | Permission-based access (requires spatie/laravel-permission)
    |--------------------------------------------------------------------------
    | When require_permission is true the acting user must have the permission
    | named below. Set require_permission to false to skip the check entirely.
    */
    'require_permission' => env('IMPERSONATION_REQUIRE_PERMISSION', false),

    'permission' => env('IMPERSONATION_PERMISSION', 'impersonate users'),

    /*
    |--------------------------------------------------------------------------
    | Role-based access (requires spatie/laravel-permission)
    |--------------------------------------------------------------------------
    | When require_admin_role is true the acting user must have the role named
    | below. Set require_admin_role to false to skip the check entirely.
    */
    'require_admin_role' => env('IMPERSONATION_REQUIRE_ADMIN_ROLE', false),

    'admin_role' => env('IMPERSONATION_ADMIN_ROLE', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Activity log
    |--------------------------------------------------------------------------
    | Log name used when recording impersonation events via spatie/activitylog.
    */
    'activity_log_name' => env('IMPERSONATION_LOG_NAME', 'impersonation'),

    /*
    |--------------------------------------------------------------------------
    | Banner view
    |--------------------------------------------------------------------------
    | Blade view rendered when an active impersonation session is detected.
    | Override with your own view name after publishing the views.
    */
    'banner_view' => 'filament-impersonation::banner',

];
