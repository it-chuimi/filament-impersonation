<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\ImpersonationManager;
use Chuimi\FilamentImpersonation\Support\RedirectResolver;
use Chuimi\FilamentImpersonation\Tests\Models\User;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// 1. Route exists when routes.enabled = true (default)
// ---------------------------------------------------------------------------

it('registers the POST impersonation/stop route when routes.enabled is true', function () {
    expect(Route::has('impersonation.stop'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 2. Route has expected name with default config
// ---------------------------------------------------------------------------

it('route impersonation.stop resolves to the correct URL', function () {
    expect(route('impersonation.stop'))->toEndWith('/impersonation/stop');
});

// ---------------------------------------------------------------------------
// 4 & 6. POST without active impersonation does not fail and redirects
//         (stop() is idempotent: returns false when no impersonation active,
//         controller still redirects to a safe URL)
// ---------------------------------------------------------------------------

it('POST to stop route redirects safely when no impersonation is active', function () {
    $user = User::create(['name' => 'User', 'email' => 'user@example.com']);

    $response = $this
        ->actingAs($user)
        ->post(route('impersonation.stop'));

    $response->assertRedirect();
});

// ---------------------------------------------------------------------------
// 5. POST with active impersonation calls stop() and redirects
//    The session payload is seeded via withSession() so the request sees an
//    active impersonation. assertSessionMissing() verifies stop() cleared it.
// ---------------------------------------------------------------------------

it('POST to stop route stops an active impersonation and redirects', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    $sessionKey = config('filament-impersonation.session_key');

    $payload = [
        'operator_user_id'          => $operator->id,
        'operator_user_type'        => User::class,
        'operator_guard'            => 'web',
        'impersonated_user_id'      => $target->id,
        'impersonated_user_type'    => User::class,
        'impersonated_guard'        => 'web',
        'impersonation_activity_id' => 999,
        'started_at'                => now()->toISOString(),
    ];

    // The currently authenticated user during impersonation is the target.
    // withSession() seeds the impersonation payload so stop() finds it.
    $response = $this
        ->actingAs($target)
        ->withSession([$sessionKey => $payload])
        ->post(route('impersonation.stop'));

    $response->assertRedirect();

    // The impersonation session key must have been cleared by stop().
    $response->assertSessionMissing($sessionKey);
});

// ---------------------------------------------------------------------------
// TAREA 1: Controller delegates to RedirectResolver::afterStop().
//
//  A fake resolver is bound in the container before the request.
//  After the request we verify:
//    - afterStop() was actually called;
//    - it received the impersonation payload that existed before stop();
//    - the response redirects to the URL the fake returned.
// ---------------------------------------------------------------------------

it('controller delegates to RedirectResolver::afterStop and redirects to its returned URL', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    $sessionKey = config('filament-impersonation.session_key');

    $payload = [
        'operator_user_id'          => $operator->id,
        'operator_user_type'        => User::class,
        'operator_guard'            => 'web',
        'impersonated_user_id'      => $target->id,
        'impersonated_user_type'    => User::class,
        'impersonated_guard'        => 'web',
        'impersonation_activity_id' => 999,
        'started_at'                => now()->toISOString(),
    ];

    $fakeResolver = new class {
        public bool  $called          = false;
        public mixed $capturedPayload = null;

        public function afterStop(
            ?array $payload = null,
            mixed  $operator = null,
            mixed  $impersonated = null,
        ): mixed {
            $this->called          = true;
            $this->capturedPayload = $payload;
            return '/stop-destination';
        }
    };

    app()->instance(RedirectResolver::class, $fakeResolver);

    $response = $this
        ->actingAs($target)
        ->withSession([$sessionKey => $payload])
        ->post(route('impersonation.stop'));

    expect($fakeResolver->called)->toBeTrue();
    expect($fakeResolver->capturedPayload)->toBeArray()
        ->and($fakeResolver->capturedPayload['operator_user_id'])->toBe($operator->id);

    $response->assertRedirect('/stop-destination');
});

// ---------------------------------------------------------------------------
// TAREA 2: Controller returns the RedirectResponse from afterStop().
//
//  When RedirectResolver::afterStop() returns an Illuminate\Http\RedirectResponse
//  (not just a string URL), the controller must return it directly.
//  No active impersonation is needed for this path.
// ---------------------------------------------------------------------------

it('controller returns the RedirectResponse produced by RedirectResolver::afterStop', function () {
    $user = User::create(['name' => 'User', 'email' => 'user@example.com']);

    $fakeResolver = new class {
        public function afterStop(
            ?array $payload = null,
            mixed  $operator = null,
            mixed  $impersonated = null,
        ): mixed {
            return redirect('/redirect-response-target');
        }
    };

    app()->instance(RedirectResolver::class, $fakeResolver);

    $response = $this
        ->actingAs($user)
        ->post(route('impersonation.stop'));

    $response->assertRedirect('/redirect-response-target');
});

// ---------------------------------------------------------------------------
// 7. Default prefix and route name are applied from config
// ---------------------------------------------------------------------------

it('stop route URL reflects the configured prefix', function () {
    $prefix = config('filament-impersonation.routes.prefix', 'impersonation');

    expect(route('impersonation.stop'))->toContain('/' . $prefix . '/stop');
});

it('stop route name reflects the configured name prefix', function () {
    $namePfx = rtrim(config('filament-impersonation.routes.name', 'impersonation.'), '.');

    expect(Route::has($namePfx . '.stop'))->toBeTrue();
});
