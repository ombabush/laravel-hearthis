<?php

namespace Ombabush\Hearthis\Tests;

use Ombabush\Hearthis\HearthisServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HearthisServiceProvider::class];
    }
}
