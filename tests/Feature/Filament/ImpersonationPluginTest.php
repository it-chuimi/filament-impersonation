<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\Filament\ImpersonationPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// 1. make() returns an ImpersonationPlugin instance
// ---------------------------------------------------------------------------

it('ImpersonationPlugin::make() returns an instance of ImpersonationPlugin', function () {
    $plugin = ImpersonationPlugin::make();

    expect($plugin)
        ->toBeInstanceOf(ImpersonationPlugin::class)
        ->toBeInstanceOf(Plugin::class);
});

// ---------------------------------------------------------------------------
// 2. getId() returns a stable identifier
// ---------------------------------------------------------------------------

it('getId() returns the stable identifier filament-impersonation', function () {
    expect(ImpersonationPlugin::make()->getId())->toBe('filament-impersonation');
});

// ---------------------------------------------------------------------------
// 3. Plugin registers in a Panel without throwing
// ---------------------------------------------------------------------------

it('plugin can be registered in a Panel without throwing errors', function () {
    $panel = new Panel();

    expect(fn () => $panel->plugin(ImpersonationPlugin::make()))
        ->not->toThrow(Throwable::class);
});

// ---------------------------------------------------------------------------
// 4. register() adds a BODY_START render hook that returns the banner view
//
//    Uses Reflection on the protected $renderHooks array (HasRenderHooks trait,
//    simple PHP array, stable in PHP 8.1+). The closure is invoked to verify
//    it returns the correct View without rendering to HTML.
// ---------------------------------------------------------------------------

it('register() adds a BODY_START render hook that returns the banner view', function () {
    $panel = new Panel();
    $panel->plugin(ImpersonationPlugin::make());

    // $renderHooks is protected array defined in HasRenderHooks trait.
    // Structure: ['hook.name' => ['scope' => [Closure, ...]]]
    $hooks = (new ReflectionProperty(Panel::class, 'renderHooks'))->getValue($panel);

    expect($hooks)->toHaveKey(PanelsRenderHook::BODY_START);

    $closure = $hooks[PanelsRenderHook::BODY_START][''][0];
    $result  = $closure();

    expect($result)
        ->toBeInstanceOf(View::class)
        ->and($result->name())->toBe('filament-impersonation::banner');
});

// ---------------------------------------------------------------------------
// 5. Plugin registration does not register routes or modify config
// ---------------------------------------------------------------------------

it('plugin registration does not register routes or modify config', function () {
    $configBefore = config('filament-impersonation');
    $routesBefore = Route::getRoutes()->count();

    (new Panel())->plugin(ImpersonationPlugin::make());

    expect(config('filament-impersonation'))->toBe($configBefore);
    expect(Route::getRoutes()->count())->toBe($routesBefore);
});

// ---------------------------------------------------------------------------
// 6. banner_view config — custom view is honored
// ---------------------------------------------------------------------------

it('register() uses the view name from banner_view config when overridden', function () {
    // Register an alias namespace pointing to the existing views directory
    // so the custom view name resolves to a real file without any fixtures.
    app('view')->addNamespace(
        'custom-banner-test',
        realpath(__DIR__ . '/../../../resources/views'),
    );
    config(['filament-impersonation.banner_view' => 'custom-banner-test::banner']);

    $panel = new Panel();
    ImpersonationPlugin::make()->register($panel);

    $hooks   = (new ReflectionProperty(Panel::class, 'renderHooks'))->getValue($panel);
    $closure = $hooks[PanelsRenderHook::BODY_START][''][0];
    $result  = $closure();

    expect($result)->toBeInstanceOf(View::class)
        ->and($result->name())->toBe('custom-banner-test::banner');
});

// ---------------------------------------------------------------------------
// 7. banner_view config — custom view receives impersonatorName / impersonatedName
// ---------------------------------------------------------------------------

it('render hook passes impersonatorName and impersonatedName to a custom banner view', function () {
    app('view')->addNamespace(
        'custom-banner-test',
        realpath(__DIR__ . '/../../../resources/views'),
    );
    config(['filament-impersonation.banner_view' => 'custom-banner-test::banner']);

    $panel = new Panel();
    ImpersonationPlugin::make()->register($panel);

    $hooks   = (new ReflectionProperty(Panel::class, 'renderHooks'))->getValue($panel);
    $closure = $hooks[PanelsRenderHook::BODY_START][''][0];
    $result  = $closure();

    $data = $result->getData();

    expect($data)
        ->toHaveKey('impersonatorName')
        ->toHaveKey('impersonatedName');
});
