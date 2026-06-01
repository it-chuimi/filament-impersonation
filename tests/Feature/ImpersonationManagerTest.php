<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\Exceptions\CannotImpersonateSelfException;
use Chuimi\FilamentImpersonation\Exceptions\ImpersonationAlreadyActiveException;
use Chuimi\FilamentImpersonation\Exceptions\ImpersonationStartFailedException;
use Chuimi\FilamentImpersonation\Exceptions\PackageDisabledException;
use Chuimi\FilamentImpersonation\Exceptions\ProtectedUserCannotBeImpersonatedException;
use Chuimi\FilamentImpersonation\Exceptions\UnauthorizedImpersonationException;
use Chuimi\FilamentImpersonation\ImpersonationManager;
use Chuimi\FilamentImpersonation\Support\ImpersonationActivity;
use Chuimi\FilamentImpersonation\Support\ImpersonationAuthorization;
use Chuimi\FilamentImpersonation\Support\ImpersonationLogoutReason;
use Chuimi\FilamentImpersonation\Tests\Models\User;
use Chuimi\FilamentImpersonation\Tests\Models\UserWithBrokenLogin;
use Chuimi\FilamentImpersonation\Tests\Models\UserWithRoles;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeOperator(array $attrs = []): User
{
    return User::create(array_merge([
        'name'  => 'Operator',
        'email' => 'operator@example.com',
    ], $attrs));
}

function makeTarget(array $attrs = []): User
{
    return User::create(array_merge([
        'name'  => 'Target',
        'email' => 'target@example.com',
    ], $attrs));
}

function loginAs(User $user): void
{
    Auth::login($user);
}

function startImpersonation(User $target, string $reason = 'Testing impersonation for audit purposes'): void
{
    app(ImpersonationManager::class)->start($target, $reason);
}

// ---------------------------------------------------------------------------
// 1. Start impersonation successfully
// ---------------------------------------------------------------------------

it('starts impersonation correctly', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 2. Auth switches to target
// ---------------------------------------------------------------------------

it('authenticates as the target user after start', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    expect(Auth::id())->toBe($target->id);
});

// ---------------------------------------------------------------------------
// 3. Full session payload
// ---------------------------------------------------------------------------

it('stores the full session payload on start', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $payload = app(ImpersonationManager::class)->payload();

    expect($payload)->toBeArray()
        ->and($payload['operator_user_id'])->toBe($operator->id)
        ->and($payload['operator_user_type'])->toBe(User::class)
        ->and($payload['operator_guard'])->toBe('web')
        ->and($payload['impersonated_user_id'])->toBe($target->id)
        ->and($payload['impersonated_user_type'])->toBe(User::class)
        ->and($payload['impersonated_guard'])->toBe('web')
        ->and($payload['impersonation_activity_id'])->not->toBeNull()
        ->and($payload['started_at'])->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 4. Package disabled blocks start
// ---------------------------------------------------------------------------

it('throws PackageDisabledException when package is disabled', function () {
    config()->set('filament-impersonation.enabled', false);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);

    expect(fn () => startImpersonation($target))
        ->toThrow(PackageDisabledException::class);
});

// ---------------------------------------------------------------------------
// 5. canImpersonate returns false when disabled
// ---------------------------------------------------------------------------

