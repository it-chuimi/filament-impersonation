<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Concerns;

use Chuimi\FilamentImpersonation\ImpersonationManager;
use Spatie\Activitylog\Models\Activity;

trait HasImpersonationActivityContext
{
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $payload = app(ImpersonationManager::class)->payload();

        if ($payload === null) {
            return;
        }

        $extra = [];

        if (array_key_exists('impersonation_activity_id', $payload)) {
            $extra['impersonation_activity_id'] = $payload['impersonation_activity_id'];
        }
        if (array_key_exists('operator_user_id', $payload)) {
            $extra['operator_user_id'] = $payload['operator_user_id'];
        }
        if (array_key_exists('impersonated_user_id', $payload)) {
            $extra['impersonated_user_id'] = $payload['impersonated_user_id'];
        }
        if (array_key_exists('operator_user_type', $payload)) {
            $extra['operator_user_type'] = $payload['operator_user_type'];
        }
        if (array_key_exists('impersonated_user_type', $payload)) {
            $extra['impersonated_user_type'] = $payload['impersonated_user_type'];
        }

        if ($extra !== []) {
            $activity->properties = $activity->properties->merge($extra);
        }

        // Set causer to the real operator only when both fields are present.
        if (isset($payload['operator_user_id'], $payload['operator_user_type'])) {
            $activity->causer_type = $payload['operator_user_type'];
            $activity->causer_id   = $payload['operator_user_id'];
        }
    }
}
