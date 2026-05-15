<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\Support\RedirectResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// 1. afterStart with null config → fallback
// ---------------------------------------------------------------------------

it('afterStart returns a safe fallback string when redirect_after_start is null', function () {
    config()->set('filament-impersonation.redirect_after_start', null);

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 2. afterStop with null config → fallback
// ---------------------------------------------------------------------------

it('afterStop returns a safe fallback string when redirect_after_stop is null', function () {
    config()->set('filament-impersonation.redirect_after_stop', null);

    $result = app(RedirectResolver::class)->afterStop();

    expect($result)->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 3. Absolute URL string → returned as-is
// ---------------------------------------------------------------------------

it('resolve returns an absolute URL string as-is', function () {
    config()->set('filament-impersonation.redirect_after_start', 'https://example.com/admin');

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBe('https://example.com/admin');
});

// ---------------------------------------------------------------------------
// 4. Relative path starting with '/' → returned as-is
// ---------------------------------------------------------------------------

it('resolve returns a path starting with slash as-is', function () {
    config()->set('filament-impersonation.redirect_after_start', '/admin/dashboard');

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBe('/admin/dashboard');
});

// ---------------------------------------------------------------------------
// 5. Named route that exists → resolved to its URL
// ---------------------------------------------------------------------------

it('resolve resolves a named route string to its URL', function () {
    Route::get('/test-redirect-target', fn () => 'ok')->name('test.redirect.target');

    config()->set('filament-impersonation.redirect_after_start', 'test.redirect.target');

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBeString()->toContain('/test-redirect-target');
});

// ---------------------------------------------------------------------------
// 6. Named route that does not exist → does not throw, returns safe fallback
// ---------------------------------------------------------------------------

it('resolve does not throw when a named route does not exist and returns safe fallback', function () {
    config()->set('filament-impersonation.redirect_after_start', 'nonexistent.route.xyz');

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBe('/');
});

// ---------------------------------------------------------------------------
// 7. Callable returning a string → string is used
// ---------------------------------------------------------------------------

it('resolve executes the callable and uses the returned string', function () {
    config()->set('filament-impersonation.redirect_after_start', fn ($ctx) => '/callable-result');

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBe('/callable-result');
});

// ---------------------------------------------------------------------------
// 8. Callable returning null → fallback is used
// ---------------------------------------------------------------------------

it('resolve uses fallback when callable returns null', function () {
    config()->set('filament-impersonation.redirect_after_start', fn ($ctx) => null);

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 9. Callable receives expected context keys with correct phase
// ---------------------------------------------------------------------------

it('callable receives the expected context array with phase start', function () {
    $captured = null;

    config()->set('filament-impersonation.redirect_after_start', function ($ctx) use (&$captured) {
        $captured = $ctx;

        return '/';
    });

    app(RedirectResolver::class)->afterStart(
        payload: ['started_at' => '2024-01-01T00:00:00Z'],
    );

    expect($captured)->toBeArray()
        ->and($captured)->toHaveKeys(['phase', 'operator', 'impersonated', 'payload', 'request'])
        ->and($captured['phase'])->toBe('start')
        ->and($captured['payload'])->toBe(['started_at' => '2024-01-01T00:00:00Z'])
        ->and($captured['operator'])->toBeNull()
        ->and($captured['impersonated'])->toBeNull();
});

it('callable receives the expected context array with phase stop', function () {
    $captured = null;

    config()->set('filament-impersonation.redirect_after_stop', function ($ctx) use (&$captured) {
        $captured = $ctx;

        return '/';
    });

    app(RedirectResolver::class)->afterStop();

    expect($captured)->toBeArray()
        ->and($captured['phase'])->toBe('stop');
});

// ---------------------------------------------------------------------------
// 10. afterStart uses redirect_after_start (not redirect_after_stop)
// ---------------------------------------------------------------------------

it('afterStart uses redirect_after_start config key', function () {
    config()->set('filament-impersonation.redirect_after_start', '/start-destination');
    config()->set('filament-impersonation.redirect_after_stop', '/stop-destination');

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBe('/start-destination');
});

// ---------------------------------------------------------------------------
// 11. afterStop uses redirect_after_stop (not redirect_after_start)
// ---------------------------------------------------------------------------

it('afterStop uses redirect_after_stop config key', function () {
    config()->set('filament-impersonation.redirect_after_start', '/start-destination');
    config()->set('filament-impersonation.redirect_after_stop', '/stop-destination');

    $result = app(RedirectResolver::class)->afterStop();

    expect($result)->toBe('/stop-destination');
});

// ---------------------------------------------------------------------------
// Extra: callable returning RedirectResponse is passed through
// ---------------------------------------------------------------------------

it('resolve passes through a RedirectResponse returned by a callable', function () {
    $response = new RedirectResponse('/somewhere');

    config()->set('filament-impersonation.redirect_after_start', fn ($ctx) => $response);

    $result = app(RedirectResolver::class)->afterStart();

    expect($result)->toBeInstanceOf(RedirectResponse::class)
        ->and($result->getTargetUrl())->toBe('/somewhere');
});
