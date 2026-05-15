<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Support;

use Illuminate\Contracts\Auth\Authenticatable;

class ImpersonationAuthorization
{
    /**
     * Check whether the operator is authorized to impersonate the target.
     * Does NOT check self-impersonation, active session, or enabled flag —
     * those are the manager's responsibility.
     */
    public function isAuthorized(Authenticatable $operator, Authenticatable $target): bool
    {
        $callback = config('filament-impersonation.can_impersonate');

        if (is_callable($callback)) {
            return (bool) $callback($operator, $target);
        }

        return $this->checkRolesAndPermissions($operator);
    }

    /**
     * Check whether the target user is protected and must not be impersonated.
     */
    public function isProtectedUser(Authenticatable $target): bool
    {
        $protectedRoles = config('filament-impersonation.protected_roles', []);

        if (!empty($protectedRoles)) {
            if (!method_exists($target, 'hasAnyRole')) {
                return true;
            }

            if ($target->hasAnyRole($protectedRoles)) {
                return true;
            }
        }

        $callback = config('filament-impersonation.is_protected_user');

        if (is_callable($callback)) {
            return (bool) $callback($target);
        }

        return false;
    }

    private function checkRolesAndPermissions(Authenticatable $operator): bool
    {
        $roles       = config('filament-impersonation.operator_roles', []);
        $permissions = config('filament-impersonation.operator_permissions', []);

        if (!empty($roles)) {
            if (!method_exists($operator, 'hasAnyRole')) {
                return false;
            }

            if (!$operator->hasAnyRole($roles)) {
                return false;
            }
        }

        if (!empty($permissions)) {
            if (!method_exists($operator, 'can')) {
                return false;
            }

            foreach ($permissions as $permission) {
                if (!$operator->can($permission)) {
                    return false;
                }
            }
        }

        return true;
    }
}
