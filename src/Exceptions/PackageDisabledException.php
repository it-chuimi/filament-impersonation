<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Exceptions;

use RuntimeException;

class PackageDisabledException extends RuntimeException
{
    public function __construct(string $message = 'Impersonation is disabled in the package configuration.')
    {
        parent::__construct($message);
    }
}
