<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation;

use Chuimi\FilamentImpersonation\Exceptions\CannotImpersonateSelfException;
use Chuimi\FilamentImpersonation\Exceptions\ImpersonationAlreadyActiveException;
use Chuimi\FilamentImpersonation\Exceptions\ImpersonationStartFailedException;
use Chuimi\FilamentImpersonation\Exceptions\PackageDisabledException;
use Chuimi\FilamentImpersonation\Exceptions\ProtectedUserCannotBeImpersonatedException;
use Chuimi\FilamentImpersonation\Exceptions\UnauthorizedImpersonationException;
use Chuimi\FilamentImpersonation\Support\ImpersonationActivity;
use Chuimi\FilamentImpersonation\Support\ImpersonationAuthorization;
use Chuimi\FilamentImpersonation\Support\ImpersonationLogoutReason;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

class ImpersonationManager
{
    public function __construct(
        private readonly ImpersonationActivity $activity,
        private readonly ImpersonationAuthorization $authorization,
    ) {}

    /**
     * Start impersonating $target as the currently authenticated operator.
     *
     * @throws PackageDisabledException
     * @throws ImpersonationAlreadyActiveException
     * @throws CannotImpersonateSelfException
     * @throws ProtectedUserCannotBeImpersonatedException
     * @throws UnauthorizedImpersonationException
     * @throws \InvalidArgumentException
     * @throws ImpersonationStartFailedException
     */
    public function start(Authenticatable $target, string $reason): void
    {
        if (!config('filament-impersonation.enabled')) {
            throw new PackageDisabledException();
        }

        $guard = $this->resolveGuard();

        /** @var Authenticatable|null $operator */
        $operator = Auth::guard($guard)->user();

        if (!$operator instanceof Authenticatable) {
            throw new \RuntimeException('No authenticated user found to perform impersonation.');
        }

        if ($this->isImpersonating()) {
            throw new ImpersonationAlreadyActiveException();
        }

        if (
            $operator->getAuthIdentifier() === $target->getAuthIdentifier()
            && $operator::class === $target::class
        ) {
            throw new CannotImpersonateSelfException();
        }

        if ($this->authorization->isProtectedUser($target)) {
            throw new ProtectedUserCannotBeImpersonatedException();
        }

        if (!$this->authorization->isAuthorized($operator, $target)) {
            throw new UnauthorizedImpersonationException();
        }

        $this->validateReason($reason);

        // Record the mandatory start activity BEFORE switching user.
        // If this throws, impersonation does not start.
        $activity = $this->activity->recordStart($operator, $target, $guard, $reason);

        $payload = [
            'operator_user_id'   => $operator->getAuthIdentifier(),
            'operator_user_type' => $operator::class,
            'operator_guard'     => $guard,

            'impersonated_user_id'   => $target->getAuthIdentifier(),
            'impersonated_user_type' => $target::class,
            'impersonated_guard'     => $guard,

            'impersonation_activity_id' => $activity->id,
            'started_at'               => now()->toISOString(),
        ];

        session()->put(config('filament-impersonation.session_key'), $payload);

        // Store the active guard in session before switching users.
        // Filament AuthenticateSession reads this key to resolve the guard on the next request.
        session(['guard' => $guard]);

        Auth::guard($guard)->login($target);

        // Sync the password hash for the new user immediately after login.
        // AuthenticateSession checks session('password_hash_{guard}') on every request.
        // SessionGuard::login() already calls session->migrate(true) internally,
        // so no additional session()->regenerate() is needed here.
        session()->put('password_hash_' . $guard, $target->getAuthPassword());
    }

    /**
     * Stop the active impersonation and restore the original operator.
     * Idempotent: returns false if no impersonation is active.
     */
    public function stop(): bool
    {
        $payload = $this->payload();

        if ($payload === null) {
            return false;
        }

        $sessionKey = config('filament-impersonation.session_key');
        $rawGuard   = $payload['operator_guard'] ?? null;
        $guard      = $this->isValidGuard($rawGuard) ? (string) $rawGuard : $this->resolveGuard();

        [$operator, $logoutReason] = $this->resolveOperatorForRestore($payload, $guard);

        if ($logoutReason !== null) {
            // Forced logout: operator could not be safely resolved or restored.
            try {
                $this->activity->recordStopByLogout($payload, $logoutReason, $operator);
            } catch (\Throwable $e) {
                report($e);
            }

            session()->forget($sessionKey);
            Auth::guard($guard)->logout();
            session()->invalidate();
            session()->regenerateToken();

            return true;
        }

        // Normal stop: attempt login first.
        // If login throws, fall back to safe logout without registering impersonation.stopped.
        try {
            Auth::guard($guard)->login($operator);
        } catch (\Throwable $e) {
            report($e);

            try {
                $this->activity->recordStopByLogout($payload, ImpersonationLogoutReason::RestoreFailed, $operator);
            } catch (\Throwable $e2) {
                report($e2);
            }

            session()->forget($sessionKey);
            Auth::guard($guard)->logout();
            session()->invalidate();
            session()->regenerateToken();

            return true;
        }

        // Login succeeded: sync guard and password hash for AuthenticateSession.
        // SessionGuard::login() already called session->migrate(true) internally,
        // so no additional session()->regenerate() is needed here.
        session(['guard' => $guard]);
        session()->put('password_hash_' . $guard, $operator->getAuthPassword());

        // Register the normal stop and finalize.
        try {
            $this->activity->recordStop($payload, $operator);
        } catch (\Throwable $e) {
            report($e);
        }

        session()->forget($sessionKey);

        return true;
    }