it('canImpersonate returns false when package is disabled', function () {
    config()->set('filament-impersonation.enabled', false);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);

    expect(app(ImpersonationManager::class)->canImpersonate($target))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 5b. canImpersonate returns false when impersonation is already active
// ---------------------------------------------------------------------------

it('canImpersonate returns false when an impersonation is already active', function () {
    $operator = makeOperator();
    $target   = makeTarget();
    $another  = User::create(['name' => 'Another', 'email' => 'another@example.com']);

    loginAs($operator);
    startImpersonation($target);

    expect(app(ImpersonationManager::class)->canImpersonate($another))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. Nested impersonation is blocked
// ---------------------------------------------------------------------------

it('blocks nested impersonation when one is already active', function () {
    $operator = makeOperator();
    $target   = makeTarget();
    $another  = User::create(['name' => 'Another', 'email' => 'another@example.com']);

    loginAs($operator);
    startImpersonation($target);

    expect(fn () => app(ImpersonationManager::class)->start($another, 'Attempting nested impersonation'))
        ->toThrow(ImpersonationAlreadyActiveException::class);
});

// ---------------------------------------------------------------------------
// 7. Self-impersonation is always blocked
// ---------------------------------------------------------------------------

it('blocks self-impersonation', function () {
    $operator = makeOperator();

    loginAs($operator);

    expect(fn () => startImpersonation($operator))
        ->toThrow(CannotImpersonateSelfException::class);
});

// ---------------------------------------------------------------------------
// 8. Protected user blocked by protected_roles
// ---------------------------------------------------------------------------

it('blocks impersonation when target has a protected role', function () {
    config()->set('filament-impersonation.protected_roles', ['admin']);

    $operator = makeOperator();
    loginAs($operator);

    $target = UserWithRoles::create(['name' => 'Admin', 'email' => 'admin@example.com']);
    $target->forcedRoles = ['admin'];

    expect(fn () => app(ImpersonationManager::class)->start($target, 'Trying to impersonate an admin'))
        ->toThrow(ProtectedUserCannotBeImpersonatedException::class);
});

// ---------------------------------------------------------------------------
// 9. protected_roles set + target has no hasAnyRole() → deny for safety
// ---------------------------------------------------------------------------

it('blocks impersonation when protected_roles is set and target lacks hasAnyRole', function () {
    config()->set('filament-impersonation.protected_roles', ['admin']);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);

    expect(fn () => app(ImpersonationManager::class)->start($target, 'Testing safety denial'))
        ->toThrow(ProtectedUserCannotBeImpersonatedException::class);
});

// ---------------------------------------------------------------------------
// 9b. is_protected_user callback
// ---------------------------------------------------------------------------

it('blocks impersonation when is_protected_user callback returns true', function () {
    config()->set('filament-impersonation.is_protected_user', fn ($target) => true);

    $operator = makeOperator();
    $target   = makeTarget();
    loginAs($operator);

    expect(fn () => startImpersonation($target))
        ->toThrow(ProtectedUserCannotBeImpersonatedException::class);
});

it('allows impersonation when is_protected_user callback returns false', function () {
    config()->set('filament-impersonation.is_protected_user', fn ($target) => false);

    $operator = makeOperator();
    $target   = makeTarget();
    loginAs($operator);
    startImpersonation($target);

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 10. Authorization via callback
// ---------------------------------------------------------------------------

it('authorizes impersonation through the can_impersonate callback', function () {
    config()->set('filament-impersonation.can_impersonate', fn () => true);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeTrue();
});

it('denies impersonation when can_impersonate callback returns false', function () {
    config()->set('filament-impersonation.can_impersonate', fn () => false);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);

    expect(fn () => startImpersonation($target))
        ->toThrow(UnauthorizedImpersonationException::class);
});

// ---------------------------------------------------------------------------
// 11. Authorization via role when model supports hasAnyRole
// ---------------------------------------------------------------------------

it('authorizes impersonation when operator has the required role', function () {
    config()->set('filament-impersonation.operator_roles', ['super-admin']);

    $operator = UserWithRoles::create(['name' => 'SuperAdmin', 'email' => 'superadmin@example.com']);
    $operator->forcedRoles = ['super-admin'];

    $target = makeTarget();

    loginAs($operator);
    app(ImpersonationManager::class)->start($target, 'Testing role-based authorization');

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeTrue();
});

it('denies impersonation when operator lacks the required role', function () {
    config()->set('filament-impersonation.operator_roles', ['super-admin']);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);

    expect(fn () => startImpersonation($target))
        ->toThrow(UnauthorizedImpersonationException::class);
});

// ---------------------------------------------------------------------------
// 11b. Authorization via operator_permissions
// ---------------------------------------------------------------------------

it('authorizes impersonation when operator has the required permission', function () {
    config()->set('filament-impersonation.operator_permissions', ['impersonate-users']);

    $operator = UserWithRoles::create(['name' => 'PowerUser', 'email' => 'power@example.com']);
    $operator->forcedPermissions = ['impersonate-users'];

    $target = makeTarget();

    loginAs($operator);
    app(ImpersonationManager::class)->start($target, 'Testing permission-based authorization');

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeTrue();
});

it('denies impersonation when operator lacks the required permission', function () {
    config()->set('filament-impersonation.operator_permissions', ['impersonate-users']);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);

    expect(fn () => startImpersonation($target))
        ->toThrow(UnauthorizedImpersonationException::class);
});

// ---------------------------------------------------------------------------
// 12. impersonation.started is mandatory — if it fails, start is aborted
// (uses anonymous class stub, no Mockery required)
// ---------------------------------------------------------------------------

it('does not start impersonation if recording the start activity fails', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);

    $failingActivity = new class(request()) extends ImpersonationActivity {
        public function recordStart(
            \Illuminate\Contracts\Auth\Authenticatable $operator,
            \Illuminate\Contracts\Auth\Authenticatable $target,
            string $guard,
            string $reason,
        ): \Spatie\Activitylog\Models\Activity {
            throw new ImpersonationStartFailedException('Forced failure in test');
        }
    };

    $manager = new ImpersonationManager(
        $failingActivity,
        app(ImpersonationAuthorization::class),
    );

    expect(fn () => $manager->start($target, 'Testing activity failure stub'))
        ->toThrow(ImpersonationStartFailedException::class);

    expect(Auth::id())->toBe($operator->id);
    expect(session()->has(config('filament-impersonation.session_key')))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 13. stop() restores the original operator
// ---------------------------------------------------------------------------

it('restores the original operator after stop', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    expect(Auth::id())->toBe($target->id);

    app(ImpersonationManager::class)->stop();

    expect(Auth::id())->toBe($operator->id);
});

