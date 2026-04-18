<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Tests;

use Nordkit\Wiretap\Laravel\WiretapServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [WiretapServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
