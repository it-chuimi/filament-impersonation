<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Listeners;

use Chuimi\FilamentImpersonation\ImpersonationManager;
use Chuimi\FilamentImpersonation\Support\ImpersonationLogoutReason;
use Illuminate\Auth\Events\Logout;

class HandleImpersonationLogout
{
    public function __construct(
        private readonly ImpersonationManager $manager,
    ) {}

    public function handle(Logout $event): void
    {
        if (!$this->manager->isImpersonating()) {
            return;
        }

        try {
            $this->manager->stopForLogout(ImpersonationLogoutReason::ManualLogout);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
