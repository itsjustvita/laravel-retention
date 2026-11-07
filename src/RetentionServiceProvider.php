<?php

declare(strict_types=1);

namespace ItsJustVita\Retention;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use ItsJustVita\Retention\Console\AuditCommand;
use ItsJustVita\Retention\Console\DueCommand;
use ItsJustVita\Retention\Console\GenerateCommand;
use ItsJustVita\Retention\Console\RunCommand;
use ItsJustVita\Retention\Database\RetentionColumns;
use ItsJustVita\Retention\Document\GitInfo;
use ItsJustVita\Retention\Events\RecordAnonymized;
use ItsJustVita\Retention\Events\RecordDeleted;
use ItsJustVita\Retention\Events\RecordRestricted;
use ItsJustVita\Retention\Events\TableRowsDeleted;
use ItsJustVita\Retention\Execution\StartColumn;
use ItsJustVita\Retention\Log\LogHasher;
use ItsJustVita\Retention\Log\WriteRetentionLog;
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
        $this->app->singleton(LogHasher::class);
        $this->app->bind(GitInfo::class, static fn (Application $app): GitInfo => new GitInfo($app->basePath()));
    }

    public function boot(): void
    {
        RetentionColumns::registerMacros();

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'retention');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'retention');

        $this->app->make(Dispatcher::class)->listen(
            [RecordDeleted::class, RecordAnonymized::class, RecordRestricted::class, TableRowsDeleted::class],
            WriteRetentionLog::class,
        );

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/retention.php' => config_path('retention.php'),
            ], 'retention-config');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/retention'),
            ], 'retention-translations');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/retention'),
            ], 'retention-views');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'retention-migrations');

            $this->commands([
                RunCommand::class,
                DueCommand::class,
                AuditCommand::class,
                GenerateCommand::class,
            ]);
        }
    }
}
