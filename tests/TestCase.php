<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
    }

    /**
     * Freezes time in the application timezone, like now() in a real
     * application. Older Carbon versions would otherwise read datetime columns
     * in the timezone of the frozen instant.
     *
     * @param  mixed  $date
     * @param  callable|null  $callback
     * @return mixed
     */
    public function travelTo($date, $callback = null)
    {
        return parent::travelTo(
            $date instanceof CarbonInterface ? CarbonImmutable::instance($date)->setTimezone(date_default_timezone_get()) : $date,
            $callback,
        );
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
