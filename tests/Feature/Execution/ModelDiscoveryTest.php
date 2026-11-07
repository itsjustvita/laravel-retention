<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use ItsJustVita\Retention\Execution\ModelDiscovery;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\CycleA;
use ItsJustVita\Retention\Tests\Fixtures\Models\CycleB;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\RestrictableProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

it('finds the Eloquent models in the configured paths, sorted by class name', function () {
    // tests/Fixtures also holds a non-model class and an anonymous migration class.
    config(['retention.models.paths' => [dirname(__DIR__, 2).'/Fixtures'], 'retention.models.additional' => []]);

    expect(app(ModelDiscovery::class)->models())->toBe([
        Customer::class,
        CycleA::class,
        CycleB::class,
        Invoice::class,
        Order::class,
        PolicyProbe::class,
        RestrictableProbe::class,
        ServerLog::class,
    ]);
});

it('adds models listed explicitly and skips missing paths', function () {
    config([
        'retention.models.paths' => ['/does/not/exist'],
        'retention.models.additional' => [Order::class, Customer::class, Order::class],
    ]);

    expect(app(ModelDiscovery::class)->models())->toBe([Customer::class, Order::class]);
});

it('rejects additional entries that are not Eloquent models', function () {
    config(['retention.models.paths' => [], 'retention.models.additional' => [stdClass::class]]);

    app(ModelDiscovery::class)->models();
})->throws(InvalidArgumentException::class, '[retention.models.additional] contains [stdClass], which is not an Eloquent model.');

it('lists the models that use HasRetention', function () {
    config([
        'retention.models.paths' => [],
        'retention.models.additional' => [Customer::class, User::class],
    ]);

    expect(app(ModelDiscovery::class)->retainedModels())->toBe([Customer::class]);
});
