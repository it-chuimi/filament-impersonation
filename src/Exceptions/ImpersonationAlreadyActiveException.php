<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Exceptions;

use RuntimeException;

class ImpersonationAlreadyActiveException extends RuntimeException
{
    public function __construct(string $message = 'An impersonation session is already active. Stop it before starting a new one.')
    {
        parent::__construct($message);
    }
}
