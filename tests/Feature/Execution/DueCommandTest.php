<?php

declare(strict_types=1);

use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

beforeEach(function () {
    config([
        'retention.timezone' => 'Europe/Berlin',
        'retention.models.paths' => [],
        'retention.models.additional' => [Customer::class, Order::class, Invoice::class, ServerLog::class],
    ]);

    $this->travelTo(at('2020-06-01 10:00'));
    $customer = createCustomer(['contract_ended_at' => now()]);              // due since 2024-01-01
    createCustomer(['contract_ended_at' => '2022-12-15 10:00:00']);          // due from 2026-01-01
    $order = createOrder($customer, ['completed_at' => now()]);              // due, but blocked by its invoice
    createInvoice($customer, ['order_id' => $order->id, 'issued_at' => '2020-06-01'])->restrict(); // due from 2029
    $this->travelTo(at('2025-12-10'));
});

it('shows due, blocked, upcoming and restricted records per model', function () {
    $this->artisan('retention:due')
        ->expectsTable(['Category', 'Model', 'Due now', 'Blocked', 'On hold', 'Due within 30 days', 'Restricted'], [
            ['customer_master_data', Customer::class, '1', '0', '0', '1', '0'],
            ['invoices', Invoice::class, '0', '0', '0', '0', '1'],
            ['orders', Order::class, '0', '1', '0', '0', '0'],
            ['server_logs', ServerLog::class, '0', '0', '0', '0', '–'],
        ])
        ->assertSuccessful();
});

it('uses the given forecast horizon', function () {
    $this->artisan('retention:due', ['--days' => 10, '--model' => ['Customer']])
        ->expectsTable(['Category', 'Model', 'Due now', 'Blocked', 'On hold', 'Due within 10 days', 'Restricted'], [
            ['customer_master_data', Customer::class, '1', '0', '0', '0', '0'],
        ])
        ->assertSuccessful();
});

it('refuses to report while a policy is invalid', function () {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('probe', 'Probe')->rule('no_such_rule');
    config(['retention.models.additional' => [PolicyProbe::class]]);

    $this->artisan('retention:due')
        ->expectsOutputToContain('unknown retention rule [no_such_rule].')
        ->assertFailed();
});
