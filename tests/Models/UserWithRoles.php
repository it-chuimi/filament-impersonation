<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Tests\Models;

class UserWithRoles extends User
{
    /** Roles assigned in-memory for test purposes. */
    public array $forcedRoles = [];

    /** Permissions assigned in-memory for test purposes. */
    public array $forcedPermissions = [];

    public function hasAnyRole(mixed $roles): bool
    {
        foreach ((array) $roles as $role) {
            if (in_array($role, $this->forcedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->forcedRoles, true);
    }

    /**
     * Override Laravel's Gate-backed can() with an in-memory permission check.
     *
     * @param  iterable|string  $abilities
     * @param  mixed            $arguments
     */
    public function can($abilities, $arguments = []): bool
    {
        foreach ((array) $abilities as $ability) {
            if (in_array($ability, $this->forcedPermissions, true)) {
                return true;
            }
        }

        return false;
    }
}
