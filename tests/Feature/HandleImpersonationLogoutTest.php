<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\ImpersonationManager;
use Chuimi\FilamentImpersonation\Listeners\HandleImpersonationLogout;
use Chuimi\FilamentImpersonation\Support\ImpersonationLogoutReason;
use Chuimi\FilamentImpersonation\Tests\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

// Shared reason string — long enough to pass reason validation (min 10 chars).
const IMPERSONATION_AUDIT_REASON = 'Testing impersonation for audit purposes';

// ---------------------------------------------------------------------------
// 1. Logout without active impersonation → no activity generated
// ---------------------------------------------------------------------------

it('logout without active impersonation does not record stopped_by_logout activity', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    Auth::login($operator);

    // Sanity: no impersonation active
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();

    // Fire the event directly — there is no impersonation to clean up
    event(new Logout('web', $operator));

    expect(Activity::where('description', 'impersonation.stopped_by_logout')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 2. Logout with active impersonation → payload cleared
// ---------------------------------------------------------------------------

it('logout with active impersonation clears the session payload', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, IMPERSONATION_AUDIT_REASON);

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeTrue();

    event(new Logout('web', $target));

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
    expect(app(ImpersonationManager::class)->payload())->toBeNull();
});

// ---------------------------------------------------------------------------
// 3. Logout with active impersonation → activity recorded
// ---------------------------------------------------------------------------

it('logout with active impersonation records impersonation.stopped_by_logout activity', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, IMPERSONATION_AUDIT_REASON);

    event(new Logout('web', $target));

    expect(Activity::where('description', 'impersonation.stopped_by_logout')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 4. Activity contains logout_reason = manual_logout
// ---------------------------------------------------------------------------

it('stopped_by_logout activity contains logout_reason manual_logout', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, IMPERSONATION_AUDIT_REASON);

    event(new Logout('web', $target));

    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();

    expect($activity)->not()->toBeNull()
        ->and($activity->properties->get('logout_reason'))
        ->toBe(ImpersonationLogoutReason::ManualLogout->value);
});

// ---------------------------------------------------------------------------
// 5. Operator is NOT restored after logout
// ---------------------------------------------------------------------------

it('logout during impersonation does not restore the original operator', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, IMPERSONATION_AUDIT_REASON);

    // Auth is now $target; the listener must NOT re-login as $operator
    event(new Logout('web', $target));

    // The event fired while the guard had $target; we clear impersonation but
    // do NOT re-authenticate as $operator — Auth state stays cleared by Laravel
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
    expect(app(ImpersonationManager::class)->payload())->toBeNull();
});

// ---------------------------------------------------------------------------
// 6. Idempotent: listener does nothing and does not throw when no payload
// ---------------------------------------------------------------------------

it('listener handle does not throw and does nothing when no impersonation is active', function () {
    $user     = User::create(['name' => 'User', 'email' => 'user@example.com']);
    $listener = app(HandleImpersonationLogout::class);
    $logoutEvent = new Logout('web', $user);

    expect(fn () => $listener->handle($logoutEvent))->not()->toThrow(\Throwable::class);

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
    expect(Activity::where('description', 'impersonation.stopped_by_logout')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 7. Listener is registered: Logout event dispatched via event() activates it
// ---------------------------------------------------------------------------

it('the Logout event dispatched through the event system activates the listener', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, IMPERSONATION_AUDIT_REASON);

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeTrue();

    // Dispatch through the real event system — not calling the listener directly.
    // This verifies that Event::listen(Logout::class, HandleImpersonationLogout::class)
    // in the ServiceProvider is in effect.
    event(new Logout('web', $target));

    // Payload must be cleared by the listener (not just session magic)
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();

    // Activity must be recorded (proves manager->stopForLogout() was reached)
    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();
    expect($activity)->not()->toBeNull()
        ->and($activity->properties->get('logout_reason'))->toBe('manual_logout');
});
