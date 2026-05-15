<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Exceptions;

use RuntimeException;

class ProtectedUserCannotBeImpersonatedException extends RuntimeException
{
    public function __construct(string $message = 'The target user is protected and cannot be impersonated.')
    {
        parent::__construct($message);
    }
}
