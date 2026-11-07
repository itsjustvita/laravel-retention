<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use ItsJustVita\Retention\RetentionServiceProvider;

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
