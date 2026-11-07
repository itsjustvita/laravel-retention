<?php

declare(strict_types=1);

use ItsJustVita\Retention\Actions\Dependencies;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\CycleA;
use ItsJustVita\Retention\Tests\Fixtures\Models\CycleB;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

it('tells whether blockedBy() relations still hold records', function () {
    $customer = createCustomer();
    $blocked = createOrder($customer);
    $free = createOrder($customer);
    createInvoice($customer, ['order_id' => $blocked->id]);
    $policy = app(PolicyRegistry::class)->for(Order::class);

    expect(app(Dependencies::class)->blocksDeletion($blocked, $policy))->toBeTrue()
        ->and(app(Dependencies::class)->blocksDeletion($free, $policy))->toBeFalse();
});

it('excludes blocked records from a query, counting restricted and trashed children', function () {
    $customer = createCustomer();
    $free = createOrder($customer);
    $withInvoice = createOrder($customer);
    $withRestrictedInvoice = createOrder($customer);
    $withTrashedInvoice = createOrder($customer);
    createInvoice($customer, ['order_id' => $withInvoice->id]);
    createInvoice($customer, ['order_id' => $withRestrictedInvoice->id])->restrict();
    createInvoice($customer, ['order_id' => $withTrashedInvoice->id])->delete();

    $query = app(Dependencies::class)->excludeBlocked(Order::query(), app(PolicyRegistry::class)->for(Order::class));

    expect($query->pluck('id')->all())->toBe([$free->id]);
});

it('orders models so that blocking relations are processed first', function () {
    expect(app(Dependencies::class)->processingOrder([Order::class, Customer::class, Invoice::class, ServerLog::class]))
        ->toBe([Customer::class, Invoice::class, Order::class, ServerLog::class]);
});

it('ignores blocking relations to models outside the list', function () {
    expect(app(Dependencies::class)->processingOrder([Order::class, ServerLog::class]))
        ->toBe([Order::class, ServerLog::class]);
});

it('refuses to order models with cyclic blockedBy() relations', function () {
    app(Dependencies::class)->processingOrder([CycleA::class, CycleB::class]);
})->throws(InvalidRetentionPolicy::class, 'blockedBy() forms a cycle between ['.CycleA::class.', '.CycleB::class.']');
