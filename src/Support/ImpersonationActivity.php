<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Support;

use Carbon\Carbon;
use Chuimi\FilamentImpersonation\Exceptions\ImpersonationStartFailedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ImpersonationActivity
{
    public function __construct(
        private readonly Request $request,
    ) {}

    /**
     * Record impersonation.started.
     * Mandatory: throws ImpersonationStartFailedException on failure.
     */
    public function recordStart(
        Authenticatable $operator,
        Authenticatable $target,
        string $guard,
        string $reason,
    ): Activity {
        try {
            /** @var Activity $activity */
            $activity = activity(config('filament-impersonation.activity_log_name', 'impersonation'))
                ->causedBy($operator)
                ->withProperties([
                    'operator_user_id'     => $operator->getAuthIdentifier(),
                    'operator_user_type'   => $operator::class,
                    'operator_guard'       => $guard,
                    'impersonated_user_id'   => $target->getAuthIdentifier(),
                    'impersonated_user_type' => $target::class,
                    'impersonated_guard'     => $guard,
                    'reason'     => $reason,
                    'started_at' => now()->toISOString(),
                    'ip_address' => $this->request->ip(),
                    'user_agent' => $this->request->userAgent(),
                ])
                ->log('impersonation.started');

            return $activity;
        } catch (\Throwable $e) {
            throw new ImpersonationStartFailedException(
                'Failed to record impersonation start activity: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Record impersonation.stopped (normal exit, operator restored).
     * Non-blocking: caller must catch exceptions if needed.
     */
    public function recordStop(array $payload, ?Authenticatable $operator = null): void
    {
        $stoppedAt  = now();
        $startedAt  = Carbon::parse($payload['started_at']);
        $durationSeconds = (int) $startedAt->diffInSeconds($stoppedAt);

        $logger = activity(config('filament-impersonation.activity_log_name', 'impersonation'));

        if ($operator !== null) {
            $logger->causedBy($operator);
        }

        $logger->withProperties([
            'operator_user_id'     => $payload['operator_user_id'] ?? null,
            'operator_user_type'   => $payload['operator_user_type'] ?? null,
            'operator_guard'       => $payload['operator_guard'] ?? null,
            'impersonated_user_id'   => $payload['impersonated_user_id'] ?? null,
            'impersonated_user_type' => $payload['impersonated_user_type'] ?? null,
            'impersonated_guard'     => $payload['impersonated_guard'] ?? null,
            'impersonation_activity_id' => $payload['impersonation_activity_id'] ?? null,
            'started_at'      => $payload['started_at'] ?? null,
            'stopped_at'      => $stoppedAt->toISOString(),
            'duration_seconds' => $durationSeconds,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ])->log('impersonation.stopped');
    }

    /**
     * Record impersonation.stopped_by_logout (forced exit).
     * Non-blocking: caller must catch exceptions if needed.
     */
    public function recordStopByLogout(
        array $payload,
        ImpersonationLogoutReason $reason,
        ?Authenticatable $operator = null,
    ): void {
        $stoppedAt  = now();
        $startedAt  = isset($payload['started_at']) ? Carbon::parse($payload['started_at']) : $stoppedAt;
        $durationSeconds = (int) $startedAt->diffInSeconds($stoppedAt);

        $logger = activity(config('filament-impersonation.activity_log_name', 'impersonation'));

        if ($operator !== null) {
            $logger->causedBy($operator);
        }

        $logger->withProperties([
            'operator_user_id'     => $payload['operator_user_id'] ?? null,
            'operator_user_type'   => $payload['operator_user_type'] ?? null,
            'operator_guard'       => $payload['operator_guard'] ?? null,
            'impersonated_user_id'   => $payload['impersonated_user_id'] ?? null,
            'impersonated_user_type' => $payload['impersonated_user_type'] ?? null,
            'impersonated_guard'     => $payload['impersonated_guard'] ?? null,
            'impersonation_activity_id' => $payload['impersonation_activity_id'] ?? null,
            'started_at'      => $payload['started_at'] ?? null,
            'stopped_at'      => $stoppedAt->toISOString(),
            'duration_seconds' => $durationSeconds,
            'ip_address'    => $this->request->ip(),
            'user_agent'    => $this->request->userAgent(),
            'logout_reason' => $reason->value,
        ])->log('impersonation.stopped_by_logout');
    }
}
