<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Execution\DueRecords;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

beforeEach(function () {
    config(['retention.timezone' => 'Europe/Berlin']); // the application itself runs in UTC
});

/**
 * @param  class-string<Model>  $model
 * @return list<int>
 */
function dueIds(string $model, string $now, bool $excludeBlocked = true): array
{
    return app(DueRecords::class)->query($model, at($now), $excludeBlocked)->orderBy('id')->pluck('id')->all();
}

it('selects date columns by calendar year in the retention timezone', function () {
    $customer = createCustomer();
    $lastDay = createInvoice($customer, ['issued_at' => '2025-12-31']);
    createInvoice($customer, ['issued_at' => '2026-01-01']);

    expect(dueIds(Invoice::class, '2033-12-31 23:59:59'))->toBe([])
        ->and(dueIds(Invoice::class, '2034-01-01 00:00:00'))->toBe([$lastDay->id]);
});

it('converts datetime columns from the application timezone', function () {
    // 23:30 UTC on 31 December is already 1 January in Berlin, so the period counts from the end of 2026.
    $customer = createCustomer(['contract_ended_at' => '2025-12-31 23:30:00']);

    expect(dueIds(Customer::class, '2029-01-01 00:00:00'))->toBe([])
        ->and(dueIds(Customer::class, '2029-12-31 23:59:59'))->toBe([])
        ->and(dueIds(Customer::class, '2030-01-01 00:00:00'))->toBe([$customer->id]);
});

it('selects time-based periods to the second', function () {
    config(['retention.rules' => ['server_logs' => ['period' => 'PT72H']]]);
    // 12:00 Berlin = 11:00 UTC; 72 real hours later it is 13:00 Berlin summer time.
    $log = ServerLog::create(['ip_address' => '192.0.2.1', 'logged_at' => '2025-03-29 11:00:00']);

    expect(dueIds(ServerLog::class, '2025-04-01 12:59:59'))->toBe([])
        ->and(dueIds(ServerLog::class, '2025-04-01 13:00:00'))->toBe([$log->id]);
});

it('skips records whose period has not started', function () {
    createCustomer(['contract_ended_at' => null]);

    expect(dueIds(Customer::class, '2040-01-01'))->toBe([]);
});

it('includes restricted and soft-deleted records', function () {
    $customer = createCustomer();
    $restricted = createInvoice($customer, ['issued_at' => '2020-05-01']);
    $restricted->restrict();
    $trashed = createInvoice($customer, ['issued_at' => '2020-06-01']);
    $trashed->delete();

    expect(dueIds(Invoice::class, '2030-01-01'))->toBe([$restricted->id, $trashed->id]);
});

it('skips records that are already anonymised', function () {
    createCustomer(['contract_ended_at' => '2020-06-01', 'anonymized_at' => '2024-01-01 00:00:00']);
    $pending = createCustomer(['contract_ended_at' => '2020-06-01']);

    expect(dueIds(Customer::class, '2025-01-01'))->toBe([$pending->id]);
});

it('skips records blocked by blockedBy() unless asked to include them', function () {
    $customer = createCustomer();
    $blocked = createOrder($customer, ['completed_at' => '2020-06-01 10:00:00']);
    $free = createOrder($customer, ['completed_at' => '2020-06-01 10:00:00']);
    createInvoice($customer, ['order_id' => $blocked->id]);

    expect(dueIds(Order::class, '2025-01-01'))->toBe([$free->id])
        ->and(dueIds(Order::class, '2025-01-01', excludeBlocked: false))->toBe([$blocked->id, $free->id]);
});

it('agrees with the calculator for individual records', function () {
    $customer = createCustomer(['contract_ended_at' => '2025-12-31 23:30:00']);
    $invoice = createInvoice($customer, ['issued_at' => '2025-12-31']);
    $records = app(DueRecords::class);

    expect($records->isDue($customer, at('2029-12-31 23:59:59')))->toBeFalse()
        ->and($records->isDue($customer, at('2030-01-01 00:00:00')))->toBeTrue()
        ->and($records->isDue($invoice, at('2033-12-31 23:59:59')))->toBeFalse()
        ->and($records->isDue($invoice, at('2034-01-01 00:00:00')))->toBeTrue()
        ->and($records->isDue(createCustomer(['contract_ended_at' => null]), at('2040-01-01')))->toBeFalse();
});

it('reads the start event of a record', function () {
    $invoice = createInvoice(createCustomer(), ['issued_at' => '2025-12-31']);

    expect(app(DueRecords::class)->startEvent($invoice)?->toIso8601String())->toBe('2025-12-31T00:00:00+01:00');
});