    /**
     * Record a forced stop caused by logout (e.g., from the Logout listener).
     * Does NOT attempt to restore the operator.
     * Idempotent: returns false if no impersonation is active.
     */
    public function stopForLogout(
        ImpersonationLogoutReason $reason = ImpersonationLogoutReason::ManualLogout,
    ): bool {
        $payload = $this->payload();

        if ($payload === null) {
            return false;
        }

        $sessionKey = config('filament-impersonation.session_key');

        try {
            $this->activity->recordStopByLogout($payload, $reason);
        } catch (\Throwable $e) {
            report($e);
        }

        session()->forget($sessionKey);

        return true;
    }

    /**
     * Return true if an impersonation session is currently active.
     */
    public function isImpersonating(): bool
    {
        return session()->has(config('filament-impersonation.session_key'));
    }

    /**
     * Return the current impersonation payload, or null if none is active.
     */
    public function payload(): ?array
    {
        $payload = session()->get(config('filament-impersonation.session_key'));

        if (!is_array($payload) || empty($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * Check whether the current operator can impersonate $target.
     * Returns false (no exception) for all normal non-authorization cases.
     */
    public function canImpersonate(Authenticatable $target): bool
    {
        if (!config('filament-impersonation.enabled')) {
            return false;
        }

        try {
            if ($this->isImpersonating()) {
                return false;
            }

            $guard    = $this->resolveGuard();
            $operator = Auth::guard($guard)->user();

            if (!$operator instanceof Authenticatable) {
                return false;
            }

            if (
                $operator->getAuthIdentifier() === $target->getAuthIdentifier()
                && $operator::class === $target::class
            ) {
                return false;
            }

            if ($this->authorization->isProtectedUser($target)) {
                return false;
            }

            return $this->authorization->isAuthorized($operator, $target);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Return true only when $guard is a non-empty string that exists in config('auth.guards').
     * Accepts mixed input because the value is read from an untrusted session payload.
     */
    private function isValidGuard(mixed $guard): bool
    {
        return is_string($guard)
            && $guard !== ''
            && config("auth.guards.{$guard}") !== null;
    }

    private function resolveGuard(): string
    {
        $configured = config('filament-impersonation.guard');

        if ($configured !== null) {
            return (string) $configured;
        }

        foreach (array_keys(config('auth.guards', [])) as $guardName) {
            if (Auth::guard($guardName)->check()) {
                return $guardName;
            }
        }

        return config('auth.defaults.guard', 'web');
    }

    /**
     * Resolve the Eloquent user model class for operator restoration.
     *
     * Resolution order:
     *   A. config('filament-impersonation.user_model') when non-null.
     *      If the class does not exist → null immediately (no fallback).
     *      An explicitly configured but invalid class is never silently bypassed.
     *   B. (only when user_model is null) Model from the guard's auth provider.
     *   C. (only when user_model is null) config('auth.providers.users.model').
     *   D. null → triggers UserModelNotResolvable forced logout.
     */
    private function resolveUserModelClass(string $guard): ?string
    {
        $configured = config('filament-impersonation.user_model');

        if ($configured !== null) {
            return class_exists((string) $configured) ? (string) $configured : null;
        }

        $provider = config("auth.guards.{$guard}.provider");
        if ($provider !== null) {
            $model = config("auth.providers.{$provider}.model");
            if ($model !== null && class_exists((string) $model)) {
                return (string) $model;
            }
        }

        $fallback = config('auth.providers.users.model');
        if ($fallback !== null && class_exists((string) $fallback)) {
            return (string) $fallback;
        }

        return null;
    }

    /**
     * Resolve the operator from the session payload, applying full validation.
     *
     * @return array{0: Authenticatable|null, 1: ImpersonationLogoutReason|null}
     *   Second element is null when the operator is ready to be restored normally.
     */
    private function resolveOperatorForRestore(array $payload, string $guard): array
    {
        $modelClass = $this->resolveUserModelClass($guard);

        if ($modelClass === null) {
            return [null, ImpersonationLogoutReason::UserModelNotResolvable];
        }

        $userId = $payload['operator_user_id'] ?? null;

        try {
            /** @var \Illuminate\Database\Eloquent\Model $instance */
            $instance = new $modelClass;
            $operator = $instance->newQuery()->find($userId);
        } catch (\Throwable) {
            return [null, ImpersonationLogoutReason::RestoreFailed];
        }

        if ($operator === null) {
            return [null, ImpersonationLogoutReason::OperatorNotFound];
        }

        try {
            if (!$this->isRestorableUser($operator)) {
                return [$operator, ImpersonationLogoutReason::OperatorNotRestorable];
            }
        } catch (\Throwable) {
            return [$operator, ImpersonationLogoutReason::RestoreFailed];
        }

        return [$operator, null];
    }

    private function isRestorableUser(Authenticatable $user): bool
    {
        $callback = config('filament-impersonation.is_restorable_user');

        if (is_callable($callback)) {
            return (bool) $callback($user);
        }

        return true;
    }

    private function validateReason(string $reason): void
    {
        $config    = config('filament-impersonation.reason', []);
        $required  = $config['required'] ?? true;
        $minLength = (int) ($config['min_length'] ?? 10);

        if (!$required) {
            return;
        }

        $trimmed = trim($reason);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('Impersonation reason is required.');
        }

        if (mb_strlen($trimmed) < $minLength) {
            throw new \InvalidArgumentException(
                "Impersonation reason must be at least {$minLength} characters."
            );
        }
    }
}