// ---------------------------------------------------------------------------
// 13b. user_model config is used to resolve the operator on restore
// ---------------------------------------------------------------------------

it('uses configured user_model to restore the operator', function () {
    config()->set('filament-impersonation.user_model', User::class);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);
    app(ImpersonationManager::class)->stop();

    expect(Auth::id())->toBe($operator->id);
});

// ---------------------------------------------------------------------------
// 13e. user_model is null → resolves operator from auth provider fallback
// ---------------------------------------------------------------------------

it('resolves operator model from auth provider when user_model is null', function () {
    // user_model is null by default; auth.providers.users.model is set to User::class in TestCase.
    config()->set('filament-impersonation.user_model', null);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);
    $result = app(ImpersonationManager::class)->stop();

    expect($result)->toBeTrue();
    expect(Auth::id())->toBe($operator->id);
    expect(Activity::where('description', 'impersonation.stopped')->exists())->toBeTrue();
    expect(Activity::where('description', 'impersonation.stopped_by_logout')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 13c. User model not resolvable → safe logout with user_model_not_resolvable
// ---------------------------------------------------------------------------

it('performs safe logout with user_model_not_resolvable when model cannot be resolved', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    // Corrupt model resolution after impersonation has started
    config()->set('filament-impersonation.user_model', 'App\\NonExistent\\UserModel');
    config()->set('auth.guards.web.provider', null);
    config()->set('auth.providers.users.model', null);

    app(ImpersonationManager::class)->stop();

    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['logout_reason'])
        ->toBe(ImpersonationLogoutReason::UserModelNotResolvable->value);

    expect(Auth::check())->toBeFalse();
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
    expect(session()->has(config('filament-impersonation.session_key')))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 13d. Explicit invalid user_model → user_model_not_resolvable (no fallback used)
// ---------------------------------------------------------------------------

it('does not fall back to auth provider when user_model is explicitly set to an invalid class', function () {
    // auth.providers.users.model remains valid — the valid fallback must NOT be used.
    config()->set('filament-impersonation.user_model', 'App\\NonExistent\\UserModel');

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    app(ImpersonationManager::class)->stop();

    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['logout_reason'])
        ->toBe(ImpersonationLogoutReason::UserModelNotResolvable->value);

    expect(Auth::check())->toBeFalse();
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
    expect(session()->has(config('filament-impersonation.session_key')))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 14. stop() records impersonation.stopped on normal exit
// ---------------------------------------------------------------------------

it('records impersonation.stopped on normal stop', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);
    app(ImpersonationManager::class)->stop();

    $activity = Activity::where('description', 'impersonation.stopped')->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->properties['operator_user_id'])->toBe($operator->id)
        ->and((int) $activity->properties['impersonated_user_id'])->toBe($target->id);
});

