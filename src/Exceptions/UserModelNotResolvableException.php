<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Exceptions;

use RuntimeException;

class UserModelNotResolvableException extends RuntimeException
{
    public function __construct(string $message = 'The user model could not be resolved from the auth configuration.')
    {
        parent::__construct($message);
    }
}
