<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use ItsJustVita\Retention\RetentionServiceProvider;
use ItsJustVita\Retention\Rules\RuleCatalogue;

it('merges the package config', function () {
    expect(config('retention'))
        ->toBeArray()
        ->toHaveKeys(['timezone', 'rules']);
});

it('publishes the config file under the retention-config tag', function () {
    $paths = ServiceProvider::pathsToPublish(RetentionServiceProvider::class, 'retention-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/retention.php')
        ->and(reset($paths))->toBe(config_path('retention.php'));
});

it('registers the rule catalogue and the calculator as singletons', function () {
    expect(app(RuleCatalogue::class))->toBe(app(RuleCatalogue::class))
        ->and(app(RetentionCalculator::class))->toBe(app(RetentionCalculator::class));
});

it('calculates in the configured retention timezone', function () {
    config(['retention.timezone' => 'Europe/Berlin', 'app.timezone' => 'UTC']);

    expect(app(RetentionCalculator::class)->timezone())->toBe('Europe/Berlin');
});

it('falls back to the application timezone', function (?string $retentionTimezone) {
    config(['retention.timezone' => $retentionTimezone, 'app.timezone' => 'America/New_York']);

    expect(app(RetentionCalculator::class)->timezone())->toBe('America/New_York');
})->with(['not set' => null, 'empty' => '']);
