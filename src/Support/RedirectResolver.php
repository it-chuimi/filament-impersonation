<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedirectResolver
{
    public function __construct(
        private readonly Request $request,
    ) {}

    /**
     * Resolve the redirect destination after a successful impersonation start.
     *
     * @return string|RedirectResponse
     */
    public function afterStart(
        ?array $payload = null,
        ?Authenticatable $operator = null,
        ?Authenticatable $impersonated = null,
    ): string|RedirectResponse {
        return $this->resolve(
            config('filament-impersonation.redirect_after_start'),
            [
                'phase'        => 'start',
                'operator'     => $operator,
                'impersonated' => $impersonated,
                'payload'      => $payload,
                'request'      => $this->request,
            ],
        );
    }

    /**
     * Resolve the redirect destination after impersonation stops.
     *
     * @return string|RedirectResponse
     */
    public function afterStop(
        ?array $payload = null,
        ?Authenticatable $operator = null,
        ?Authenticatable $impersonated = null,
    ): string|RedirectResponse {
        return $this->resolve(
            config('filament-impersonation.redirect_after_stop'),
            [
                'phase'        => 'stop',
                'operator'     => $operator,
                'impersonated' => $impersonated,
                'payload'      => $payload,
                'request'      => $this->request,
            ],
        );
    }

    /**
     * Resolve a redirect config value to a usable destination.
     *
     * Accepts:
     *   - callable($context): string|RedirectResponse|null
     *   - string (absolute URL, path starting with '/', or named route)
     *   - null → safe fallback
     *
     * Callable exceptions are NOT caught — misconfigured callables must be
     * visible to the consuming application.
     *
     * @return string|RedirectResponse
     */
    public function resolve(mixed $config, array $context = []): string|RedirectResponse
    {
        if (is_callable($config)) {
            $result = $config($context);

            if ($result instanceof RedirectResponse) {
                return $result;
            }

            if (is_string($result)) {
                return $result;
            }

            return $this->fallback();
        }

        if (is_string($config)) {
            return $this->resolveString($config);
        }

        return $this->fallback();
    }

    private function resolveString(string $value): string
    {
        if (
            str_starts_with($value, 'http://')
            || str_starts_with($value, 'https://')
            || str_starts_with($value, '/')
        ) {
            return $value;
        }

        try {
            return route($value);
        } catch (\Throwable) {
            return '/';
        }
    }

    private function fallback(): string
    {
        return url()->previous('/');
    }
}
