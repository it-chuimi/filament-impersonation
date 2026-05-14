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

it('can render the banner view with variables', function () {
    $html = view('filament-impersonation::banner', [
        'impersonatorName' => 'Alice',
        'impersonatedName' => 'Bob',
        'leaveUrl'         => '/impersonation/leave',
    ])->render();

    expect($html)->toContain('Alice')
        ->and($html)->toContain('Bob')
        ->and($html)->toContain('/impersonation/leave');
});
