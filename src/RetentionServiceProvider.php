<?php

declare(strict_types=1);

namespace ItsJustVita\Retention;

use Illuminate\Support\ServiceProvider;

class RetentionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/retention.php', 'retention');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/retention.php' => config_path('retention.php'),
            ], 'retention-config');
        }
    }
}
