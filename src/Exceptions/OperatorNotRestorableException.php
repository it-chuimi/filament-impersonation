<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Exceptions;

use RuntimeException;

class OperatorNotRestorableException extends RuntimeException
{
    public function __construct(string $message = 'The original operator cannot be restored. The session will be terminated.')
    {
        parent::__construct($message);
    }
}