// ---------------------------------------------------------------------------
// 15. stop() is idempotent
// ---------------------------------------------------------------------------

it('stop returns false when no impersonation is active', function () {
    expect(app(ImpersonationManager::class)->stop())->toBeFalse();
});

it('stop returns true when impersonation is stopped', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    expect(app(ImpersonationManager::class)->stop())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 16. payload() returns null when not impersonating
// ---------------------------------------------------------------------------

it('payload returns null when no impersonation is active', function () {
    expect(app(ImpersonationManager::class)->payload())->toBeNull();
});

// ---------------------------------------------------------------------------
// 17. stopForLogout() records stopped_by_logout and does not restore operator
// ---------------------------------------------------------------------------

it('stopForLogout records stopped_by_logout and does not restore the operator', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $manager = app(ImpersonationManager::class);
    $manager->stopForLogout(ImpersonationLogoutReason::ManualLogout);

    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['logout_reason'])
        ->toBe(ImpersonationLogoutReason::ManualLogout->value);

    expect($manager->isImpersonating())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 18. Operator deleted before stop → safe logout + operator_not_found
// ---------------------------------------------------------------------------

it('performs safe logout when the original operator cannot be found', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $operator->delete();

    app(ImpersonationManager::class)->stop();

    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['logout_reason'])
        ->toBe(ImpersonationLogoutReason::OperatorNotFound->value);

    expect(Auth::check())->toBeFalse();
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 18b. is_restorable_user returns false → operator_not_restorable
// ---------------------------------------------------------------------------

it('performs safe logout with operator_not_restorable when is_restorable_user returns false', function () {
    config()->set('filament-impersonation.is_restorable_user', fn ($user) => false);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);
    app(ImpersonationManager::class)->stop();

    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['logout_reason'])
        ->toBe(ImpersonationLogoutReason::OperatorNotRestorable->value);

    expect(Auth::check())->toBeFalse();
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 18c. restore_failed: is_restorable_user callback throws unexpectedly
// ---------------------------------------------------------------------------

it('performs safe logout with restore_failed when restorable check throws', function () {
    config()->set('filament-impersonation.is_restorable_user', function () {
        throw new \RuntimeException('Unexpected error during restoration check');
    });

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);
    app(ImpersonationManager::class)->stop();

    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['logout_reason'])
        ->toBe(ImpersonationLogoutReason::RestoreFailed->value);

    expect(Auth::check())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 18d. restore_failed: Auth::guard()->login() throws during normal stop
// (uses UserWithBrokenLogin — no Mockery required)
// ---------------------------------------------------------------------------

it('performs safe logout with restore_failed when login throws during operator restoration', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    // After impersonation starts, swap user_model to a model whose getAuthIdentifier() throws.
    // The DB find still works (queries by PK); login() is what calls getAuthIdentifier().
    config()->set('filament-impersonation.user_model', UserWithBrokenLogin::class);

    $result = app(ImpersonationManager::class)->stop();

    expect($result)->toBeTrue();
    expect(Activity::where('description', 'impersonation.stopped')->exists())->toBeFalse();

    $activity = Activity::where('description', 'impersonation.stopped_by_logout')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties['logout_reason'])
        ->toBe(ImpersonationLogoutReason::RestoreFailed->value);

    expect(session()->has(config('filament-impersonation.session_key')))->toBeFalse();
    expect(Auth::check())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 19. Session is regenerated on start and stop
// ---------------------------------------------------------------------------

it('regenerates the session when impersonation starts', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);

    $idBefore = session()->getId();
    startImpersonation($target);

    expect(session()->getId())->not->toBe($idBefore);
});

