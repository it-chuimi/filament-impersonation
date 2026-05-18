<?php

declare(strict_types=1);

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
