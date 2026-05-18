<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Tests;

class RoutesDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Set the full routes sub-array so mergeConfigFrom's shallow merge
        // does not discard the other sub-keys (middleware, prefix, name).
        $app['config']->set('filament-impersonation.routes', [
            'enabled'    => false,
            'middleware' => ['web', 'auth'],
            'prefix'     => 'impersonation',
            'name'       => 'impersonation.',
        ]);
    }
}
