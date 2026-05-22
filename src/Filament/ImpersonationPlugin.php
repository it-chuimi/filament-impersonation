<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Filament;

use Chuimi\FilamentImpersonation\ImpersonationManager;
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
            function (): View {
                $manager = app(ImpersonationManager::class);
                $payload = $manager->payload();

                $impersonatorName = null;
                $impersonatedName = null;

                if ($payload !== null) {
                    $impersonatorName = $this->resolveUserDisplayName(
                        $payload['operator_user_type'] ?? null,
                        $payload['operator_user_id'] ?? null,
                    );
                    $impersonatedName = $this->resolveUserDisplayName(
                        $payload['impersonated_user_type'] ?? null,
                        $payload['impersonated_user_id'] ?? null,
                    );
                }

                return view('filament-impersonation::banner', [
                    'impersonatorName' => $impersonatorName,
                    'impersonatedName' => $impersonatedName,
                ]);
            },
        );
    }

    public function boot(Panel $panel): void
    {
    }

    private function resolveUserDisplayName(mixed $type, mixed $id): ?string
    {
        try {
            if (!is_string($type) || $type === '' || $id === null) {
                return null;
            }

            if (!class_exists($type)) {
                return null;
            }

            $instance = new $type;
            $user     = $instance->newQuery()->find($id);

            if ($user === null) {
                return null;
            }

            if (isset($user->name) && is_string($user->name) && $user->name !== '') {
                return $user->name;
            }

            if (isset($user->email) && is_string($user->email) && $user->email !== '') {
                return $user->email;
            }

            $identifier = $user->getAuthIdentifier();

            return $identifier !== null ? (string) $identifier : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
