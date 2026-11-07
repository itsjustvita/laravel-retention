<?php

declare(strict_types=1);

namespace ItsJustVita\Retention;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use ItsJustVita\Retention\Console\DueCommand;
use ItsJustVita\Retention\Console\RunCommand;
use ItsJustVita\Retention\Database\RetentionColumns;
use ItsJustVita\Retention\Execution\StartColumn;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Policies\PolicyValidator;
use ItsJustVita\Retention\Rules\RuleCatalogue;
use ItsJustVita\Retention\Rules\ShippedRules;

class RetentionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/retention.php', 'retention');

        $this->app->singleton(RuleCatalogue::class, static function (Application $app): RuleCatalogue {
            $overrides = $app->make(Repository::class)->get('retention.rules') ?? [];

            if (! is_array($overrides)) {
                throw new InvalidArgumentException('[retention.rules] must be an array of rule definitions keyed by rule key.');
            }

            return RuleCatalogue::fromDefinitions(ShippedRules::definitions(), $overrides);
        });

        $this->app->singleton(RetentionCalculator::class, static function (Application $app): RetentionCalculator {
            $config = $app->make(Repository::class);
            $timezone = $config->get('retention.timezone');

            if ($timezone === null || $timezone === '') {
                $timezone = $config->get('app.timezone') ?? 'UTC';
            }

            if (! is_string($timezone)) {
                throw new InvalidArgumentException('[retention.timezone] must be a timezone name such as Europe/Berlin.');
            }

            return new RetentionCalculator($timezone);
        });

        $this->app->singleton(PolicyRegistry::class);
        $this->app->singleton(PolicyValidator::class);
        $this->app->singleton(StartColumn::class);
    }

    public function boot(): void
    {
        RetentionColumns::registerMacros();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/retention.php' => config_path('retention.php'),
            ], 'retention-config');

            $this->commands([
                RunCommand::class,
                DueCommand::class,
            ]);
        }
    }
}
