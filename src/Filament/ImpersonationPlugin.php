<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;

class ImpersonationPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filament-impersonation';
    }

    public function register(Panel $panel): void
    {
        $panel->renderHook(
            PanelsRenderHook::BODY_START,
            fn (): View => view('filament-impersonation::banner'),
        );
    }

    public function boot(Panel $panel): void
    {
    }
}
