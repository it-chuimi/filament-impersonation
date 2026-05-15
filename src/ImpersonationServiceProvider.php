<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation;

use Chuimi\FilamentImpersonation\Support\ImpersonationActivity;
use Chuimi\FilamentImpersonation\Support\ImpersonationAuthorization;
use Chuimi\FilamentImpersonation\Support\RedirectResolver;
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