it('regenerates the session when impersonation stops normally', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $idBefore = session()->getId();
    app(ImpersonationManager::class)->stop();

    expect(session()->getId())->not->toBe($idBefore);
});

// ---------------------------------------------------------------------------
// 20. Activity log field coverage
// ---------------------------------------------------------------------------

it('records operator_user_type and impersonated_user_type in the start activity', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $activity = Activity::where('description', 'impersonation.started')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['operator_user_type'])->toBe(User::class)
        ->and($activity->properties['impersonated_user_type'])->toBe(User::class)
        ->and($activity->properties['ip_address'])->not->toBeNull();
});

it('records duration_seconds, operator_user_type and impersonated_user_type in the stop activity', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);
    app(ImpersonationManager::class)->stop();

    $activity = Activity::where('description', 'impersonation.stopped')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties)->toHaveKey('duration_seconds')
        ->and($activity->properties['duration_seconds'])->toBeInt()
        ->and($activity->properties['operator_user_type'])->toBe(User::class)
        ->and($activity->properties['impersonated_user_type'])->toBe(User::class);
});

// ---------------------------------------------------------------------------
// 21. Guard validation — operator_guard is validated before use in stop()
// ---------------------------------------------------------------------------

it('stop() does not throw when operator_guard is an invalid guard string', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $payload                   = session()->get(config('filament-impersonation.session_key'));
    $payload['operator_guard'] = 'nonexistent_guard';
    session()->put(config('filament-impersonation.session_key'), $payload);

    expect(fn () => app(ImpersonationManager::class)->stop())->not()->toThrow(\Throwable::class);
});

it('stop() falls back to the resolved guard, restores the operator and clears payload when operator_guard is invalid', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $payload                   = session()->get(config('filament-impersonation.session_key'));
    $payload['operator_guard'] = 'nonexistent_guard';
    session()->put(config('filament-impersonation.session_key'), $payload);

    $result = app(ImpersonationManager::class)->stop();

    expect($result)->toBeTrue();
    expect(Auth::id())->toBe($operator->id);
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
    expect(session()->has(config('filament-impersonation.session_key')))->toBeFalse();
});

it('stop() does not throw when operator_guard key is absent from payload', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $payload = session()->get(config('filament-impersonation.session_key'));
    unset($payload['operator_guard']);
    session()->put(config('filament-impersonation.session_key'), $payload);

    expect(fn () => app(ImpersonationManager::class)->stop())->not()->toThrow(\Throwable::class);
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
});

it('stop() does not throw when operator_guard is an empty string', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $payload                   = session()->get(config('filament-impersonation.session_key'));
    $payload['operator_guard'] = '';
    session()->put(config('filament-impersonation.session_key'), $payload);

    expect(fn () => app(ImpersonationManager::class)->stop())->not()->toThrow(\Throwable::class);
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
});

it('stopForLogout() does not throw when operator_guard is invalid and clears the impersonation session', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $payload                   = session()->get(config('filament-impersonation.session_key'));
    $payload['operator_guard'] = 'nonexistent_guard';
    session()->put(config('filament-impersonation.session_key'), $payload);

    expect(fn () => app(ImpersonationManager::class)->stopForLogout())->not()->toThrow(\Throwable::class);
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
});

