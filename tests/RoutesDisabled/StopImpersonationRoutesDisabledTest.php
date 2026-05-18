<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// RoutesDisabledTestCase::defineEnvironment sets routes.enabled=false BEFORE
// the ServiceProvider boot() runs, so the stop route is never registered.

// ---------------------------------------------------------------------------
// 3. routes.enabled=false → route is not registered
// ---------------------------------------------------------------------------

it('does not register the impersonation.stop route when routes.enabled is false', function () {
    expect(config('filament-impersonation.routes.enabled'))->toBeFalse();
    expect(Route::has('impersonation.stop'))->toBeFalse();
});
