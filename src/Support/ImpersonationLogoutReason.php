<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Support;

enum ImpersonationLogoutReason: string
{
    case OperatorNotFound        = 'operator_not_found';
    case OperatorNotRestorable   = 'operator_not_restorable';
    case UserModelNotResolvable  = 'user_model_not_resolvable';
    case ManualLogout            = 'manual_logout';
    case RestoreFailed           = 'restore_failed';
}
