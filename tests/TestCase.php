<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Tests;

use Chuimi\FilamentImpersonation\ImpersonationServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ImpersonationServiceProvider::class,
        ];
    }
}
