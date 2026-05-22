<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\Filament\ImpersonationPlugin;
use Chuimi\FilamentImpersonation\Tests\Models\User;
use Filament\Panel;
use Filament\View\PanelsRenderHook;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function bannerActivePayload(): array
{
    return [
        'operator_user_id'          => 1,
        'operator_user_type'        => 'App\Models\User',
        'operator_guard'            => 'web',
        'impersonated_user_id'      => 2,
        'impersonated_user_type'    => 'App\Models\User',
        'impersonated_guard'        => 'web',
        'impersonation_activity_id' => 1,
        'started_at'                => now()->toISOString(),
    ];
}

function getBannerPluginClosure(): Closure
{
    $panel = new Panel();
    ImpersonationPlugin::make()->register($panel);

    $hooks = (new ReflectionProperty(Panel::class, 'renderHooks'))->getValue($panel);

    return $hooks[PanelsRenderHook::BODY_START][''][0];
}

function bannerPayloadForUsers(mixed $operatorId, mixed $targetId): array
{
    return [
        'operator_user_id'          => $operatorId,
        'operator_user_type'        => User::class,
        'operator_guard'            => 'web',
        'impersonated_user_id'      => $targetId,
        'impersonated_user_type'    => User::class,
        'impersonated_guard'        => 'web',
        'impersonation_activity_id' => 1,
        'started_at'                => now()->toISOString(),
    ];
}

// ---------------------------------------------------------------------------
// 1. View exists and renders without error
// ---------------------------------------------------------------------------

it('banner view exists and can be rendered without errors', function () {
    $html = view('filament-impersonation::banner')->render();
    expect($html)->toBeString();
});

// ---------------------------------------------------------------------------
// 2. Without impersonation active → nothing visible
// ---------------------------------------------------------------------------

it('renders nothing visible when no impersonation session is active', function () {
    $html = view('filament-impersonation::banner')->render();
    expect($html)->not->toContain('role="alert"');
});

// ---------------------------------------------------------------------------
// 3. With impersonation active → alert banner with names shown
// ---------------------------------------------------------------------------

it('shows the alert banner with names when impersonation is active', function () {
    session()->put(config('filament-impersonation.session_key'), bannerActivePayload());

    $html = view('filament-impersonation::banner', [
        'impersonatorName' => 'Alice',
        'impersonatedName' => 'Bob',
    ])->render();

    expect($html)->toContain('role="alert"')
        ->and($html)->toContain('Alice')
        ->and($html)->toContain('Bob');
});

// ---------------------------------------------------------------------------
// 4. With impersonation active + route exists → POST form to stop route
// ---------------------------------------------------------------------------

it('renders a POST form targeting the stop route when impersonation is active', function () {
    session()->put(config('filament-impersonation.session_key'), bannerActivePayload());

    $html = view('filament-impersonation::banner')->render();

    expect($html)->toContain('method="POST"')
        ->and($html)->toContain(route('impersonation.stop'));
});

// ---------------------------------------------------------------------------
// 5. CSRF token input is included in the form
// ---------------------------------------------------------------------------

it('includes a CSRF token hidden input in the stop form', function () {
    session()->put(config('filament-impersonation.session_key'), bannerActivePayload());

    $html = view('filament-impersonation::banner')->render();

    expect($html)->toContain('name="_token"');
});

// ---------------------------------------------------------------------------
// 6. Leave impersonation button uses the translated text
// ---------------------------------------------------------------------------

it('uses the leave_impersonation translation string for the button text', function () {
    session()->put(config('filament-impersonation.session_key'), bannerActivePayload());

    $html = view('filament-impersonation::banner')->render();

    expect($html)->toContain(__('filament-impersonation::messages.leave_impersonation'));
});

// ---------------------------------------------------------------------------
// 7. Non-existent route name in config → banner renders without error, no form
// ---------------------------------------------------------------------------

it('renders without error and omits the form when the configured route name does not exist', function () {
    session()->put(config('filament-impersonation.session_key'), bannerActivePayload());
    config(['filament-impersonation.routes.name' => 'nonexistent.']);

    $html = view('filament-impersonation::banner')->render();

    expect($html)->toContain('role="alert"')
        ->and($html)->not->toContain('method="POST"');
});

// ---------------------------------------------------------------------------
// 8. Plugin resolves real user names from session payload
// ---------------------------------------------------------------------------

it('plugin resolves real user names from session payload into the banner', function () {
    $operator = User::create(['name' => 'Admin',      'email' => 'admin@example.com']);
    $target   = User::create(['name' => 'Responsable','email' => 'responsable@example.com']);

    session()->put(config('filament-impersonation.session_key'), bannerPayloadForUsers($operator->id, $target->id));

    $html = getBannerPluginClosure()()->render();

    expect($html)
        ->toContain('role="alert"')
        ->toContain('Admin')
        ->toContain('Responsable');
});

// ---------------------------------------------------------------------------
// 9. Plugin uses unknown when operator does not exist in db
// ---------------------------------------------------------------------------

it('plugin uses unknown translation when operator user id does not exist in db', function () {
    $target = User::create(['name' => 'Responsable', 'email' => 'responsable@example.com']);

    session()->put(config('filament-impersonation.session_key'), bannerPayloadForUsers(9999, $target->id));

    $html = getBannerPluginClosure()()->render();

    expect($html)->toContain(__('filament-impersonation::messages.unknown'));
});

// ---------------------------------------------------------------------------
// 10. Plugin uses unknown when impersonated does not exist in db
// ---------------------------------------------------------------------------

it('plugin uses unknown translation when impersonated user id does not exist in db', function () {
    $operator = User::create(['name' => 'Admin', 'email' => 'admin@example.com']);

    session()->put(config('filament-impersonation.session_key'), bannerPayloadForUsers($operator->id, 9999));

    $html = getBannerPluginClosure()()->render();

    expect($html)->toContain(__('filament-impersonation::messages.unknown'));
});

// ---------------------------------------------------------------------------
// 11. Plugin uses unknown for both when user type is an invalid class
// ---------------------------------------------------------------------------

it('plugin uses unknown for both names when payload user type is a non-existent class', function () {
    session()->put(config('filament-impersonation.session_key'), [
        'operator_user_id'          => 1,
        'operator_user_type'        => 'NonExistent\\Model\\User',
        'operator_guard'            => 'web',
        'impersonated_user_id'      => 2,
        'impersonated_user_type'    => 'NonExistent\\Model\\User',
        'impersonated_guard'        => 'web',
        'impersonation_activity_id' => 1,
        'started_at'                => now()->toISOString(),
    ]);

    $html = getBannerPluginClosure()()->render();

    $unknown = __('filament-impersonation::messages.unknown');

    expect($html)
        ->toContain('role="alert"')
        ->and(substr_count($html, $unknown))->toBe(2);
});

// ---------------------------------------------------------------------------
// 12. Plugin renders nothing visible when no impersonation session is active
// ---------------------------------------------------------------------------

it('plugin renders nothing visible when no impersonation session is active', function () {
    $html = getBannerPluginClosure()()->render();

    expect($html)->not->toContain('role="alert"');
});
