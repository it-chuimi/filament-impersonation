<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Exceptions;

use RuntimeException;

class CannotImpersonateSelfException extends RuntimeException
{
    public function __construct(string $message = 'A user cannot impersonate themselves.')
    {
        parent::__construct($message);
    }
}
