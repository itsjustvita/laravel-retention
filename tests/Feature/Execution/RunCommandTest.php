<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ItsJustVita\Retention\Events\RunCompleted;
use ItsJustVita\Retention\Execution\DueRecords;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;
use ItsJustVita\Retention\Tests\Fixtures\NeverDueRecords;

beforeEach(function () {
    config([
        'retention.timezone' => 'Europe/Berlin',
        'retention.models.paths' => [],
        'retention.models.additional' => [Customer::class, Order::class, Invoice::class, ServerLog::class],
    ]);
});

const RUN_HEADERS = ['Category', 'Model', 'Deleted', 'Anonymised', 'Blocked', 'Skipped'];

it('deletes and anonymises the due records and leaves the rest alone', function () {
    $this->travelTo(at('2020-06-01 10:00'));
    $former = createCustomer(['name' => 'Former', 'contract_ended_at' => now()]);
    $active = createCustomer(['name' => 'Active']);
    createInvoice($active, ['issued_at' => '2015-06-01']);
    $newInvoice = createInvoice($active, ['issued_at' => '2020-06-01']);
    ServerLog::create(['ip_address' => '192.0.2.1', 'logged_at' => now()]);

    $this->travelTo(at('2026-03-01 04:00'));
    ServerLog::create(['ip_address' => '192.0.2.2', 'logged_at' => now()]);

    $this->artisan('retention:run')
        ->expectsTable(RUN_HEADERS, [
            ['customer_master_data', Customer::class, '0', '1', '0', '0'],
            ['invoices', Invoice::class, '1', '0', '0', '0'],
            ['orders', Order::class, '0', '0', '0', '0'],
            ['server_logs', ServerLog::class, '1', '0', '0', '0'],
        ])
        ->assertSuccessful();

    expect(DB::table('customers')->where('id', $former->id)->value('name'))->toBe('deleted')
        ->and(DB::table('customers')->where('id', $active->id)->value('name'))->toBe('Active')
        ->and(DB::table('invoices')->pluck('id')->all())->toBe([$newInvoice->id])
        ->and(DB::table('server_logs')->pluck('ip_address')->all())->toBe(['192.0.2.2']);
});

it('processes children before their parents in the same run', function () {
    $this->travelTo(at('2020-06-01 10:00'));
    $customer = createCustomer();
    $order = createOrder($customer, ['completed_at' => now()]);
    createInvoice($customer, ['order_id' => $order->id, 'issued_at' => '2020-06-01']);

    $this->travelTo(at('2026-01-01'));
    $this->artisan('retention:run', ['--model' => ['Order', 'Invoice']])
        ->expectsTable(RUN_HEADERS, [
            ['invoices', Invoice::class, '0', '0', '0', '0'],
            ['orders', Order::class, '0', '0', '1', '0'],
        ])
        ->assertSuccessful();

    expect(DB::table('orders')->count())->toBe(1);

    $this->travelTo(at('2029-01-01'));
    $this->artisan('retention:run', ['--model' => ['Order', 'Invoice']])
        ->expectsTable(RUN_HEADERS, [
            ['invoices', Invoice::class, '1', '0', '0', '0'],
            ['orders', Order::class, '1', '0', '0', '0'],
        ])
        ->assertSuccessful();

    expect(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('invoices')->count())->toBe(0);
});

it('anonymises a record only once across runs', function () {
    createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $this->travelTo(at('2026-01-01'));

    $this->artisan('retention:run', ['--model' => [Customer::class]])->assertSuccessful();

    $this->artisan('retention:run', ['--model' => [Customer::class]])
        ->expectsTable(RUN_HEADERS, [['customer_master_data', Customer::class, '0', '0', '0', '0']])
        ->assertSuccessful();
});

it('processes all records across chunks', function () {
    $this->travelTo(at('2025-01-01'));
    foreach (range(1, 7) as $i) {
        ServerLog::create(['ip_address' => "192.0.2.{$i}", 'logged_at' => now()]);
    }

    $this->travelTo(at('2025-02-01'));
    $this->artisan('retention:run', ['--model' => ['ServerLog'], '--chunk' => 2])
        ->expectsTable(RUN_HEADERS, [['server_logs', ServerLog::class, '7', '0', '0', '0']])
        ->assertSuccessful();

    expect(DB::table('server_logs')->count())->toBe(0);
});

it('changes nothing in a dry run', function () {
    createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $customer = createCustomer();
    $order = createOrder($customer, ['completed_at' => '2020-06-01 10:00:00']);
    createInvoice($customer, ['order_id' => $order->id, 'issued_at' => '2025-01-01']);
    $this->travelTo(at('2026-01-01'));

    $this->artisan('retention:run', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->expectsTable(['Category', 'Model', 'Due', 'Blocked'], [
            ['customer_master_data', Customer::class, '1', '0'],
            ['invoices', Invoice::class, '0', '0'],
            ['orders', Order::class, '0', '1'],
            ['server_logs', ServerLog::class, '0', '0'],
        ])
        ->assertSuccessful();

    expect(DB::table('customers')->whereNull('anonymized_at')->count())->toBe(2)
        ->and(DB::table('orders')->count())->toBe(1);
});

it('dispatches RunCompleted with the report', function () {
    Event::fake([RunCompleted::class]);
    createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $this->travelTo(at('2026-01-01'));

    $this->artisan('retention:run')->assertSuccessful();

    Event::assertDispatched(RunCompleted::class, fn (RunCompleted $event) => ! $event->report->dryRun
        && $event->report->total('anonymized') === 1
        && $event->report->evaluatedAt->equalTo(at('2026-01-01')));
});

it('refuses to run while a policy is invalid', function () {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('probe', 'Probe')->rule('no_such_rule');
    config(['retention.models.additional' => [Customer::class, PolicyProbe::class]]);
    createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $this->travelTo(at('2026-01-01'));

    $this->artisan('retention:run')
        ->expectsOutputToContain('unknown retention rule [no_such_rule].')
        ->assertFailed();

    expect(DB::table('customers')->whereNull('anonymized_at')->count())->toBe(1);
});

it('rejects unknown models', function () {
    $this->artisan('retention:run', ['--model' => ['Ticket']])
        ->expectsOutputToContain('Unknown model [Ticket]')
        ->assertFailed();
});

it('skips records that the SQL selection returned but the calculator does not consider due', function () {
    createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $this->travelTo(at('2026-01-01'));

    app()->bind(DueRecords::class, NeverDueRecords::class);

    $this->artisan('retention:run', ['--model' => [Customer::class]])
        ->expectsOutputToContain('1 record(s) were selected as due but failed the per-record check')
        ->expectsTable(RUN_HEADERS, [['customer_master_data', Customer::class, '0', '0', '0', '1']])
        ->assertSuccessful();

    expect(DB::table('customers')->whereNull('anonymized_at')->count())->toBe(1);
});
