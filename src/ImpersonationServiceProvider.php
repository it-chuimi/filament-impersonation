<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation;

use Chuimi\FilamentImpersonation\Support\ImpersonationActivity;
use Chuimi\FilamentImpersonation\Support\ImpersonationAuthorization;
use Chuimi\FilamentImpersonation\Support\RedirectResolver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ImpersonationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/filament-impersonation.php',
            'filament-impersonation'
        );

        $this->app->singleton(ImpersonationAuthorization::class);
        $this->app->singleton(ImpersonationActivity::class);
        $this->app->singleton(ImpersonationManager::class);
        $this->app->singleton(RedirectResolver::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(
            __DIR__ . '/../resources/views',
            'filament-impersonation'
        );

        $this->loadTranslationsFrom(
            __DIR__ . '/../resources/lang',
            'filament-impersonation'
        );

        if (config('filament-impersonation.routes.enabled', true)) {
            Route::middleware(config('filament-impersonation.routes.middleware', ['web', 'auth']))
                ->prefix(config('filament-impersonation.routes.prefix', 'impersonation'))
                ->name(config('filament-impersonation.routes.name', 'impersonation.'))
                ->group(__DIR__ . '/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/filament-impersonation.php' => config_path('filament-impersonation.php'),
            ], 'filament-impersonation-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/filament-impersonation'),
            ], 'filament-impersonation-views');

            $this->publishes([
                __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/filament-impersonation'),
            ], 'filament-impersonation-lang');
        }
    }
}
