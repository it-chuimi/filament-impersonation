<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\ImpersonationManager;
use Chuimi\FilamentImpersonation\Tests\Models\AuditableModel;
use Chuimi\FilamentImpersonation\Tests\Models\PlainAuditableModel;
use Chuimi\FilamentImpersonation\Tests\Models\User;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

// Shared reason — passes min-length validation (>= 10 chars).
const AUDIT_TRAIT_REASON = 'Testing impersonation for audit purposes';

// ---------------------------------------------------------------------------
// 1. No impersonation — activity is normal, no impersonation fields added
// ---------------------------------------------------------------------------

it('creates normal activity without impersonation context when no impersonation is active', function () {
    $model = AuditableModel::create(['name' => 'Widget']);

    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity)->not()->toBeNull()
        ->and($activity->properties->has('impersonation_activity_id'))->toBeFalse()
        ->and($activity->properties->has('operator_user_id'))->toBeFalse()
        ->and($activity->properties->has('impersonated_user_id'))->toBeFalse();
});

it('does not alter the causer unexpectedly when no impersonation is active', function () {
    $user  = User::create(['name' => 'User', 'email' => 'user@example.com']);
    Auth::login($user);

    $model = AuditableModel::create(['name' => 'Widget']);

    $activity = Activity::forSubject($model)->latest()->first();

    // Default causer is the authenticated user; trait must not change it
    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->causer_type)->toBe($user::class);
});

// ---------------------------------------------------------------------------
// 2. With impersonation — activity is enriched with full context
// ---------------------------------------------------------------------------

it('enriches activity with impersonation_activity_id when impersonation is active', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    $model    = AuditableModel::create(['name' => 'Widget']);
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->properties->get('impersonation_activity_id'))->not()->toBeNull();
});

it('enriches activity with operator_user_id when impersonation is active', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    $model    = AuditableModel::create(['name' => 'Widget']);
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->properties->get('operator_user_id'))->toBe($operator->id);
});

it('enriches activity with impersonated_user_id when impersonation is active', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    $model    = AuditableModel::create(['name' => 'Widget']);
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->properties->get('impersonated_user_id'))->toBe($target->id);
});

it('enriches activity with operator_user_type when impersonation is active', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    $model    = AuditableModel::create(['name' => 'Widget']);
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->properties->get('operator_user_type'))->toBe($operator::class);
});

it('enriches activity with impersonated_user_type when impersonation is active', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    $model    = AuditableModel::create(['name' => 'Widget']);
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->properties->get('impersonated_user_type'))->toBe($target::class);
});

// ---------------------------------------------------------------------------
// 3. Causer override — activity.causer is the real operator, not the target
// ---------------------------------------------------------------------------

it('sets causer_id to operator_user_id when impersonation is active', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    // After start, Auth::user() is $target — default causer would be $target.
    // tapActivity must override it to $operator.
    $model    = AuditableModel::create(['name' => 'Widget']);
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->causer_id)->toBe($operator->id);
});

it('sets causer_type to operator_user_type when impersonation is active', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    $model    = AuditableModel::create(['name' => 'Widget']);
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->causer_type)->toBe($operator::class);
});

// ---------------------------------------------------------------------------
// 4. Existing properties (attributes / old) are preserved
// ---------------------------------------------------------------------------

it('preserves the attributes key in properties when impersonation is active', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    $model    = AuditableModel::create(['name' => 'Widget']);
    $activity = Activity::forSubject($model)->latest()->first();

    // LogsActivity populates properties.attributes — must not be erased by merge
    expect($activity->properties->has('attributes'))->toBeTrue()
        ->and($activity->properties->get('attributes'))->toMatchArray(['name' => 'Widget']);
});

it('preserves the old key in properties when updating a model during impersonation', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);
    $model    = AuditableModel::create(['name' => 'Original']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    $model->update(['name' => 'Updated']);

    $activity = Activity::forSubject($model)->where('event', 'updated')->latest()->first();

    expect($activity->properties->has('old'))->toBeTrue()
        ->and($activity->properties->get('old'))->toMatchArray(['name' => 'Original']);
});

// ---------------------------------------------------------------------------
// 5. Incomplete payload — trait must not throw and must add only safe fields
// ---------------------------------------------------------------------------

// Decision: payload is seeded directly in session using session_key from config.
// This is necessary because ImpersonationManager::start() always builds a complete
// payload (operator/target/guard/activity_id). Using start() would not exercise the
// "partial payload" edge case. Seeding via session is the documented approach for
// this scenario (per task spec).

it('does not throw and adds only present fields when payload is partially populated', function () {
    // Incomplete: has operator_user_id but not operator_user_type or impersonated_user_id
    session()->put(config('filament-impersonation.session_key'), [
        'operator_user_id'        => 999,
        'impersonation_activity_id' => 42,
    ]);

    expect(fn () => AuditableModel::create(['name' => 'Widget']))->not()->toThrow(\Throwable::class);

    $model    = AuditableModel::query()->where('name', 'Widget')->latest('id')->first();
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->properties->get('operator_user_id'))->toBe(999)
        ->and($activity->properties->get('impersonation_activity_id'))->toBe(42)
        ->and($activity->properties->has('impersonated_user_id'))->toBeFalse()
        ->and($activity->properties->has('operator_user_type'))->toBeFalse();

    // causer must NOT be set: operator_user_type is missing
    expect($activity->causer_id)->toBeNull()
        ->and($activity->causer_type)->toBeNull();
});

it('does not set causer when operator_user_type is absent from payload', function () {
    session()->put(config('filament-impersonation.session_key'), [
        'operator_user_id' => 999,
        // operator_user_type intentionally absent
    ]);

    AuditableModel::create(['name' => 'Widget']);

    $model    = AuditableModel::query()->where('name', 'Widget')->latest('id')->first();
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->causer_id)->toBeNull()
        ->and($activity->causer_type)->toBeNull();
});

it('does not set causer when operator_user_id is absent from payload', function () {
    session()->put(config('filament-impersonation.session_key'), [
        'operator_user_type' => User::class,
        // operator_user_id intentionally absent
    ]);

    AuditableModel::create(['name' => 'Widget']);

    $model    = AuditableModel::query()->where('name', 'Widget')->latest('id')->first();
    $activity = Activity::forSubject($model)->latest()->first();

    expect($activity->causer_id)->toBeNull()
        ->and($activity->causer_type)->toBeNull();
});

// ---------------------------------------------------------------------------
// 6. Model without the trait does not receive impersonation context
// ---------------------------------------------------------------------------

it('does not enrich activity for a model that uses LogsActivity but not HasImpersonationActivityContext', function () {
    $operator = User::create(['name' => 'Operator', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target',   'email' => 'target@example.com']);

    Auth::login($operator);
    app(ImpersonationManager::class)->start($target, AUDIT_TRAIT_REASON);

    // PlainAuditableModel has LogsActivity but NOT HasImpersonationActivityContext
    $plain    = PlainAuditableModel::create(['name' => 'Plain Widget']);
    $activity = Activity::forSubject($plain)->latest()->first();

    expect($activity)->not()->toBeNull()
        ->and($activity->properties->has('impersonation_activity_id'))->toBeFalse()
        ->and($activity->properties->has('operator_user_id'))->toBeFalse()
        // causer is the currently authenticated user ($target), not the operator
        ->and($activity->causer_id)->toBe($target->id);
});
