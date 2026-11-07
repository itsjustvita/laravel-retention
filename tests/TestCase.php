<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests;

use Illuminate\Foundation\Application;
use ItsJustVita\Retention\RetentionServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RetentionServiceProvider::class];
    }
}
