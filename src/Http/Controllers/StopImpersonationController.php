<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Http\Controllers;

use Chuimi\FilamentImpersonation\ImpersonationManager;
use Chuimi\FilamentImpersonation\Support\RedirectResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StopImpersonationController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $manager  = app(ImpersonationManager::class);
        $resolver = app(RedirectResolver::class);

        // Payload is captured before stop() clears it from session.
        $payload = $manager->payload();

        // Auth::user() before stop() is the impersonated user (or null if no impersonation).
        $impersonated = Auth::user();

        $manager->stop();

        // Auth::user() after stop() is the restored operator, or null on forced logout.
        $operator = Auth::user();

        $url = $resolver->afterStop(
            payload:      $payload,
            operator:     $operator instanceof Authenticatable ? $operator : null,
            impersonated: $impersonated instanceof Authenticatable ? $impersonated : null,
        );

        if ($url instanceof RedirectResponse) {
            return $url;
        }

        return redirect($url);
    }
}
