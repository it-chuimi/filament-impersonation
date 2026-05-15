<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Tests\Models;

/**
 * Test-only model that throws on getAuthIdentifier().
 * Used to simulate a login() failure during stop() restoration without Mockery.
 *
 * Instances can be stored in and loaded from the users table normally.
 * The failure is triggered only when Auth::guard()->login() calls getAuthIdentifier().
 */
class UserWithBrokenLogin extends User
{
    public function getAuthIdentifier(): mixed
    {
        throw new \RuntimeException('Simulated login failure: getAuthIdentifier threw during restore.');
    }
}