it('corrupting impersonated_guard has no effect on operator restoration in stop()', function () {
    // impersonated_guard is stored in the payload but never used in auth or
    // restore logic — only operator_guard matters for guard resolution.
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    $payload                      = session()->get(config('filament-impersonation.session_key'));
    $payload['impersonated_guard'] = 'nonexistent_guard';
    session()->put(config('filament-impersonation.session_key'), $payload);

    $result = app(ImpersonationManager::class)->stop();

    expect($result)->toBeTrue();
    expect(Auth::id())->toBe($operator->id);
    expect(app(ImpersonationManager::class)->isImpersonating())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 22. AuthenticateSession compatibility — start()
// ---------------------------------------------------------------------------

it('start() stores the active guard in session for AuthenticateSession', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    expect(session('guard'))->toBe('web');
});

it('start() writes password_hash_{guard} for the impersonated user', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    // The key must be present even when getAuthPassword() returns null
    // (e.g. no password column), ensuring AuthenticateSession sees a consistent value.
    expect(session()->exists('password_hash_web'))->toBeTrue();
    expect(session('password_hash_web'))->toBe($target->getAuthPassword());
});

// ---------------------------------------------------------------------------
// 23. AuthenticateSession compatibility — stop() normal
// ---------------------------------------------------------------------------

it('stop() writes the active guard in session after restoring operator', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);
    app(ImpersonationManager::class)->stop();

    expect(session('guard'))->toBe('web');
});

it('stop() writes password_hash_{guard} for the restored operator', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);
    app(ImpersonationManager::class)->stop();

    expect(session()->exists('password_hash_web'))->toBeTrue();
    expect(session('password_hash_web'))->toBe($operator->getAuthPassword());
});

it('stop() password_hash_{guard} reflects operator after restoration, not target', function () {
    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    // Hash during impersonation belongs to target.
    expect(session('password_hash_web'))->toBe($target->getAuthPassword());

    app(ImpersonationManager::class)->stop();

    // After stop, hash must match the restored operator, not the target.
    expect(session('password_hash_web'))->toBe($operator->getAuthPassword());
    expect(Auth::id())->toBe($operator->id);
});

// ---------------------------------------------------------------------------
// 25. Reason validation — siempre obligatorio
// ---------------------------------------------------------------------------

it('start() throws InvalidArgumentException when reason is empty', function () {
    $operator = makeOperator();
    $target   = makeTarget();
    loginAs($operator);

    expect(fn () => app(ImpersonationManager::class)->start($target, ''))
        ->toThrow(\InvalidArgumentException::class);
});

it('start() throws InvalidArgumentException when reason contains only whitespace', function () {
    $operator = makeOperator();
    $target   = makeTarget();
    loginAs($operator);

    expect(fn () => app(ImpersonationManager::class)->start($target, '   '))
        ->toThrow(\InvalidArgumentException::class);
});

it('start() throws InvalidArgumentException when reason is shorter than min_length', function () {
    config()->set('filament-impersonation.reason.min_length', 15);

    $operator = makeOperator();
    $target   = makeTarget();
    loginAs($operator);

    expect(fn () => app(ImpersonationManager::class)->start($target, 'Too short'))
        ->toThrow(\InvalidArgumentException::class);
});

it('start() succeeds when reason meets the configured min_length', function () {
    config()->set('filament-impersonation.reason.min_length', 5);

    $operator = makeOperator();
    $target   = makeTarget();
    loginAs($operator);

    app(ImpersonationManager::class)->start($target, 'Five!');

    expect(app(ImpersonationManager::class)->isImpersonating())->toBeTrue();
});

it('reason.required is not present in the package config', function () {
    $reason = config('filament-impersonation.reason');

    expect($reason)->toBeArray()
        ->not->toHaveKey('required');
});

// ---------------------------------------------------------------------------
// 24. password_hash is not set in forced stop paths
// ---------------------------------------------------------------------------

it('forced stop does not write password_hash_{guard} for operator', function () {
    config()->set('filament-impersonation.is_restorable_user', fn ($user) => false);

    $operator = makeOperator();
    $target   = makeTarget();

    loginAs($operator);
    startImpersonation($target);

    // Capture hash that was set during start (target's hash).
    $hashAfterStart = session('password_hash_web');

    app(ImpersonationManager::class)->stop();

    // Forced stop invalidates the session; the hash key should no longer exist.
    expect(session()->has('password_hash_web'))->toBeFalse();

    // And the user must not be authenticated.
    expect(Auth::check())->toBeFalse();
});
