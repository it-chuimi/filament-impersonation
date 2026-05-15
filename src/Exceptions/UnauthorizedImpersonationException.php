<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Exceptions;

use RuntimeException;

class UnauthorizedImpersonationException extends RuntimeException
{
    public function __construct(string $message = 'The current user is not authorized to perform impersonation.')
    {
        parent::__construct($message);
    }
}
