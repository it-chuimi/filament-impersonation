<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Exceptions;

use RuntimeException;
use Throwable;

class ImpersonationStartFailedException extends RuntimeException
{
    public function __construct(
        string $message = 'Failed to record the impersonation start activity. Impersonation was not started.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
