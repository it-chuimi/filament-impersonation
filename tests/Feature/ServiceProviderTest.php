<?php

declare(strict_types=1);

it('loads the package configuration', function () {
    expect(config('filament-impersonation'))->toBeArray()
        ->and(config('filament-impersonation.enabled'))->toBeTrue()
        ->and(config('filament-impersonation.session_key'))->toBe('filament_impersonation')
        ->and(config('filament-impersonation.banner_view'))->toBe('filament-impersonation::banner');
});

it('registers the banner view', function () {
    expect(view()->exists('filament-impersonation::banner'))->toBeTrue();
});

it('can render the banner view with impersonator and impersonated names', function () {
    session()->put(config('filament-impersonation.session_key'), [
        'operator_user_id'          => 1,
        'operator_user_type'        => 'App\Models\User',
        'operator_guard'            => 'web',
        'impersonated_user_id'      => 2,
        'impersonated_user_type'    => 'App\Models\User',
        'impersonated_guard'        => 'web',
        'impersonation_activity_id' => 1,
        'started_at'                => now()->toISOString(),
    ]);

    $html = view('filament-impersonation::banner', [
        'impersonatorName' => 'Alice',
        'impersonatedName' => 'Bob',
    ])->render();

    expect($html)->toContain('Alice')
        ->and($html)->toContain('Bob');
});
